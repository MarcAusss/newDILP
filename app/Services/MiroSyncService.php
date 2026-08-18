<?php

namespace App\Services;

use App\Models\GeneratedMiroItem;
use App\Models\ImportBatch;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

class MiroSyncService
{
    private const BULK_CREATE_SIZE = 20;

    private Collection $trackedItems;

    /** @var array<string, array{0:int|float|null,1:int|float|null}> */
    private array $plannedPositions = [];

    public function __construct(
        private readonly MiroService $miro,
        private readonly MiroLayoutService $layout,
        private readonly MiroBoardAdoptionService $boardAdoption,
    ) {
        $this->trackedItems = collect();
    }

    public function sync(ImportBatch $batch): void
    {
        // Miro writes can legitimately take several minutes on large provincial
        // imports. Do not let PHP terminate an otherwise healthy synchronization.
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        ignore_user_abort(true);

        $batch->loadMissing(['province', 'rows']);
        $province = $batch->province;

        if (!$province->miro_board_id) {
            throw new RuntimeException('No Miro board ID is configured for '.$province->name.'.');
        }

        $this->miro->getBoard($province->miro_board_id);
        $this->resetTrackingWhenBoardChanged($province->id, $province->miro_board_id);

        // Preserve the base provincial map and detect permanent municipality anchors.
        // Previous generated batches are never candidates for replacement.
        $this->boardAdoption->prepare($province, $batch);

        $batch->setRelation('province', $province->fresh());
        $province = $batch->province;

        $layout = $this->layout->build($province, $batch);

        $batch->update([
            'status' => 'processing',
            'error_message' => null,
        ]);

        try {
            $this->loadTracking($province->id, $province->miro_board_id);
            $this->syncShapes($batch, $layout['shapes']);
            $this->syncConnectors($batch, $layout['connectors']);

            // Deliberately do not clear, replace, or delete items from older
            // ImportBatch records. Every new import owns a fresh Miro batch.

            $batch->update([
                'status' => 'completed',
                'completed_at' => now(),
                'error_message' => null,
            ]);
        } catch (Throwable $e) {
            $batch->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function loadTracking(int $provinceId, string $boardId): void
    {
        $this->trackedItems = GeneratedMiroItem::query()
            ->where('province_id', $provinceId)
            ->where('board_id', $boardId)
            ->get()
            ->keyBy('stable_key');

        $this->plannedPositions = [];

        foreach ($this->trackedItems as $stableKey => $item) {
            $this->plannedPositions[$stableKey] = [$item->x, $item->y];
        }
    }

    private function resetTrackingWhenBoardChanged(int $provinceId, string $boardId): void
    {
        $hasUnknownOrDifferentBoard = GeneratedMiroItem::query()
            ->where('province_id', $provinceId)
            ->where(function ($query) use ($boardId) {
                $query->whereNull('board_id')->orWhere('board_id', '!=', $boardId);
            })
            ->exists();

        if ($hasUnknownOrDifferentBoard) {
            // Never delete content from the old Miro board here. Only detach the
            // local tracking records so the new board can be synchronized safely.
            GeneratedMiroItem::query()->where('province_id', $provinceId)->delete();
        }
    }

    private function syncShapes(ImportBatch $batch, array $shapes): void
    {
        $pendingCreates = [];

        foreach ($shapes as $shape) {
            $mapping = $this->trackedItems->get($shape['stable_key']);

            // A discovered municipality label is permanent board content. Trust
            // the saved item ID/coordinates instead of issuing a GET for every
            // municipality on every import.
            if (
                $mapping &&
                $mapping->item_type === 'external_anchor' &&
                $shape['item_type'] === 'anchor'
            ) {
                $this->plannedPositions[$shape['stable_key']] = [$mapping->x, $mapping->y];

                $mergedMeta = [
                    ...($mapping->meta ?? []),
                    ...($shape['meta'] ?? []),
                ];

                $mapping->forceFill([
                    'label' => $shape['label'],
                    'meta' => $mergedMeta,
                    'last_synced_at' => now(),
                ])->save();

                continue;
            }

            $syncHash = $this->shapeSyncHash($shape);

            if ($mapping) {
                $this->flushPendingShapeCreates($batch, $pendingCreates);
                $pendingCreates = [];

                $this->plannedPositions[$shape['stable_key']] = [$mapping->x, $mapping->y];

                // From the first optimized sync onward, unchanged Miro shapes are
                // skipped entirely. This makes re-imports dramatically faster.
                if (data_get($mapping->meta, 'sync_hash') === $syncHash) {
                    continue;
                }

                $this->updateExistingShape($batch, $shape, $mapping, $syncHash);
                continue;
            }

            [$createX, $createY] = $this->resolveNewShapePositionFromTracking($shape);
            $shape['x'] = $createX;
            $shape['y'] = $createY;
            $this->plannedPositions[$shape['stable_key']] = [$createX, $createY];

            $pendingCreates[] = [
                'item' => $shape,
                'sync_hash' => $syncHash,
            ];

            if (count($pendingCreates) >= self::BULK_CREATE_SIZE) {
                $this->flushPendingShapeCreates($batch, $pendingCreates);
                $pendingCreates = [];
            }
        }

        $this->flushPendingShapeCreates($batch, $pendingCreates);
    }

    private function updateExistingShape(
        ImportBatch $batch,
        array $item,
        GeneratedMiroItem $mapping,
        string $syncHash,
    ): void {
        $province = $batch->province;

        try {
            $shouldUpdatePosition = $item['item_type'] === 'anchor';

            $response = $this->miro->updateShape(
                $province->miro_board_id,
                $mapping->miro_item_id,
                $this->shapePayload(
                    $province->miro_frame_id,
                    $item,
                    $shouldUpdatePosition,
                    false,
                    false, // preserve geometry when resuming the same batch
                ),
            );
        } catch (RequestException $e) {
            if ($e->response?->status() !== 404) {
                throw $e;
            }

            $mapping->delete();
            $this->trackedItems->forget($item['stable_key']);

            [$createX, $createY] = $this->resolveNewShapePositionFromTracking($item);
            $item['x'] = $createX;
            $item['y'] = $createY;
            $this->plannedPositions[$item['stable_key']] = [$createX, $createY];

            $this->flushPendingShapeCreates($batch, [[
                'item' => $item,
                'sync_hash' => $syncHash,
            ]]);

            return;
        }

        $this->persistShapeMapping(
            $province->id,
            $province->miro_board_id,
            $item,
            $response,
            $mapping,
            false,
            $syncHash,
        );
    }

    private function flushPendingShapeCreates(ImportBatch $batch, array $pending): void
    {
        if ($pending === []) {
            return;
        }

        $province = $batch->province;

        foreach (array_chunk($pending, self::BULK_CREATE_SIZE) as $chunk) {
            $payloads = [];

            foreach ($chunk as $entry) {
                $payloads[] = [
                    'type' => 'shape',
                    ...$this->shapePayload(
                        $province->miro_frame_id,
                        $entry['item'],
                        true,
                        true,
                    ),
                ];
            }

            try {
                $responses = $this->miro->createItemsBulk(
                    $province->miro_board_id,
                    $payloads,
                );
            } catch (RequestException $e) {
                // Conservative compatibility fallback: if a workspace rejects
                // the bulk endpoint/payload, continue with normal shape creates
                // instead of aborting the whole provincial import.
                if (!in_array($e->response?->status(), [400, 404, 413, 415, 422], true)) {
                    throw $e;
                }

                $responses = [];
                foreach ($chunk as $entry) {
                    $responses[] = $this->miro->createShape(
                        $province->miro_board_id,
                        $this->shapePayload(
                            $province->miro_frame_id,
                            $entry['item'],
                            true,
                            true,
                        ),
                    );
                }
            }

            if (count($responses) !== count($chunk)) {
                throw new RuntimeException(
                    'Miro bulk creation returned '.count($responses).
                    ' items for '.count($chunk).' requested shapes.'
                );
            }

            foreach ($chunk as $index => $entry) {
                $this->persistShapeMapping(
                    $province->id,
                    $province->miro_board_id,
                    $entry['item'],
                    $responses[$index] ?? [],
                    null,
                    true,
                    $entry['sync_hash'],
                );
            }
        }
    }

    private function persistShapeMapping(
        int $provinceId,
        string $boardId,
        array $item,
        array $response,
        ?GeneratedMiroItem $mapping,
        bool $created,
        string $syncHash,
    ): void {
        $miroItemId = data_get($response, 'id') ?: $mapping?->miro_item_id;

        if (!$miroItemId) {
            throw new RuntimeException('Miro did not return an item ID for '.$item['label'].'.');
        }

        $responseX = data_get($response, 'position.x');
        $responseY = data_get($response, 'position.y');

        $x = $responseX !== null
            ? (int) round($responseX)
            : ($created ? (int) round($item['x']) : $mapping?->x);

        $y = $responseY !== null
            ? (int) round($responseY)
            : ($created ? (int) round($item['y']) : $mapping?->y);

        $meta = [
            ...($item['meta'] ?? []),
            'sync_hash' => $syncHash,
        ];

        $record = GeneratedMiroItem::updateOrCreate(
            [
                'province_id' => $provinceId,
                'stable_key' => $item['stable_key'],
            ],
            [
                'board_id' => $boardId,
                'item_type' => $item['item_type'],
                'miro_item_id' => $miroItemId,
                'label' => $item['label'],
                'x' => $x,
                'y' => $y,
                'meta' => $meta,
                'last_synced_at' => now(),
            ],
        );

        $this->trackedItems->put($item['stable_key'], $record);
        $this->plannedPositions[$item['stable_key']] = [$record->x, $record->y];
    }

    private function syncConnectors(ImportBatch $batch, array $connectors): void
    {
        $province = $batch->province;

        foreach ($connectors as $item) {
            $start = $this->trackedItems->get($item['start_key']);
            $end = $this->trackedItems->get($item['end_key']);

            if (!$start || !$end) {
                throw new RuntimeException(
                    'Cannot create connector '.$item['label'].' because one of its Miro items is missing.'
                );
            }

            $mapping = $this->trackedItems->get($item['stable_key']);
            $payload = $this->connectorPayload($start->miro_item_id, $end->miro_item_id);
            $syncHash = $this->connectorSyncHash($payload);

            if ($mapping && data_get($mapping->meta, 'sync_hash') === $syncHash) {
                continue;
            }

            $response = null;

            if ($mapping) {
                try {
                    $response = $this->miro->updateConnector(
                        $province->miro_board_id,
                        $mapping->miro_item_id,
                        $payload,
                    );
                } catch (RequestException $e) {
                    if ($e->response?->status() !== 404) {
                        throw $e;
                    }

                    $mapping->delete();
                    $this->trackedItems->forget($item['stable_key']);
                    $mapping = null;
                }
            }

            if (!$mapping) {
                $response = $this->miro->createConnector(
                    $province->miro_board_id,
                    $payload,
                );
            }

            $miroItemId = data_get($response, 'id') ?: $mapping?->miro_item_id;

            if (!$miroItemId) {
                throw new RuntimeException('Miro did not return a connector ID for '.$item['label'].'.');
            }

            $record = GeneratedMiroItem::updateOrCreate(
                [
                    'province_id' => $province->id,
                    'stable_key' => $item['stable_key'],
                ],
                [
                    'board_id' => $province->miro_board_id,
                    'item_type' => 'connector',
                    'miro_item_id' => $miroItemId,
                    'label' => $item['label'],
                    'x' => null,
                    'y' => null,
                    'meta' => [
                        ...($item['meta'] ?? []),
                        'start_key' => $item['start_key'],
                        'end_key' => $item['end_key'],
                        'sync_hash' => $syncHash,
                    ],
                    'last_synced_at' => now(),
                ],
            );

            $this->trackedItems->put($item['stable_key'], $record);
        }
    }

    private function resolveNewShapePositionFromTracking(array $item): array
    {
        $previousKey = data_get($item, 'meta.previous_key');

        if (!$previousKey) {
            return [(int) round($item['x']), (int) round($item['y'])];
        }

        $position = $this->plannedPositions[$previousKey] ?? null;

        if (!$position || $position[0] === null || $position[1] === null) {
            return [(int) round($item['x']), (int) round($item['y'])];
        }

        return [
            (int) round($position[0] + (int) data_get($item, 'meta.offset_x', 0)),
            (int) round($position[1] + (int) data_get($item, 'meta.offset_y', 0)),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Append-only Miro batch policy
    |--------------------------------------------------------------------------
    |
    | There is intentionally no obsolete-item cleanup method. Older generated
    | boxes/connectors remain on the board permanently unless the user removes
    | them manually in Miro.
    |
    */

    private function shapeSyncHash(array $item): string
    {
        $canonical = $this->shapePayload(null, $item, false, false, false);

        // Generated fallback anchors must also move when their configured map
        // coordinates change. Data/summary boxes intentionally preserve manual
        // board placement on update, so their X/Y values are not part of the hash.
        if ($item['item_type'] === 'anchor') {
            $canonical['position'] = [
                'x' => (int) round($item['x']),
                'y' => (int) round($item['y']),
                'origin' => 'center',
            ];
        }

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function connectorSyncHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function shapePayload(
        ?string $frameId,
        array $item,
        bool $withPosition,
        bool $withParent = false,
        bool $withGeometry = true,
    ): array {
        $colors = config('imports.miro');

        $styles = [
            'anchor' => [
                'fillColor' => $colors['anchor_fill'],
                'fillOpacity' => 1,
                'borderColor' => $colors['anchor_border'],
                'borderOpacity' => 1,
                'borderWidth' => 1,
                'color' => $colors['anchor_fill'],
                'fontSize' => 10,
                'textAlign' => 'center',
                'textAlignVertical' => 'middle',
            ],
            'panel' => [
                'fillColor' => $colors['panel_fill'],
                'fillOpacity' => 1,
                'borderColor' => $colors['panel_border'],
                'borderOpacity' => 1,
                'borderWidth' => 2,
                'borderStyle' => 'normal',
                'color' => $colors['panel_text'],
                'fontFamily' => 'arial',
                'fontSize' => 16,
                'textAlign' => 'center',
                'textAlignVertical' => 'top',
            ],
            'group_panel' => [
                'fillColor' => $colors['group_panel_fill'],
                'fillOpacity' => 1,
                'borderColor' => $colors['group_panel_border'],
                'borderOpacity' => 1,
                'borderWidth' => 2,
                'borderStyle' => 'normal',
                'color' => $colors['group_panel_text'],
                'fontFamily' => 'arial',
                'fontSize' => 18,
                'textAlign' => 'center',
                'textAlignVertical' => 'middle',
            ],
            'summary_top' => $this->summaryStyle($colors['summary_top_fill'], 18),
            'summary_undertakings' => $this->summaryStyle($colors['summary_undertakings_fill'], 14, 'top'),
            'summary_highest' => $this->summaryStyle($colors['summary_highest_fill'], 18),
            'summary_least' => $this->summaryStyle($colors['summary_least_fill'], 18),
            'summary_beneficiaries' => $this->summaryStyle($colors['summary_beneficiaries_fill'], 18),
            'summary_group' => $this->summaryStyle($colors['summary_group_fill'], 18),
            'summary_total' => $this->summaryStyle($colors['summary_total_fill'], 18),
        ];

        if (!isset($styles[$item['style']])) {
            throw new RuntimeException('Unsupported Miro shape style: '.$item['style']);
        }

        $payload = [
            'data' => [
                'content' => $item['content'],
                'shape' => $item['shape'],
            ],
            'style' => $styles[$item['style']],
        ];

        if ($withGeometry) {
            $payload['geometry'] = [
                'width' => $item['width'],
                'height' => $item['height'],
            ];
        }

        if ($withPosition) {
            $payload['position'] = [
                'x' => $item['x'],
                'y' => $item['y'],
                'origin' => 'center',
            ];
        }

        if ($withParent && $frameId) {
            $payload['parent'] = ['id' => $frameId];
        }

        return $payload;
    }

    private function summaryStyle(string $fillColor, int $fontSize, string $vertical = 'middle'): array
    {
        $colors = config('imports.miro');

        return [
            'fillColor' => $fillColor,
            'fillOpacity' => 1,
            'borderColor' => $colors['summary_border'],
            'borderOpacity' => 1,
            'borderWidth' => 1,
            'borderStyle' => 'normal',
            'color' => $colors['summary_text'],
            'fontFamily' => 'arial',
            'fontSize' => $fontSize,
            'textAlign' => 'center',
            'textAlignVertical' => $vertical,
        ];
    }

    private function connectorPayload(string $startItemId, string $endItemId): array
    {
        return [
            'startItem' => ['id' => $startItemId, 'snapTo' => 'auto'],
            'endItem' => ['id' => $endItemId, 'snapTo' => 'auto'],
            'shape' => 'elbowed',
            'style' => [
                'strokeColor' => config('imports.miro.connector_color'),
                'strokeWidth' => 2,
                'strokeStyle' => 'normal',
                'startStrokeCap' => 'none',
                'endStrokeCap' => 'rounded_stealth',
            ],
        ];
    }
}
