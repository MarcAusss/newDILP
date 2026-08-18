<?php

namespace App\Services;

use App\Models\GeneratedMiroItem;
use App\Models\MunicipalityMapping;
use App\Models\Province;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class MiroMappingDiscoveryService
{
    public function __construct(private readonly MiroService $miro)
    {
    }

    /**
     * Ensure the configured municipality list exists in the local placement table.
     * This does not create, update, or delete anything on Miro.
     */
    public function ensureProvinceMunicipalities(Province $province): int
    {
        $municipalities = config('imports.municipality_lists.'.$province->name, []);

        if ($municipalities === []) {
            return 0;
        }

        $layout = config('imports.layout', []);
        $created = 0;

        foreach (array_values($municipalities) as $index => $municipality) {
            $municipalityKey = $this->nameKey($municipality);

            $mapping = MunicipalityMapping::query()->firstOrNew([
                'province_id' => $province->id,
                'municipality_key' => $municipalityKey,
            ]);

            $mapping->municipality = $municipality;
            $mapping->sort_order = $index;

            if (!$mapping->exists) {
                $anchorX = (int) $province->base_x + (int) ($layout['default_anchor_x_offset'] ?? 0);
                $anchorY = (int) $province->base_y + ($index * (int) ($layout['default_municipality_vertical_gap'] ?? 300));

                $mapping->anchor_x = $anchorX;
                $mapping->anchor_y = $anchorY;
                $mapping->panel_x = (int) $province->base_x + (int) ($layout['default_panel_x_offset'] ?? 700);
                $mapping->panel_y = $anchorY;
                $mapping->flow_direction = 'right';
                $mapping->configured = false;
                $created++;
            }

            $mapping->save();
        }

        return $created;
    }

    /**
     * Read-only board scan. It discovers municipality labels, green data panels,
     * and connector relationships, then saves only local placement metadata.
     */
    public function scan(Province $province): array
    {
        if (!$province->miro_board_id) {
            throw new RuntimeException('Configure a Miro board for '.$province->name.' before scanning.');
        }

        $this->miro->getBoard($province->miro_board_id);
        $this->ensureProvinceMunicipalities($province);

        $mappings = $province->municipalityMappings()
            ->orderBy('sort_order')
            ->orderBy('municipality')
            ->get();

        if ($mappings->isEmpty()) {
            throw new RuntimeException('No municipality list is configured for '.$province->name.'.');
        }

        $shapeItems = collect($this->miro->getBoardItems($province->miro_board_id, 'shape'));
        $textItems = collect($this->miro->getBoardItems($province->miro_board_id, 'text'));
        $connectors = collect($this->miro->getConnectors($province->miro_board_id));

        $greenPanels = $shapeItems
            ->filter(fn (array $item) => $this->isLegacyGreenPanel($item))
            ->keyBy(fn (array $item) => (string) ($item['id'] ?? ''))
            ->filter(fn (array $item, string $id) => $id !== '');

        $labelCandidates = $textItems
            ->concat($shapeItems->reject(fn (array $item) => $this->isLegacyGreenPanel($item)))
            ->values();

        $anchorMatches = [];

        foreach ($mappings as $mapping) {
            $candidate = $this->bestMunicipalityLabelCandidate(
                $province,
                $mapping->municipality,
                $labelCandidates,
            );

            if (!$candidate || empty($candidate['id'])) {
                continue;
            }

            [$x, $y] = $this->position($candidate);

            if ($x === null || $y === null) {
                continue;
            }

            $anchorMatches[$mapping->municipality_key] = [
                'item' => $candidate,
                'id' => (string) $candidate['id'],
                'x' => $x,
                'y' => $y,
            ];
        }

        $detectedAnchors = collect($anchorMatches);
        $centroidX = $detectedAnchors->isNotEmpty()
            ? (float) $detectedAnchors->avg('x')
            : (float) $province->base_x;
        $centroidY = $detectedAnchors->isNotEmpty()
            ? (float) $detectedAnchors->avg('y')
            : (float) $province->base_y;

        $adjacency = $this->buildAdjacency($connectors);
        $claimedPanels = [];
        $foundAnchors = 0;
        $withBoxes = 0;
        $withoutBoxes = 0;

        foreach ($mappings as $mapping) {
            $anchor = $anchorMatches[$mapping->municipality_key] ?? null;

            if (!$anchor) {
                $mapping->update(['configured' => false]);

                GeneratedMiroItem::query()
                    ->where('province_id', $province->id)
                    ->where('stable_key', $this->anchorKey($mapping->municipality_key))
                    ->where('item_type', 'external_anchor')
                    ->delete();

                continue;
            }

            $foundAnchors++;

            $panelIds = $this->discoverConnectedPanelChain(
                $anchor['id'],
                $greenPanels,
                $adjacency,
                $claimedPanels,
            );

            if ($panelIds === []) {
                $nearestPanel = $this->nearestUnclaimedPanel($anchor, $greenPanels, $claimedPanels);

                if ($nearestPanel) {
                    $panelIds[] = $nearestPanel;
                }
            }

            foreach ($panelIds as $panelId) {
                $claimedPanels[$panelId] = true;
            }

            $firstPanel = $panelIds !== [] ? $greenPanels->get($panelIds[0]) : null;

            if ($firstPanel) {
                [$panelX, $panelY] = $this->position($firstPanel);
                $flow = $this->flowFromPanelsOrVector($panelIds, $greenPanels, $anchor);

                $mapping->update([
                    'anchor_x' => (int) round($anchor['x']),
                    'anchor_y' => (int) round($anchor['y']),
                    'panel_x' => (int) round($panelX ?? $mapping->panel_x),
                    'panel_y' => (int) round($panelY ?? $mapping->panel_y),
                    'flow_direction' => $flow,
                    'configured' => true,
                ]);

                $withBoxes++;
            } else {
                $flow = $this->outwardFlow(
                    $anchor['x'],
                    $anchor['y'],
                    $centroidX,
                    $centroidY,
                );

                [$panelX, $panelY] = $this->offsetPoint(
                    $anchor['x'],
                    $anchor['y'],
                    $flow,
                    (int) config('imports.layout.auto_panel_distance', 620),
                );

                $mapping->update([
                    'anchor_x' => (int) round($anchor['x']),
                    'anchor_y' => (int) round($anchor['y']),
                    'panel_x' => (int) round($panelX),
                    'panel_y' => (int) round($panelY),
                    'flow_direction' => $flow,
                    'configured' => true,
                ]);

                $withoutBoxes++;
            }

            GeneratedMiroItem::updateOrCreate(
                [
                    'province_id' => $province->id,
                    'stable_key' => $this->anchorKey($mapping->municipality_key),
                ],
                [
                    'board_id' => $province->miro_board_id,
                    'item_type' => 'external_anchor',
                    'miro_item_id' => $anchor['id'],
                    'label' => $mapping->municipality.' map label',
                    'x' => (int) round($anchor['x']),
                    'y' => (int) round($anchor['y']),
                    'meta' => [
                        'municipality_key' => $mapping->municipality_key,
                        'source' => 'mapping_setup_scan',
                        'source_type' => data_get($anchor, 'item.type'),
                        'legacy_panel_ids' => $panelIds,
                        'legacy_panel_count' => count($panelIds),
                        'scanned_at' => now()->toIso8601String(),
                    ],
                    'last_synced_at' => now(),
                ],
            );
        }

        return [
            'municipality_count' => $mappings->count(),
            'anchors_found' => $foundAnchors,
            'anchors_missing' => $mappings->count() - $foundAnchors,
            'with_existing_boxes' => $withBoxes,
            'without_existing_boxes' => $withoutBoxes,
            'green_boxes_detected' => $greenPanels->count(),
        ];
    }

    public function statuses(Province $province): Collection
    {
        if (!$province->miro_board_id) {
            return collect();
        }

        return GeneratedMiroItem::query()
            ->where('province_id', $province->id)
            ->where('board_id', $province->miro_board_id)
            ->where('item_type', 'external_anchor')
            ->get()
            ->keyBy(fn (GeneratedMiroItem $item) => (string) data_get($item->meta, 'municipality_key', ''));
    }

    private function bestMunicipalityLabelCandidate(
        Province $province,
        string $municipality,
        Collection $items,
    ): ?array {
        $expectedKey = $this->municipalityCompareKey($province, $municipality);

        return $items
            ->filter(function (array $item) use ($province, $expectedKey) {
                $content = $this->itemText($item);

                if ($content === '') {
                    return false;
                }

                return $this->municipalityCompareKey($province, $content) === $expectedKey;
            })
            ->sortByDesc(function (array $item) {
                $fontSize = (float) data_get($item, 'style.fontSize', 0);
                $typeBonus = ($item['type'] ?? null) === 'text' ? 1000 : 500;

                return $typeBonus + $fontSize;
            })
            ->first();
    }

    private function buildAdjacency(Collection $connectors): array
    {
        $adjacency = [];

        foreach ($connectors as $connector) {
            [$start, $end] = $this->connectorEndpoints($connector);

            if (!$start || !$end) {
                continue;
            }

            $adjacency[$start][] = $end;
            $adjacency[$end][] = $start;
        }

        return $adjacency;
    }

    private function discoverConnectedPanelChain(
        string $anchorId,
        Collection $greenPanels,
        array $adjacency,
        array $claimedPanels,
    ): array {
        $queue = [[$anchorId, 0]];
        $seen = [$anchorId => true];
        $found = [];

        while ($queue !== []) {
            [$currentId, $depth] = array_shift($queue);

            foreach ($adjacency[$currentId] ?? [] as $neighborId) {
                if (isset($seen[$neighborId])) {
                    continue;
                }

                $seen[$neighborId] = true;

                if ($greenPanels->has($neighborId)) {
                    $found[] = [
                        'id' => $neighborId,
                        'depth' => $depth + 1,
                    ];

                    $queue[] = [$neighborId, $depth + 1];
                }
            }
        }

        return collect($found)
            ->sortBy(fn (array $entry) => sprintf('%08d:%s', $entry['depth'], $entry['id']))
            ->pluck('id')
            ->reject(fn (string $id) => isset($claimedPanels[$id]))
            ->unique()
            ->values()
            ->all();
    }

    private function nearestUnclaimedPanel(
        array $anchor,
        Collection $greenPanels,
        array $claimedPanels,
    ): ?string {
        $nearest = $greenPanels
            ->reject(fn (array $panel, string $id) => isset($claimedPanels[$id]))
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

        if (!$nearest) {
            return null;
        }

        $threshold = (float) config('imports.board_cleanup.nearest_panel_distance', 1800);

        return $nearest['distance'] <= $threshold ? $nearest['id'] : null;
    }

    private function flowFromPanelsOrVector(
        array $panelIds,
        Collection $greenPanels,
        array $anchor,
    ): string {
        if (count($panelIds) >= 2) {
            [$x1, $y1] = $this->position($greenPanels->get($panelIds[0], []));
            [$x2, $y2] = $this->position($greenPanels->get($panelIds[1], []));

            if ($x1 !== null && $y1 !== null && $x2 !== null && $y2 !== null) {
                return $this->flowFromVector($x2 - $x1, $y2 - $y1);
            }
        }

        [$panelX, $panelY] = $this->position($greenPanels->get($panelIds[0], []));

        if ($panelX !== null && $panelY !== null) {
            return $this->flowFromVector($panelX - $anchor['x'], $panelY - $anchor['y']);
        }

        return 'right';
    }

    private function outwardFlow(
        float $x,
        float $y,
        float $centroidX,
        float $centroidY,
    ): string {
        return $this->flowFromVector($x - $centroidX, $y - $centroidY);
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

        return $g >= 120 && $g > ($r * 1.08) && $g > ($b * 1.20) && ($r + $g + $b) >= 300;
    }

    private function connectorEndpoints(array $connector): array
    {
        return [
            data_get($connector, 'startItem.id') ?: data_get($connector, 'startItem.item.id'),
            data_get($connector, 'endItem.id') ?: data_get($connector, 'endItem.item.id'),
        ];
    }

    private function position(array $item): array
    {
        $x = data_get($item, 'position.x');
        $y = data_get($item, 'position.y');

        return [
            is_numeric($x) ? (float) $x : null,
            is_numeric($y) ? (float) $y : null,
        ];
    }

    private function itemText(array $item): string
    {
        $content = (string) data_get($item, 'data.content', '');
        $plain = html_entity_decode(
            strip_tags(
                str_replace(['<br>', '<br/>', '<br />'], "\n", $content)
            )
        );
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

    private function nameKey(string $value): string
    {
        return $this->plainKey($value);
    }

    private function anchorKey(string $municipalityKey): string
    {
        return 'anchor:'.substr(sha1($municipalityKey), 0, 20);
    }
}
