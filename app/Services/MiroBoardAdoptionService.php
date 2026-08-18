<?php

namespace App\Services;

use App\Models\GeneratedMiroItem;
use App\Models\ImportBatch;
use App\Models\MunicipalityMapping;
use App\Models\Province;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MiroBoardAdoptionService
{
    public function __construct(private readonly MiroService $miro)
    {
    }

    /**
     * Adopt permanent municipality labels and compatible legacy panels for the
     * CURRENT province only.
     *
     * This service is intentionally non-destructive: importing one province must
     * never delete, clear, re-adopt, or otherwise modify items already tracked to
     * another province on the same Miro board.
     */
    public function prepare(Province $province, ImportBatch $batch): void
    {
        if (!$province->miro_board_id) {
            return;
        }

        $batch->loadMissing('rows');

        // Group Project rows (#EA9999) are intentionally excluded from normal
        // municipality mapping and legacy green-box adoption.
        $municipalityKeys = $batch->rows
            ->where('is_group_project', false)
            ->pluck('municipality_key')
            ->unique()
            ->values();
        $mappings = MunicipalityMapping::query()
            ->where('province_id', $province->id)
            ->whereIn('municipality_key', $municipalityKeys)
            ->orderBy('sort_order')
            ->get();

        if ($mappings->isEmpty()) {
            return;
        }

        /*
         |--------------------------------------------------------------------------
         | Protect items owned by other provinces
         |--------------------------------------------------------------------------
         |
         | A single Miro board may contain Albay, Camarines Norte, Camarines Sur,
         | Catanduanes, Masbate, and Sorsogon at the same time. Items already
         | tracked to another province are never candidates for adoption here.
         |
         */
        $foreignItemIds = GeneratedMiroItem::query()
            ->where('board_id', $province->miro_board_id)
            ->where('province_id', '!=', $province->id)
            ->pluck('miro_item_id')
            ->filter()
            ->mapWithKeys(fn ($id) => [(string) $id => true]);

        $shapeItems = collect($this->miro->getBoardItems($province->miro_board_id, 'shape'));
        $textItems = collect($this->miro->getBoardItems($province->miro_board_id, 'text'));

        $availableShapeItems = $shapeItems
            ->reject(fn (array $item) => $foreignItemIds->has((string) ($item['id'] ?? '')))
            ->values();

        $availableTextItems = $textItems
            ->reject(fn (array $item) => $foreignItemIds->has((string) ($item['id'] ?? '')))
            ->values();

        $legacyPanels = $availableShapeItems
            ->filter(fn (array $item) => $this->isLegacyGreenPanel($item))
            ->keyBy('id');

        $normalItems = $availableTextItems
            ->concat(
                $availableShapeItems->reject(
                    fn (array $item) => $legacyPanels->has($item['id'] ?? '')
                )
            )
            ->values();

        $anchorMap = $this->discoverMunicipalityAnchors($province, $mappings, $normalItems);

        // If generated panels already exist for this exact board, normal sync can take over.
        $hasCurrentPanels = GeneratedMiroItem::query()
            ->where('province_id', $province->id)
            ->where('board_id', $province->miro_board_id)
            ->where('item_type', 'panel')
            ->exists();

        if ($hasCurrentPanels) {
            return;
        }

        $connectors = collect($this->miro->getConnectors($province->miro_board_id));
        $assignments = $this->assignLegacyPanels($mappings, $anchorMap, $legacyPanels, $connectors);
        $this->adoptAssignedPanels($province, $mappings, $anchorMap, $assignments, $legacyPanels);

        /*
         |--------------------------------------------------------------------------
         | Non-destructive board policy
         |--------------------------------------------------------------------------
         |
         | Do not delete old/unclaimed green panels or red connectors. They may
         | belong to another province or may be intentionally positioned content.
         | Normal sync will only update items tracked to the current province.
         |
         */
    }

    private function discoverMunicipalityAnchors(Province $province, Collection $mappings, Collection $items): array
    {
        $anchors = [];

        foreach ($mappings as $mapping) {
            $expectedKey = $this->municipalityCompareKey($province, $mapping->municipality);
            $candidate = $items->first(function (array $item) use ($province, $expectedKey) {
                $content = $this->itemText($item);
                if ($content === '') {
                    return false;
                }

                return $this->municipalityCompareKey($province, $content) === $expectedKey;
            });

            if (!$candidate || empty($candidate['id'])) {
                continue;
            }

            [$x, $y] = $this->position($candidate);
            if ($x === null || $y === null) {
                continue;
            }

            $anchorKey = $this->anchorKey($mapping->municipality_key);
            $existing = GeneratedMiroItem::query()
                ->where('province_id', $province->id)
                ->where('stable_key', $anchorKey)
                ->first();

            /*
             |-----------------------------------------------------------------------
             | Never delete an old anchor from Miro
             |-----------------------------------------------------------------------
             |
             | If a better permanent municipality label is discovered, only the
             | local tracking record is repointed to it. The previous Miro item is
             | left untouched.
             |
             */

            GeneratedMiroItem::updateOrCreate(
                [
                    'province_id' => $province->id,
                    'stable_key' => $anchorKey,
                ],
                [
                    'board_id' => $province->miro_board_id,
                    'item_type' => 'external_anchor',
                    'miro_item_id' => $candidate['id'],
                    'label' => $mapping->municipality.' map label',
                    'x' => (int) round($x),
                    'y' => (int) round($y),
                    'meta' => [
                        'municipality_key' => $mapping->municipality_key,
                        'source' => 'existing_miro_map_item',
                        'source_type' => $candidate['type'] ?? null,
                    ],
                    'last_synced_at' => now(),
                ],
            );

            $mapping->update([
                'anchor_x' => (int) round($x),
                'anchor_y' => (int) round($y),
                'configured' => true,
            ]);

            $anchors[$mapping->municipality_key] = [
                'id' => $candidate['id'],
                'x' => (float) $x,
                'y' => (float) $y,
            ];
        }

        return $anchors;
    }

    private function assignLegacyPanels(
        Collection $mappings,
        array $anchorMap,
        Collection $legacyPanels,
        Collection $connectors,
    ): array {
        $adjacency = [];

        foreach ($connectors as $connector) {
            [$start, $end] = $this->connectorEndpoints($connector);
            if (!$start || !$end) {
                continue;
            }

            $adjacency[$start][] = $end;
            $adjacency[$end][] = $start;
        }

        $assignments = [];
        $claimed = [];

        foreach ($mappings as $mapping) {
            $anchor = $anchorMap[$mapping->municipality_key] ?? null;
            if (!$anchor) {
                continue;
            }

            $queue = [[$anchor['id'], 0]];
            $seen = [$anchor['id'] => true];
            $found = [];

            while ($queue !== []) {
                [$currentId, $depth] = array_shift($queue);
                foreach ($adjacency[$currentId] ?? [] as $neighborId) {
                    if (isset($seen[$neighborId])) {
                        continue;
                    }
                    $seen[$neighborId] = true;

                    if ($legacyPanels->has($neighborId)) {
                        $found[] = ['id' => $neighborId, 'depth' => $depth + 1];
                        $queue[] = [$neighborId, $depth + 1];
                    }
                }
            }

            $panelIds = collect($found)
                ->sortBy(fn (array $entry) => sprintf('%08d:%s', $entry['depth'], $entry['id']))
                ->pluck('id')
                ->reject(fn (string $id) => isset($claimed[$id]))
                ->values()
                ->all();

            // Some old Miro arrows are visually drawn but not snapped to both items.
            // Preserve the nearest green panel as a conservative fallback.
            if ($panelIds === []) {
                $nearest = $legacyPanels
                    ->reject(fn (array $panel, string $id) => isset($claimed[$id]))
                    ->map(function (array $panel, string $id) use ($anchor) {
                        [$x, $y] = $this->position($panel);
                        if ($x === null || $y === null) {
                            return null;
                        }

                        return [
                            'id' => $id,
                            'distance' => sqrt((($x - $anchor['x']) ** 2) + (($y - $anchor['y']) ** 2)),
                        ];
                    })
                    ->filter()
                    ->sortBy('distance')
                    ->first();

                if ($nearest && $nearest['distance'] <= (float) config('imports.board_cleanup.nearest_panel_distance', 1800)) {
                    $panelIds[] = $nearest['id'];
                }
            }

            foreach ($panelIds as $id) {
                $claimed[$id] = true;
            }

            $assignments[$mapping->municipality_key] = $panelIds;
        }

        return $assignments;
    }

    private function adoptAssignedPanels(
        Province $province,
        Collection $mappings,
        array $anchorMap,
        array $assignments,
        Collection $legacyPanels,
    ): void {
        $detectedAnchors = collect($anchorMap);
        $centroidX = $detectedAnchors->isNotEmpty() ? (float) $detectedAnchors->avg('x') : (float) $province->base_x;
        $centroidY = $detectedAnchors->isNotEmpty() ? (float) $detectedAnchors->avg('y') : (float) $province->base_y;
        $panelDistance = (int) config('imports.layout.auto_panel_distance', 620);

        foreach ($mappings as $mapping) {
            $anchor = $anchorMap[$mapping->municipality_key] ?? null;
            $panelIds = $assignments[$mapping->municipality_key] ?? [];
            $firstPanel = $panelIds !== [] ? $legacyPanels->get($panelIds[0]) : null;

            if ($firstPanel) {
                [$panelX, $panelY] = $this->position($firstPanel);
                $flow = $this->flowFromExistingPanels($panelIds, $legacyPanels)
                    ?? $this->flowFromVector(($panelX ?? 0) - ($anchor['x'] ?? 0), ($panelY ?? 0) - ($anchor['y'] ?? 0));

                $mapping->update([
                    'panel_x' => (int) round($panelX ?? $mapping->panel_x),
                    'panel_y' => (int) round($panelY ?? $mapping->panel_y),
                    'flow_direction' => $flow,
                    'configured' => true,
                ]);
            } elseif ($anchor) {
                $flow = $this->flowFromVector($anchor['x'] - $centroidX, $anchor['y'] - $centroidY);
                [$panelX, $panelY] = $this->offsetPoint($anchor['x'], $anchor['y'], $flow, $panelDistance);

                $mapping->update([
                    'panel_x' => (int) round($panelX),
                    'panel_y' => (int) round($panelY),
                    'flow_direction' => $flow,
                    'configured' => true,
                ]);
            }

            foreach ($panelIds as $index => $panelId) {
                $panel = $legacyPanels->get($panelId);
                if (!$panel) {
                    continue;
                }

                [$x, $y] = $this->position($panel);
                GeneratedMiroItem::updateOrCreate(
                    [
                        'province_id' => $province->id,
                        'stable_key' => $this->panelKey($mapping->municipality_key, $index),
                    ],
                    [
                        'board_id' => $province->miro_board_id,
                        'item_type' => 'panel',
                        'miro_item_id' => $panelId,
                        'label' => $mapping->municipality.' adopted panel '.($index + 1),
                        'x' => $x !== null ? (int) round($x) : null,
                        'y' => $y !== null ? (int) round($y) : null,
                        'meta' => [
                            'municipality_key' => $mapping->municipality_key,
                            'panel_index' => $index,
                            'source' => 'adopted_legacy_green_panel',
                        ],
                        'last_synced_at' => now(),
                    ],
                );
            }
        }
    }

    private function isLegacyGreenPanel(array $item): bool
    {
        $shape = strtolower((string) data_get($item, 'data.shape', ''));
        if (!in_array($shape, ['round_rectangle', 'rectangle'], true)) {
            return false;
        }

        $width = (float) data_get($item, 'geometry.width', 0);
        $height = (float) data_get($item, 'geometry.height', 0);
        if ($width < 180 || $height < 120) {
            return false;
        }

        return $this->isGreenHex((string) data_get($item, 'style.fillColor', ''));
    }

    private function isGreenHex(string $hex): bool
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return false;
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        // Mapping panels are a light/medium green. Keep the filter deliberately narrow
        // enough to avoid the beige provincial map and normal white board content.
        return $g >= 120 && $g > ($r * 1.08) && $g > ($b * 1.20) && ($r + $g + $b) >= 300;
    }

    private function connectorEndpoints(array $connector): array
    {
        return [
            data_get($connector, 'startItem.id') ?: data_get($connector, 'startItem.item.id'),
            data_get($connector, 'endItem.id') ?: data_get($connector, 'endItem.item.id'),
        ];
    }

    private function flowFromExistingPanels(array $panelIds, Collection $legacyPanels): ?string
    {
        if (count($panelIds) < 2) {
            return null;
        }

        [$x1, $y1] = $this->position($legacyPanels->get($panelIds[0], []));
        [$x2, $y2] = $this->position($legacyPanels->get($panelIds[1], []));
        if ($x1 === null || $y1 === null || $x2 === null || $y2 === null) {
            return null;
        }

        return $this->flowFromVector($x2 - $x1, $y2 - $y1);
    }

    private function flowFromVector(float $dx, float $dy): string
    {
        if (abs($dx) >= abs($dy)) {
            return $dx < 0 ? 'left' : 'right';
        }

        return $dy < 0 ? 'up' : 'down';
    }

    private function offsetPoint(float $x, float $y, string $flow, int $distance): array
    {
        return match ($flow) {
            'left' => [$x - $distance, $y],
            'up' => [$x, $y - $distance],
            'down' => [$x, $y + $distance],
            default => [$x + $distance, $y],
        };
    }

    private function position(array $item): array
    {
        $x = data_get($item, 'position.x');
        $y = data_get($item, 'position.y');

        return [is_numeric($x) ? (float) $x : null, is_numeric($y) ? (float) $y : null];
    }

    private function itemText(array $item): string
    {
        $content = (string) data_get($item, 'data.content', '');
        $plain = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $content)));
        $lines = preg_split('/\R+/', $plain) ?: [];

        return trim((string) ($lines[0] ?? $plain));
    }

    private function municipalityCompareKey(Province $province, string $value): string
    {
        $key = $this->plainKey($value);

        foreach (config('imports.municipality_aliases.'.$province->name, []) as $from => $to) {
            if ($this->stripAdministrativeWords($this->plainKey($from)) === $this->stripAdministrativeWords($key)) {
                $key = $this->plainKey($to);
                break;
            }
        }

        return $this->stripAdministrativeWords($key);
    }

    private function stripAdministrativeWords(string $key): string
    {
        $key = preg_replace('/^(CITY|MUNICIPALITY) OF /', '', $key) ?? $key;
        $key = preg_replace('/ (CITY|MUNICIPALITY)$/', '', $key) ?? $key;

        return trim($key);
    }

    private function plainKey(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', ' ')
            ->trim()
            ->toString();
    }

    private function anchorKey(string $municipalityKey): string
    {
        return 'anchor:'.substr(sha1($municipalityKey), 0, 20);
    }

    private function panelKey(string $municipalityKey, int $index): string
    {
        return 'panel:'.substr(sha1($municipalityKey), 0, 20).':'.$index;
    }
}
