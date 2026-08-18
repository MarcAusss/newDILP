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

    /**
     * @var array<string, array{0:int|float|null,1:int|float|null}>
     */
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
        /*
        |--------------------------------------------------------------------------
        | Long-running Miro synchronization
        |--------------------------------------------------------------------------
        |
        | Large province imports can take several minutes.
        |
        */

        @set_time_limit(0);
        @ini_set('max_execution_time', '0');

        ignore_user_abort(true);

        $batch->loadMissing([
            'province',
            'rows',
        ]);

        $province = $batch->province;

        if (!$province->miro_board_id) {
            throw new RuntimeException(
                'No Miro board ID is configured for ' .
                $province->name .
                '.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Verify Board
        |--------------------------------------------------------------------------
        */

        $this->miro->getBoard(
            $province->miro_board_id
        );

        /*
        |--------------------------------------------------------------------------
        | Reset local tracking when board changes
        |--------------------------------------------------------------------------
        */

        $this->resetTrackingWhenBoardChanged(
            $province->id,
            $province->miro_board_id
        );

        /*
        |--------------------------------------------------------------------------
        | Adopt existing board content
        |--------------------------------------------------------------------------
        |
        | Keep:
        |
        | - provincial map
        | - municipality labels
        | - municipality map anchors
        | - existing compatible data boxes
        |
        */

        $this->boardAdoption->prepare(
            $province,
            $batch
        );

        /*
        |--------------------------------------------------------------------------
        | Reload province after adoption
        |--------------------------------------------------------------------------
        */

        $batch->setRelation(
            'province',
            $province->fresh()
        );

        $province = $batch->province;

        /*
        |--------------------------------------------------------------------------
        | Build desired Miro layout
        |--------------------------------------------------------------------------
        */

        $layout = $this->layout->build(
            $province,
            $batch
        );

        $activeKeys = collect(
            $layout['shapes']
        )
            ->concat(
                $layout['connectors']
            )
            ->pluck('stable_key')
            ->all();

        /*
        |--------------------------------------------------------------------------
        | Mark import as processing
        |--------------------------------------------------------------------------
        */

        $batch->update([
            'status' => 'processing',
            'error_message' => null,
        ]);

        try {
            /*
            |--------------------------------------------------------------------------
            | Load tracked Miro items once
            |--------------------------------------------------------------------------
            */

            $this->loadTracking(
                $province->id,
                $province->miro_board_id
            );

            /*
            |--------------------------------------------------------------------------
            | Synchronize
            |--------------------------------------------------------------------------
            */

            $this->syncShapes(
                $batch,
                $layout['shapes']
            );

            $this->syncConnectors(
                $batch,
                $layout['connectors']
            );

            /*
            |--------------------------------------------------------------------------
            | Replace-only behavior
            |--------------------------------------------------------------------------
            |
            | Old generated boxes are CLEARED instead of deleted.
            |
            */

            $this->clearObsoleteItems(
                $batch,
                $activeKeys
            );

            /*
            |--------------------------------------------------------------------------
            | Complete
            |--------------------------------------------------------------------------
            */

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

    /*
    |--------------------------------------------------------------------------
    | Load Tracking
    |--------------------------------------------------------------------------
    */

    private function loadTracking(
        int $provinceId,
        string $boardId
    ): void {
        $this->trackedItems =
            GeneratedMiroItem::query()
                ->where(
                    'province_id',
                    $provinceId
                )
                ->where(
                    'board_id',
                    $boardId
                )
                ->get()
                ->keyBy(
                    'stable_key'
                );

        $this->plannedPositions = [];

        foreach (
            $this->trackedItems
            as $stableKey => $item
        ) {
            $this->plannedPositions[
                $stableKey
            ] = [
                $item->x,
                $item->y,
            ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Board Change Handling
    |--------------------------------------------------------------------------
    */

    private function resetTrackingWhenBoardChanged(
        int $provinceId,
        string $boardId
    ): void {
        $hasUnknownOrDifferentBoard =
            GeneratedMiroItem::query()
                ->where(
                    'province_id',
                    $provinceId
                )
                ->where(
                    function ($query) use ($boardId) {
                        $query
                            ->whereNull(
                                'board_id'
                            )
                            ->orWhere(
                                'board_id',
                                '!=',
                                $boardId
                            );
                    }
                )
                ->exists();

        if ($hasUnknownOrDifferentBoard) {
            /*
            |--------------------------------------------------------------------------
            | Important
            |--------------------------------------------------------------------------
            |
            | Do NOT delete anything from the old Miro board.
            |
            | We only detach the local tracking rows.
            |
            */

            GeneratedMiroItem::query()
                ->where(
                    'province_id',
                    $provinceId
                )
                ->delete();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Synchronize Shapes
    |--------------------------------------------------------------------------
    */

    private function syncShapes(
        ImportBatch $batch,
        array $shapes
    ): void {
        $pendingCreates = [];

        foreach (
            $shapes
            as $shape
        ) {
            $mapping =
                $this->trackedItems->get(
                    $shape['stable_key']
                );

            /*
            |--------------------------------------------------------------------------
            | Existing municipality label / external anchor
            |--------------------------------------------------------------------------
            |
            | Municipality labels already on Miro are permanent.
            |
            */

            if (
                $mapping &&
                $mapping->item_type ===
                'external_anchor' &&
                $shape['item_type'] ===
                'anchor'
            ) {
                $this->plannedPositions[
                    $shape['stable_key']
                ] = [
                    $mapping->x,
                    $mapping->y,
                ];

                $mergedMeta = [
                    ...(
                        $mapping->meta
                        ?? []
                    ),
                    ...(
                        $shape['meta']
                        ?? []
                    ),
                ];

                $mapping->forceFill([
                    'label' =>
                        $shape['label'],

                    'meta' =>
                        $mergedMeta,

                    'last_synced_at' =>
                        now(),
                ])->save();

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Generate desired-content hash
            |--------------------------------------------------------------------------
            */

            $syncHash =
                $this->shapeSyncHash(
                    $shape
                );

            /*
            |--------------------------------------------------------------------------
            | Existing generated shape
            |--------------------------------------------------------------------------
            */

            if ($mapping) {
                /*
                |--------------------------------------------------------------------------
                | Flush waiting creates first
                |--------------------------------------------------------------------------
                */

                $this->flushPendingShapeCreates(
                    $batch,
                    $pendingCreates
                );

                $pendingCreates = [];

                /*
                |--------------------------------------------------------------------------
                | Preserve existing manual board position
                |--------------------------------------------------------------------------
                */

                $this->plannedPositions[
                    $shape['stable_key']
                ] = [
                    $mapping->x,
                    $mapping->y,
                ];

                /*
                |--------------------------------------------------------------------------
                | Skip unchanged item
                |--------------------------------------------------------------------------
                */

                if (
                    data_get(
                        $mapping->meta,
                        'sync_hash'
                    ) ===
                    $syncHash
                ) {
                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Update existing Miro shape
                |--------------------------------------------------------------------------
                */

                $this->updateExistingShape(
                    $batch,
                    $shape,
                    $mapping,
                    $syncHash
                );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | New shape
            |--------------------------------------------------------------------------
            */

            [
                $createX,
                $createY,
            ] =
                $this
                    ->resolveNewShapePositionFromTracking(
                        $shape
                    );

            $shape['x'] =
                $createX;

            $shape['y'] =
                $createY;

            $this->plannedPositions[
                $shape['stable_key']
            ] = [
                $createX,
                $createY,
            ];

            $pendingCreates[] = [
                'item' =>
                    $shape,

                'sync_hash' =>
                    $syncHash,
            ];

            /*
            |--------------------------------------------------------------------------
            | Bulk create every 20
            |--------------------------------------------------------------------------
            */

            if (
                count(
                    $pendingCreates
                ) >=
                self::BULK_CREATE_SIZE
            ) {
                $this->flushPendingShapeCreates(
                    $batch,
                    $pendingCreates
                );

                $pendingCreates = [];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Flush remaining creates
        |--------------------------------------------------------------------------
        */

        $this->flushPendingShapeCreates(
            $batch,
            $pendingCreates
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Update Existing Shape
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | Existing Miro shapes do NOT receive geometry.width / geometry.height.
    |
    | This prevents Miro 3.0215 validation errors and preserves manual resizing.
    |
    */

    private function updateExistingShape(
        ImportBatch $batch,
        array $item,
        GeneratedMiroItem $mapping,
        string $syncHash
    ): void {
        $province =
            $batch->province;

        try {
            /*
            |--------------------------------------------------------------------------
            | Only generated municipality anchors are repositioned
            |--------------------------------------------------------------------------
            |
            | Data boxes preserve their manually positioned location.
            |
            */

            $shouldUpdatePosition =
                $item['item_type']
                ===
                'anchor';

            $response =
                $this->miro->updateShape(
                    $province->miro_board_id,
                    $mapping->miro_item_id,

                    $this->shapePayload(
                        $province->miro_frame_id,
                        $item,

                        /*
                        | Update position?
                        */
                        $shouldUpdatePosition,

                        /*
                        | Parent frame?
                        */
                        false,

                        /*
                        | Geometry?
                        |
                        | FALSE on update.
                        */
                        false
                    )
                );
        } catch (RequestException $e) {
            /*
            |--------------------------------------------------------------------------
            | Existing local record but Miro item disappeared
            |--------------------------------------------------------------------------
            */

            if (
                $e->response?->status()
                !==
                404
            ) {
                throw $e;
            }

            /*
            |--------------------------------------------------------------------------
            | Remove broken LOCAL mapping only
            |--------------------------------------------------------------------------
            */

            $mapping->delete();

            $this->trackedItems->forget(
                $item['stable_key']
            );

            /*
            |--------------------------------------------------------------------------
            | Recreate missing Miro item
            |--------------------------------------------------------------------------
            */

            [
                $createX,
                $createY,
            ] =
                $this
                    ->resolveNewShapePositionFromTracking(
                        $item
                    );

            $item['x'] =
                $createX;

            $item['y'] =
                $createY;

            $this->plannedPositions[
                $item['stable_key']
            ] = [
                $createX,
                $createY,
            ];

            $this->flushPendingShapeCreates(
                $batch,
                [
                    [
                        'item' =>
                            $item,

                        'sync_hash' =>
                            $syncHash,
                    ],
                ]
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Persist mapping
        |--------------------------------------------------------------------------
        */

        $this->persistShapeMapping(
            $province->id,
            $province->miro_board_id,
            $item,
            $response,
            $mapping,
            false,
            $syncHash
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Bulk Create Shapes
    |--------------------------------------------------------------------------
    */

    private function flushPendingShapeCreates(
        ImportBatch $batch,
        array $pending
    ): void {
        if ($pending === []) {
            return;
        }

        $province =
            $batch->province;

        foreach (
            array_chunk(
                $pending,
                self::BULK_CREATE_SIZE
            )
            as $chunk
        ) {
            $payloads = [];

            foreach (
                $chunk
                as $entry
            ) {
                /*
                |--------------------------------------------------------------------------
                | New objects DO include geometry
                |--------------------------------------------------------------------------
                */

                $payloads[] = [
                    'type' =>
                        'shape',

                    ...$this->shapePayload(
                        $province->miro_frame_id,
                        $entry['item'],

                        /*
                        | Position
                        */
                        true,

                        /*
                        | Parent
                        */
                        true,

                        /*
                        | Geometry
                        */
                        true
                    ),
                ];
            }

            try {
                /*
                |--------------------------------------------------------------------------
                | Miro bulk creation
                |--------------------------------------------------------------------------
                */

                $responses =
                    $this->miro
                        ->createItemsBulk(
                            $province
                                ->miro_board_id,
                            $payloads
                        );
            } catch (
                RequestException $e
            ) {
                /*
                |--------------------------------------------------------------------------
                | Compatibility fallback
                |--------------------------------------------------------------------------
                */

                if (
                    !in_array(
                        $e->response
                                ?->status(),
                        [
                            400,
                            404,
                            413,
                            415,
                            422,
                        ],
                        true
                    )
                ) {
                    throw $e;
                }

                $responses = [];

                foreach (
                    $chunk
                    as $entry
                ) {
                    $responses[] =
                        $this->miro
                            ->createShape(
                                $province
                                    ->miro_board_id,

                                $this->shapePayload(
                                    $province
                                        ->miro_frame_id,

                                    $entry[
                                        'item'
                                    ],

                                    true,
                                    true,
                                    true
                                )
                            );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Validate result count
            |--------------------------------------------------------------------------
            */

            if (
                count($responses)
                !==
                count($chunk)
            ) {
                throw new RuntimeException(
                    'Miro bulk creation returned ' .
                    count($responses) .
                    ' items for ' .
                    count($chunk) .
                    ' requested shapes.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Save local mappings
            |--------------------------------------------------------------------------
            */

            foreach (
                $chunk
                as $index => $entry
            ) {
                $this->persistShapeMapping(
                    $province->id,
                    $province->miro_board_id,

                    $entry['item'],

                    $responses[
                        $index
                    ] ?? [],

                    null,
                    true,

                    $entry[
                        'sync_hash'
                    ]
                );
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Persist Shape Tracking
    |--------------------------------------------------------------------------
    */

    private function persistShapeMapping(
        int $provinceId,
        string $boardId,
        array $item,
        array $response,
        ?GeneratedMiroItem $mapping,
        bool $created,
        string $syncHash
    ): void {
        $miroItemId =
            data_get(
                $response,
                'id'
            )
            ?:
            $mapping
                    ?->miro_item_id;

        if (!$miroItemId) {
            throw new RuntimeException(
                'Miro did not return an item ID for ' .
                $item['label'] .
                '.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Position returned by Miro
        |--------------------------------------------------------------------------
        */

        $responseX =
            data_get(
                $response,
                'position.x'
            );

        $responseY =
            data_get(
                $response,
                'position.y'
            );

        /*
        |--------------------------------------------------------------------------
        | Preserve position for updates
        |--------------------------------------------------------------------------
        */

        $x =
            $responseX !== null
            ?
            (int) round(
                $responseX
            )
            :
            (
                $created
                ?
                (int) round(
                    $item['x']
                )
                :
                $mapping?->x
            );

        $y =
            $responseY !== null
            ?
            (int) round(
                $responseY
            )
            :
            (
                $created
                ?
                (int) round(
                    $item['y']
                )
                :
                $mapping?->y
            );

        /*
        |--------------------------------------------------------------------------
        | Metadata
        |--------------------------------------------------------------------------
        */

        $meta = [
            ...(
                $item['meta']
                ?? []
            ),

            'sync_hash' =>
                $syncHash,

            'cleared' =>
                false,
        ];

        /*
        |--------------------------------------------------------------------------
        | Save
        |--------------------------------------------------------------------------
        */

        $record =
            GeneratedMiroItem::updateOrCreate(
                [
                    'province_id' =>
                        $provinceId,

                    'stable_key' =>
                        $item[
                            'stable_key'
                        ],
                ],
                [
                    'board_id' =>
                        $boardId,

                    'item_type' =>
                        $item[
                            'item_type'
                        ],

                    'miro_item_id' =>
                        $miroItemId,

                    'label' =>
                        $item[
                            'label'
                        ],

                    'x' =>
                        $x,

                    'y' =>
                        $y,

                    'meta' =>
                        $meta,

                    'last_synced_at' =>
                        now(),
                ]
            );

        /*
        |--------------------------------------------------------------------------
        | Update runtime cache
        |--------------------------------------------------------------------------
        */

        $this->trackedItems->put(
            $item['stable_key'],
            $record
        );

        $this->plannedPositions[
            $item['stable_key']
        ] = [
            $record->x,
            $record->y,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Synchronize Connectors
    |--------------------------------------------------------------------------
    */

    private function syncConnectors(
        ImportBatch $batch,
        array $connectors
    ): void {
        $province =
            $batch->province;

        foreach (
            $connectors
            as $item
        ) {
            $start =
                $this->trackedItems->get(
                    $item['start_key']
                );

            $end =
                $this->trackedItems->get(
                    $item['end_key']
                );

            if (
                !$start ||
                !$end
            ) {
                throw new RuntimeException(
                    'Cannot create connector ' .
                    $item['label'] .
                    ' because one of its Miro items is missing.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Existing connector
            |--------------------------------------------------------------------------
            */

            $mapping =
                $this->trackedItems->get(
                    $item['stable_key']
                );

            /*
            |--------------------------------------------------------------------------
            | Connector payload
            |--------------------------------------------------------------------------
            */

            $payload =
                $this->connectorPayload(
                    $start->miro_item_id,
                    $end->miro_item_id
                );

            $syncHash =
                $this->connectorSyncHash(
                    $payload
                );

            /*
            |--------------------------------------------------------------------------
            | Skip unchanged connector
            |--------------------------------------------------------------------------
            */

            if (
                $mapping &&
                data_get(
                    $mapping->meta,
                    'sync_hash'
                ) ===
                $syncHash
            ) {
                continue;
            }

            $response = null;

            /*
            |--------------------------------------------------------------------------
            | Update connector
            |--------------------------------------------------------------------------
            */

            if ($mapping) {
                try {
                    $response =
                        $this->miro
                            ->updateConnector(
                                $province
                                    ->miro_board_id,

                                $mapping
                                    ->miro_item_id,

                                $payload
                            );
                } catch (
                    RequestException $e
                ) {
                    if (
                        $e->response
                                ?->status()
                        !==
                        404
                    ) {
                        throw $e;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Miro connector missing
                    |--------------------------------------------------------------------------
                    */

                    $mapping->delete();

                    $this->trackedItems
                        ->forget(
                            $item[
                                'stable_key'
                            ]
                        );

                    $mapping = null;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Create connector if missing
            |--------------------------------------------------------------------------
            */

            if (!$mapping) {
                $response =
                    $this->miro
                        ->createConnector(
                            $province
                                ->miro_board_id,

                            $payload
                        );
            }

            /*
            |--------------------------------------------------------------------------
            | Resolve Miro ID
            |--------------------------------------------------------------------------
            */

            $miroItemId =
                data_get(
                    $response,
                    'id'
                )
                ?:
                $mapping
                        ?->miro_item_id;

            if (!$miroItemId) {
                throw new RuntimeException(
                    'Miro did not return a connector ID for ' .
                    $item['label'] .
                    '.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Track connector
            |--------------------------------------------------------------------------
            */

            $record =
                GeneratedMiroItem::updateOrCreate(
                    [
                        'province_id' =>
                            $province->id,

                        'stable_key' =>
                            $item[
                                'stable_key'
                            ],
                    ],
                    [
                        'board_id' =>
                            $province
                                ->miro_board_id,

                        'item_type' =>
                            'connector',

                        'miro_item_id' =>
                            $miroItemId,

                        'label' =>
                            $item[
                                'label'
                            ],

                        'x' =>
                            null,

                        'y' =>
                            null,

                        'meta' => [
                            ...(
                                $item[
                                    'meta'
                                ] ?? []
                            ),

                            'start_key' =>
                                $item[
                                    'start_key'
                                ],

                            'end_key' =>
                                $item[
                                    'end_key'
                                ],

                            'sync_hash' =>
                                $syncHash,
                        ],

                        'last_synced_at' =>
                            now(),
                    ]
                );

            $this->trackedItems->put(
                $item['stable_key'],
                $record
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve Position For New Shape
    |--------------------------------------------------------------------------
    */

    private function resolveNewShapePositionFromTracking(
        array $item
    ): array {
        $previousKey =
            data_get(
                $item,
                'meta.previous_key'
            );

        if (!$previousKey) {
            return [
                (int) round(
                    $item['x']
                ),

                (int) round(
                    $item['y']
                ),
            ];
        }

        $position =
            $this->plannedPositions[
                $previousKey
            ] ?? null;

        if (
            !$position ||
            $position[0] === null ||
            $position[1] === null
        ) {
            return [
                (int) round(
                    $item['x']
                ),

                (int) round(
                    $item['y']
                ),
            ];
        }

        return [
            (int) round(
                $position[0] +
                (int) data_get(
                    $item,
                    'meta.offset_x',
                    0
                )
            ),

            (int) round(
                $position[1] +
                (int) data_get(
                    $item,
                    'meta.offset_y',
                    0
                )
            ),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Clear Obsolete Items
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | NOTHING IS DELETED FROM MIRO.
    |
    */

    private function clearObsoleteItems(
        ImportBatch $batch,
        array $activeKeys
    ): void {
        $province =
            $batch->province;

        $activeSet =
            array_fill_keys(
                $activeKeys,
                true
            );

        $obsolete =
            $this->trackedItems
                ->reject(
                    fn(
                    GeneratedMiroItem $item,
                    string $key
                ) =>
                    isset(
                    $activeSet[$key]
                )
                )
                ->values();

        foreach (
            $obsolete
            as $oldItem
        ) {
            /*
            |--------------------------------------------------------------------------
            | Permanent items stay untouched
            |--------------------------------------------------------------------------
            */

            if (
                in_array(
                    $oldItem->item_type,
                    [
                        'external_anchor',
                        'anchor',
                        'connector',
                    ],
                    true
                )
            ) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Already cleared
            |--------------------------------------------------------------------------
            */

            if (
                (bool) data_get(
                    $oldItem->meta,
                    'cleared',
                    false
                )
            ) {
                continue;
            }

            try {
                /*
                |--------------------------------------------------------------------------
                | Clear visible content only
                |--------------------------------------------------------------------------
                |
                | Do not modify:
                |
                | - geometry
                | - size
                | - position
                | - color
                |
                */

                $this->miro->updateShape(
                    $province
                        ->miro_board_id,

                    $oldItem
                        ->miro_item_id,

                    [
                        'data' => [
                            'content' =>
                                '<p>&nbsp;</p>',
                        ],
                    ]
                );
            } catch (
                RequestException $e
            ) {
                if (
                    $e->response
                            ?->status()
                    !==
                    404
                ) {
                    throw $e;
                }

                /*
                |--------------------------------------------------------------------------
                | User manually deleted the item from Miro
                |--------------------------------------------------------------------------
                */

                $meta = [
                    ...(
                        $oldItem->meta
                        ?? []
                    ),

                    'cleared' =>
                        true,

                    'missing_on_miro' =>
                        true,

                    'cleared_at' =>
                        now()
                            ->toIso8601String(),

                    'sync_hash' =>
                        null,
                ];

                $oldItem
                    ->forceFill([
                        'meta' =>
                            $meta,

                        'last_synced_at' =>
                            now(),
                    ])
                    ->save();

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Mark cleared
            |--------------------------------------------------------------------------
            */

            $meta = [
                ...(
                    $oldItem->meta
                    ?? []
                ),

                'cleared' =>
                    true,

                'missing_on_miro' =>
                    false,

                'cleared_at' =>
                    now()
                        ->toIso8601String(),

                /*
                |--------------------------------------------------------------------------
                | Force replacement if item becomes active again
                |--------------------------------------------------------------------------
                */

                'sync_hash' =>
                    null,
            ];

            $oldItem
                ->forceFill([
                    'meta' =>
                        $meta,

                    'last_synced_at' =>
                        now(),
                ])
                ->save();

            $this->trackedItems->put(
                $oldItem->stable_key,
                $oldItem
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Shape Hash
    |--------------------------------------------------------------------------
    |
    | Geometry is deliberately excluded for existing objects.
    |
    | That means manually resizing boxes in Miro does not cause Laravel to
    | attempt to reset their width/height on every synchronization.
    |
    */

    private function shapeSyncHash(
        array $item
    ): string {
        $canonical =
            $this->shapePayload(
                null,
                $item,

                /*
                | Position
                */
                false,

                /*
                | Parent
                */
                false,

                /*
                | Geometry
                */
                false
            );

        /*
        |--------------------------------------------------------------------------
        | Anchors include position
        |--------------------------------------------------------------------------
        */

        if (
            $item['item_type']
            ===
            'anchor'
        ) {
            $canonical[
                'position'
            ] = [
                'x' =>
                    (int) round(
                        $item['x']
                    ),

                'y' =>
                    (int) round(
                        $item['y']
                    ),

                'origin' =>
                    'center',
            ];
        }

        return hash(
            'sha256',
            json_encode(
                $canonical,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Connector Hash
    |--------------------------------------------------------------------------
    */

    private function connectorSyncHash(
        array $payload
    ): string {
        return hash(
            'sha256',
            json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Shape Payload
    |--------------------------------------------------------------------------
    |
    | $withGeometry controls whether width/height are sent.
    |
    | CREATE:
    |     $withGeometry = true
    |
    | UPDATE:
    |     $withGeometry = false
    |
    */

    private function shapePayload(
        ?string $frameId,
        array $item,
        bool $withPosition,
        bool $withParent = false,
        bool $withGeometry = true
    ): array {
        $colors =
            config(
                'imports.miro'
            );

        /*
        |--------------------------------------------------------------------------
        | Styles
        |--------------------------------------------------------------------------
        */

        $styles = [
            /*
            |--------------------------------------------------------------------------
            | Municipality anchor
            |--------------------------------------------------------------------------
            */

            'anchor' => [
                'fillColor' =>
                    $colors[
                        'anchor_fill'
                    ],

                'fillOpacity' =>
                    1,

                'borderColor' =>
                    $colors[
                        'anchor_border'
                    ],

                'borderOpacity' =>
                    1,

                'borderWidth' =>
                    1,

                'color' =>
                    $colors[
                        'anchor_fill'
                    ],

                'fontSize' =>
                    36,

                'textAlign' =>
                    'center',

                'textAlignVertical' =>
                    'middle',
            ],

            /*
            |--------------------------------------------------------------------------
            | Regular municipality / barangay box
            |--------------------------------------------------------------------------
            */

            'panel' => [
                'fillColor' =>
                    $colors[
                        'panel_fill'
                    ],

                'fillOpacity' =>
                    1,

                'borderColor' =>
                    $colors[
                        'panel_border'
                    ],

                'borderOpacity' =>
                    1,

                'borderWidth' =>
                    2,

                'borderStyle' =>
                    'normal',

                'color' =>
                    $colors[
                        'panel_text'
                    ],

                'fontFamily' =>
                    'arial',

                'fontSize' =>
                    36,

                'textAlign' =>
                    'center',

                'textAlignVertical' =>
                    'top',
            ],

            /*
            |--------------------------------------------------------------------------
            | Group Project yellow box
            |--------------------------------------------------------------------------
            */

            'group_panel' => [
                'fillColor' =>
                    $colors[
                        'group_panel_fill'
                    ],

                'fillOpacity' =>
                    1,

                'borderColor' =>
                    $colors[
                        'group_panel_border'
                    ],

                'borderOpacity' =>
                    1,

                'borderWidth' =>
                    2,

                'borderStyle' =>
                    'normal',

                'color' =>
                    $colors[
                        'group_panel_text'
                    ],

                'fontFamily' =>
                    'arial',

                'fontSize' =>
                    36,

                'textAlign' =>
                    'center',

                'textAlignVertical' =>
                    'middle',
            ],

            /*
            |--------------------------------------------------------------------------
            | Summary Boxes
            |--------------------------------------------------------------------------
            */

            'summary_top' =>
                $this->summaryStyle(
                    $colors[
                        'summary_top_fill'
                    ],
                    18
                ),

            'summary_undertakings' =>
                $this->summaryStyle(
                    $colors[
                        'summary_undertakings_fill'
                    ],
                    14,
                    'top'
                ),

            'summary_highest' =>
                $this->summaryStyle(
                    $colors[
                        'summary_highest_fill'
                    ],
                    18
                ),

            'summary_least' =>
                $this->summaryStyle(
                    $colors[
                        'summary_least_fill'
                    ],
                    18
                ),

            'summary_beneficiaries' =>
                $this->summaryStyle(
                    $colors[
                        'summary_beneficiaries_fill'
                    ],
                    18
                ),

            'summary_group' =>
                $this->summaryStyle(
                    $colors[
                        'summary_group_fill'
                    ],
                    18
                ),

            'summary_total' =>
                $this->summaryStyle(
                    $colors[
                        'summary_total_fill'
                    ],
                    18
                ),
        ];

        /*
        |--------------------------------------------------------------------------
        | Validate Style
        |--------------------------------------------------------------------------
        */

        if (
            !isset(
            $styles[
                $item['style']
            ]
        )
        ) {
            throw new RuntimeException(
                'Unsupported Miro shape style: ' .
                $item['style']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Base Payload
        |--------------------------------------------------------------------------
        */

        $payload = [
            'data' => [
                'content' =>
                    $item['content'],

                'shape' =>
                    $item['shape'],
            ],

            'style' =>
                $styles[
                    $item['style']
                ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Geometry
        |--------------------------------------------------------------------------
        |
        | New shapes:
        |     yes
        |
        | Existing shapes:
        |     no
        |
        */

        if ($withGeometry) {
            $payload[
                'geometry'
            ] = [
                'width' =>
                    $item[
                        'width'
                    ],

                'height' =>
                    $item[
                        'height'
                    ],
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Position
        |--------------------------------------------------------------------------
        */

        if ($withPosition) {
            $payload[
                'position'
            ] = [
                'x' =>
                    $item['x'],

                'y' =>
                    $item['y'],

                'origin' =>
                    'center',
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Parent Frame
        |--------------------------------------------------------------------------
        */

        if (
            $withParent &&
            $frameId
        ) {
            $payload[
                'parent'
            ] = [
                'id' =>
                    $frameId,
            ];
        }

        return $payload;
    }

    /*
    |--------------------------------------------------------------------------
    | Summary Style Helper
    |--------------------------------------------------------------------------
    */

    private function summaryStyle(
        string $fillColor,
        int $fontSize,
        string $vertical = 'middle'
    ): array {
        $colors =
            config(
                'imports.miro'
            );

        return [
            'fillColor' =>
                $fillColor,

            'fillOpacity' =>
                1,

            'borderColor' =>
                $colors[
                    'summary_border'
                ],

            'borderOpacity' =>
                1,

            'borderWidth' =>
                1,

            'borderStyle' =>
                'normal',

            'color' =>
                $colors[
                    'summary_text'
                ],

            'fontFamily' =>
                'arial',

            'fontSize' =>
                $fontSize,

            'textAlign' =>
                'center',

            'textAlignVertical' =>
                $vertical,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Connector Payload
    |--------------------------------------------------------------------------
    */

    private function connectorPayload(
        string $startItemId,
        string $endItemId
    ): array {
        return [
            'startItem' => [
                'id' =>
                    $startItemId,

                'snapTo' =>
                    'auto',
            ],

            'endItem' => [
                'id' =>
                    $endItemId,

                'snapTo' =>
                    'auto',
            ],

            'shape' =>
                'elbowed',

            'style' => [
                'strokeColor' =>
                    config(
                        'imports.miro.connector_color'
                    ),

                'strokeWidth' =>
                    2,

                'strokeStyle' =>
                    'normal',

                'startStrokeCap' =>
                    'none',

                'endStrokeCap' =>
                    'rounded_stealth',
            ],
        ];
    }
}