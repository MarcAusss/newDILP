<?php

namespace App\Services;

use App\Models\ImportBatch;
use App\Models\MunicipalityMapping;
use App\Models\Province;
use Illuminate\Support\Collection;
use RuntimeException;

class MiroLayoutService
{
    public function build(Province $province, ImportBatch $batch): array
    {
        $batch->loadMissing('rows');

        $allRows = $batch->rows->sortBy('sort_order')->values();
        $regularRows = $allRows->where('is_group_project', false)->values();
        $groupRows = $allRows->where('is_group_project', true)->values();

        /*
        |--------------------------------------------------------------------------
        | New-import batch identity
        |--------------------------------------------------------------------------
        |
        | Every ImportBatch owns a fresh set of generated Miro boxes/connectors.
        | Permanent municipality anchors intentionally remain shared. If the same
        | batch is retried after a timeout, these stable keys stay the same so that
        | only that partial batch is resumed instead of duplicated.
        |
        */
        $batchPrefix = 'batch:'.$batch->id.':';
        $batchSlot = $this->batchSlot($province, $batch);

        $mappings = MunicipalityMapping::query()
            ->where('province_id', $province->id)
            ->orderBy('sort_order')
            ->get()
            ->keyBy('municipality_key');

        $shapes = [];
        $connectors = [];

        /** @var Collection<string, Collection<int, \App\Models\ImportRow>> $groups */
        $groups = $regularRows->groupBy('municipality_key');

        foreach ($groups as $municipalityKey => $municipalityRows) {
            $mapping = $mappings->get($municipalityKey);

            if (!$mapping) {
                throw new RuntimeException(
                    'No municipality mapping record exists for '.$municipalityRows->first()->municipality.'. Re-analyze the workbook.'
                );
            }

            $municipalityHash = substr(sha1($municipalityKey), 0, 20);
            $anchorKey = 'anchor:'.$municipalityHash;

            $shapes[] = [
                'stable_key' => $anchorKey,
                'item_type' => 'anchor',
                'label' => $mapping->municipality.' anchor',
                'content' => '',
                'x' => $mapping->anchor_x,
                'y' => $mapping->anchor_y,
                'width' => config('imports.layout.anchor_size'),
                'height' => config('imports.layout.anchor_size'),
                'style' => 'anchor',
                'shape' => 'circle',
                'meta' => [
                    'municipality_key' => $municipalityKey,
                    'municipality' => $mapping->municipality,
                ],
            ];

            $panels = $this->chunkRows(
                $municipalityRows,
                (int) config('imports.layout.max_lines_per_panel', 29)
            );

            $previousKey = $anchorKey;
            $previousPlannedX = $mapping->anchor_x;
            $previousPlannedY = $mapping->anchor_y;

            foreach ($panels as $panelIndex => $panel) {
                $panelKey = $batchPrefix.'panel:'.$municipalityHash.':'.$panelIndex;
                [$x, $y] = $this->panelPosition($mapping, $panelIndex, $batchSlot);
                $content = $this->normalPanelContent($panel['rows']);
                $height = $this->panelHeight($panel['line_count']);

                $shapes[] = [
                    'stable_key' => $panelKey,
                    'item_type' => 'panel',
                    'label' => $mapping->municipality.' panel '.($panelIndex + 1),
                    'content' => $content,
                    'x' => $x,
                    'y' => $y,
                    'width' => config('imports.layout.panel_width'),
                    'height' => $height,
                    'style' => 'panel',
                    'shape' => 'round_rectangle',
                    'meta' => [
                        'municipality_key' => $municipalityKey,
                        'municipality' => $mapping->municipality,
                        'panel_index' => $panelIndex,
                        'batch_id' => $batch->id,
                        'flow_direction' => $mapping->flow_direction,
                        'previous_key' => $previousKey,
                        'offset_x' => $x - $previousPlannedX,
                        'offset_y' => $y - $previousPlannedY,
                    ],
                ];

                // Arrowhead is on endItem: panel -> previous panel -> municipality pin.
                $connectors[] = [
                    'stable_key' => $batchPrefix.'connector:'.$municipalityHash.':'.$panelIndex,
                    'item_type' => 'connector',
                    'label' => $mapping->municipality.' connector '.($panelIndex + 1),
                    'start_key' => $panelKey,
                    'end_key' => $previousKey,
                    'meta' => [
                        'municipality_key' => $municipalityKey,
                        'panel_index' => $panelIndex,
                        'batch_id' => $batch->id,
                    ],
                ];

                $previousKey = $panelKey;
                $previousPlannedX = $x;
                $previousPlannedY = $y;
            }
        }

        $groupShapes = $this->groupProjectShapes($province, $batch, $groupRows, $mappings, $batchSlot);
        $summaryShapes = $this->summaryShapes($province, $batch, $regularRows, $groupRows, $mappings, $batchSlot);

        return [
            'shapes' => [...$shapes, ...$groupShapes, ...$summaryShapes],
            'connectors' => $connectors,
        ];
    }

    private function groupProjectShapes(
        Province $province,
        ImportBatch $batch,
        Collection $groupRows,
        Collection $mappings,
        int $batchSlot,
    ): array {
        if ($groupRows->isEmpty()) {
            return [];
        }

        $layout = config('imports.layout');
        [$startX, $startY] = $this->groupProjectBasePosition($province, $mappings, $batchSlot);
        $columns = max(1, (int) ($layout['group_panel_columns'] ?? 2));
        $width = (int) ($layout['group_panel_width'] ?? 470);
        $height = (int) ($layout['group_panel_compact_height'] ?? 220);
        $gap = (int) ($layout['group_panel_gap'] ?? 90);
        $boxIndex = 0;
        $shapes = [];

        // One #EA9999 project BLOCK = one compact YELLOW box.
        // Only Project Code + project-level beneficiary count are displayed.
        foreach ($groupRows->groupBy('group_project_key') as $groupProjectKey => $rows) {
            $projectCode = (string) ($rows->first()->group_project_label ?: 'Group Project');
            $summary = $this->summarizeGroupProject($rows->values());
            $groupHash = substr(sha1((string) $groupProjectKey), 0, 20);

            $columnIndex = $boxIndex % $columns;
            $rowIndex = intdiv($boxIndex, $columns);
            $x = $startX + ($columnIndex * ($width + $gap));
            $y = $startY + ($rowIndex * ($height + $gap));

            $shapes[] = [
                'stable_key' => 'batch:'.$batch->id.':group-panel:'.$groupHash.':0',
                'item_type' => 'group_panel',
                'label' => 'Group Project '.$projectCode,
                'content' => $this->groupPanelContent($projectCode, $summary['beneficiary_total']),
                'x' => $x,
                'y' => $y,
                'width' => $width,
                'height' => $height,
                'style' => 'group_panel',
                'shape' => 'round_rectangle',
                'meta' => [
                    'group_project_key' => $groupProjectKey,
                    'group_project_label' => $projectCode,
                    'beneficiary_total' => $summary['beneficiary_total'],
                    'undertaking_count' => $summary['undertaking_count'],
                    'source' => 'ea9999_group_project',
                    'batch_id' => $batch->id,
                ],
            ];

            $boxIndex++;
        }

        return $shapes;
    }

    private function summaryShapes(
        Province $province,
        ImportBatch $batch,
        Collection $regularRows,
        Collection $groupRows,
        Collection $mappings,
        int $batchSlot,
    ): array {
        $layout = config('imports.layout');
        [$baseX, $baseY] = $this->summaryBasePosition($province, $groupRows, $mappings, $batchSlot);

        $leftWidth = (int) ($layout['summary_left_width'] ?? 560);
        $rightWidth = (int) ($layout['summary_right_width'] ?? 620);
        $columnGap = (int) ($layout['summary_column_gap'] ?? 90);
        $rowGap = (int) ($layout['summary_row_gap'] ?? 70);
        $topHeight = (int) ($layout['summary_top_height'] ?? 300);
        $rightHeight = (int) ($layout['summary_right_box_height'] ?? 360);
        $smallHeight = (int) ($layout['summary_small_box_height'] ?? 250);

        $undertakingSummary = $this->regularUndertakingSummary($regularRows);
        $municipalitySummary = $this->municipalitySummary($regularRows, $mappings);

        $allUndertakingContent = $this->allUndertakingsContent($undertakingSummary['ordered']);
        $undertakingHeight = $this->summaryUndertakingsHeight(count($undertakingSummary['ordered']));

        $leftX = $baseX;
        $rightX = $baseX + (int) round(($leftWidth + $rightWidth) / 2) + $columnGap;
        $topY = $baseY;
        $undertakingY = $topY + (int) round($topHeight / 2) + $rowGap + (int) round($undertakingHeight / 2);

        $highestY = $topY;
        $leastY = $highestY + (int) round($rightHeight / 2) + $rowGap + (int) round($rightHeight / 2);
        $beneficiaryY = $leastY + (int) round($rightHeight / 2) + $rowGap + (int) round($smallHeight / 2);
        $groupY = $beneficiaryY + (int) round($smallHeight / 2) + $rowGap + (int) round($smallHeight / 2);
        $approvedY = $groupY + (int) round($smallHeight / 2) + $rowGap + (int) round($smallHeight / 2);

        return [
            $this->summaryShape(
                'batch:'.$batch->id.':summary:top-projects',
                'Top 3 Projects',
                $this->topProjectsContent($undertakingSummary['top']),
                $leftX,
                $topY,
                $leftWidth,
                $topHeight,
                'summary_top'
            ),
            $this->summaryShape(
                'batch:'.$batch->id.':summary:all-undertakings',
                'All Undertakings',
                $allUndertakingContent,
                $leftX,
                $undertakingY,
                $leftWidth,
                $undertakingHeight,
                'summary_undertakings'
            ),
            $this->summaryShape(
                'batch:'.$batch->id.':summary:highest-municipalities',
                'Highest Municipalities',
                $this->highestMunicipalitiesContent($municipalitySummary['highest']),
                $rightX,
                $highestY,
                $rightWidth,
                $rightHeight,
                'summary_highest'
            ),
            $this->summaryShape(
                'batch:'.$batch->id.':summary:least-municipalities',
                'Least Municipalities',
                $this->leastMunicipalitiesContent($municipalitySummary['least']),
                $rightX,
                $leastY,
                $rightWidth,
                $rightHeight,
                'summary_least'
            ),
            $this->summaryShape(
                'batch:'.$batch->id.':summary:beneficiaries',
                'Total Number of Beneficiaries',
                '<strong>Total Number of Beneficiaries:</strong><br><br>'.
                '<strong>Individual - '.$this->formatNumber((float) $batch->beneficiary_total).'</strong><br>'.
                '<strong>Group - '.$this->formatNumber((float) $batch->group_beneficiary_total).'</strong>',
                $rightX,
                $beneficiaryY,
                $rightWidth,
                $smallHeight,
                'summary_beneficiaries'
            ),
            $this->summaryShape(
                'batch:'.$batch->id.':summary:group-projects-awarded',
                'Group Projects Awarded',
                '<strong>Group Projects Awarded:</strong><br><br><strong>'.number_format((int) $batch->group_project_count).'</strong>',
                $rightX,
                $groupY,
                $rightWidth,
                $smallHeight,
                'summary_group'
            ),
            $this->summaryShape(
                'batch:'.$batch->id.':summary:total-approved-projects',
                'Total Approved Projects',
                '<strong>Total Approved Projects:</strong><br><br><strong>'.number_format((int) $batch->total_approved_projects).'</strong>',
                $rightX,
                $approvedY,
                $rightWidth,
                $smallHeight,
                'summary_total'
            ),
        ];
    }

    private function summaryShape(
        string $stableKey,
        string $label,
        string $content,
        int $x,
        int $y,
        int $width,
        int $height,
        string $style,
    ): array {
        return [
            'stable_key' => $stableKey,
            'item_type' => 'summary_panel',
            'label' => $label,
            'content' => $content,
            'x' => $x,
            'y' => $y,
            'width' => $width,
            'height' => $height,
            'style' => $style,
            'shape' => 'round_rectangle',
            'meta' => [
                'source' => 'provincial_summary',
                'summary_type' => $stableKey,
            ],
        ];
    }

    private function regularUndertakingSummary(Collection $regularRows): array
    {
        $totals = [];
        $order = [];

        foreach ($regularRows as $row) {
            foreach ($row->undertakings ?? [] as $undertaking) {
                $name = trim((string) ($undertaking['name'] ?? ''));
                $count = (float) ($undertaking['count'] ?? 0);

                if ($name === '' || $count <= 0) {
                    continue;
                }

                $key = mb_strtoupper($name);

                if (!isset($totals[$key])) {
                    $order[] = $key;
                    $totals[$key] = [
                        'name' => $name,
                        'count' => 0.0,
                    ];
                }

                $totals[$key]['count'] += $count;
            }
        }

        $ordered = collect($order)
            ->map(function (string $key) use ($totals) {
                $item = $totals[$key];
                $item['count'] = round((float) $item['count'], 2);

                return $item;
            })
            ->filter(fn (array $item) => $item['count'] > 0)
            ->values();

        $top = $ordered
            ->sort(function (array $a, array $b) {
                $countCompare = $b['count'] <=> $a['count'];

                return $countCompare !== 0
                    ? $countCompare
                    : strcasecmp($a['name'], $b['name']);
            })
            ->take(3)
            ->values();

        return [
            'ordered' => $ordered,
            'top' => $top,
        ];
    }

    private function municipalitySummary(Collection $regularRows, Collection $mappings): array
    {
        $totals = [];

        foreach ($mappings as $key => $mapping) {
            $totals[$key] = [
                'name' => $mapping->municipality,
                'count' => 0.0,
            ];
        }

        foreach ($regularRows as $row) {
            $key = (string) $row->municipality_key;

            if (!isset($totals[$key])) {
                $totals[$key] = [
                    'name' => $row->municipality,
                    'count' => 0.0,
                ];
            }

            $totals[$key]['count'] += (float) $row->beneficiary_total;
        }

        $collection = collect($totals)
            ->map(function (array $item) {
                $item['count'] = round((float) $item['count'], 2);

                return $item;
            })
            ->values();

        $highestCount = max(1, (int) config('imports.layout.summary_highest_count', 4));
        $leastCount = max(1, (int) config('imports.layout.summary_least_count', 6));

        $highest = $collection
            ->sort(function (array $a, array $b) {
                $countCompare = $b['count'] <=> $a['count'];

                return $countCompare !== 0
                    ? $countCompare
                    : strcasecmp($a['name'], $b['name']);
            })
            ->take($highestCount)
            ->values();

        $least = $collection
            ->sort(function (array $a, array $b) {
                $countCompare = $a['count'] <=> $b['count'];

                return $countCompare !== 0
                    ? $countCompare
                    : strcasecmp($a['name'], $b['name']);
            })
            ->take($leastCount)
            ->values();

        return [
            'highest' => $highest,
            'least' => $least,
        ];
    }

    private function topProjectsContent(Collection $top): string
    {
        $lines = ['<strong>TOP 3 PROJECTS :</strong>'];

        foreach ($top as $index => $item) {
            $lines[] = '<strong>'.($index + 1).'. '.e($item['name']).' - '.$this->formatNumber((float) $item['count']).'</strong>';
        }

        return implode('<br>', $lines);
    }

    private function allUndertakingsContent(Collection $items): string
    {
        if ($items->isEmpty()) {
            return '<strong>No regular undertakings recorded</strong>';
        }

        return $items
            ->map(fn (array $item) => e($item['name']).' - '.$this->formatNumber((float) $item['count']))
            ->implode('<br>');
    }

    private function highestMunicipalitiesContent(Collection $items): string
    {
        $lines = [
            '<strong>MUNICIPALITIES with the</strong>',
            '<strong>highest no. of DOLE assisted projects:</strong>',
            '',
        ];

        foreach ($items as $index => $item) {
            $lines[] = '<strong>'.($index + 1).'. '.e($item['name']).' - '.$this->formatNumber((float) $item['count']).'</strong>';
        }

        return implode('<br>', $lines);
    }

    private function leastMunicipalitiesContent(Collection $items): string
    {
        $year = (int) config('imports.summary_year', 2026);
        $lines = [
            '<strong>MUNICIPALITIES with least provided</strong>',
            '<strong>for Y'.$year.':</strong>',
            '',
        ];

        foreach ($items as $index => $item) {
            $lines[] = '<strong>'.($index + 1).'. '.e($item['name']).' - '.$this->formatNumber((float) $item['count']).'</strong>';
        }

        return implode('<br>', $lines);
    }

    private function chunkRows(Collection $rows, int $maxLines, int $headerLines = 0): array
    {
        $panels = [];
        $currentRows = [];
        $currentLines = $headerLines;

        foreach ($rows as $row) {
            $undertakingCount = max(1, count($row->undertakings ?? []));
            $blockLines = 1 + $undertakingCount + 1;

            if ($currentRows !== [] && ($currentLines + $blockLines) > $maxLines) {
                $panels[] = [
                    'rows' => $currentRows,
                    'line_count' => $currentLines,
                ];
                $currentRows = [];
                $currentLines = $headerLines;
            }

            $currentRows[] = $row;
            $currentLines += $blockLines;
        }

        if ($currentRows !== []) {
            $panels[] = [
                'rows' => $currentRows,
                'line_count' => $currentLines,
            ];
        }

        return $panels;
    }

    private function normalPanelContent(array $rows): string
    {
        $blocks = [];

        foreach ($rows as $row) {
            $lines = ['<strong>'.e($row->barangay).'</strong>'];
            $undertakings = $row->undertakings ?? [];

            if ($undertakings === []) {
                $lines[] = 'No undertaking recorded';
            } else {
                foreach ($undertakings as $undertaking) {
                    $lines[] = e($undertaking['name']).' - '.$this->formatNumber((float) $undertaking['count']);
                }
            }

            $blocks[] = implode('<br>', $lines);
        }

        return implode('<br><br>', $blocks);
    }

    private function groupPanelContent(string $projectCode, float $beneficiaryTotal): string
    {
        return implode('<br>', [
            '<strong>'.e($projectCode).'</strong>',
            '',
            '<strong>'.$this->formatNumber($beneficiaryTotal).' Beneficiaries</strong>',
        ]);
    }

    private function summarizeGroupProject(Collection $rows): array
    {
        $beneficiaryTotal = 0.0;
        $undertakings = [];

        foreach ($rows as $row) {
            $beneficiaryTotal += (float) $row->beneficiary_total;

            // Group Project undertaking details are deliberately ignored.
            // The highlighted Excel block is represented only by its project
            // code and project-level beneficiary total.
        }

        return [
            'beneficiary_total' => round($beneficiaryTotal, 2),
            'undertaking_count' => count($undertakings),
        ];
    }

    private function panelHeight(int $lineCount): int
    {
        $layout = config('imports.layout');
        $calculated = ($lineCount * $layout['panel_line_height']) + $layout['panel_padding'];

        return (int) max(
            $layout['panel_min_height'],
            min($layout['panel_max_height'], $calculated)
        );
    }

    private function summaryUndertakingsHeight(int $lineCount): int
    {
        $layout = config('imports.layout');
        $min = (int) ($layout['summary_undertakings_min_height'] ?? 850);
        $max = (int) ($layout['summary_undertakings_max_height'] ?? 1800);
        $calculated = 120 + (max(1, $lineCount) * 28);

        return max($min, min($max, $calculated));
    }

    private function panelPosition(MunicipalityMapping $mapping, int $panelIndex, int $batchSlot): array
    {
        $layout = config('imports.layout');
        $horizontalStep = $layout['panel_width'] + $layout['panel_gap'];
        $verticalStep = $layout['panel_max_height'] + $layout['panel_gap'];

        // A later import gets a new outward lane. Existing batches remain visible
        // instead of being covered, cleared, replaced, or deleted.
        $batchGap = max(0, (int) ($layout['new_batch_panel_gap'] ?? 2600));
        $laneOffset = $batchSlot * $batchGap;

        return match ($mapping->flow_direction) {
            'left' => [
                $mapping->panel_x - $laneOffset - ($panelIndex * $horizontalStep),
                $mapping->panel_y,
            ],
            'down' => [
                $mapping->panel_x,
                $mapping->panel_y + $laneOffset + ($panelIndex * $verticalStep),
            ],
            'up' => [
                $mapping->panel_x,
                $mapping->panel_y - $laneOffset - ($panelIndex * $verticalStep),
            ],
            default => [
                $mapping->panel_x + $laneOffset + ($panelIndex * $horizontalStep),
                $mapping->panel_y,
            ],
        };
    }

    private function groupProjectBasePosition(Province $province, Collection $mappings, int $batchSlot = 0): array
    {
        $layout = config('imports.layout');
        [$rightMost, $topMost] = $this->mapBounds($province, $mappings);
        $batchX = $batchSlot * max(0, (int) ($layout['new_batch_summary_x_gap'] ?? 2600));

        return [
            (int) round($rightMost + (int) ($layout['group_panel_x_gap_from_map'] ?? 900) + $batchX),
            (int) round($topMost),
        ];
    }

    private function summaryBasePosition(
        Province $province,
        Collection $groupRows,
        Collection $mappings,
        int $batchSlot = 0,
    ): array {
        $layout = config('imports.layout');
        [$rightMost, $topMost] = $this->mapBounds($province, $mappings);
        $summaryGap = (int) ($layout['summary_x_gap'] ?? 900);

        if ($groupRows->isEmpty()) {
            $batchX = $batchSlot * max(0, (int) ($layout['new_batch_summary_x_gap'] ?? 2600));

            return [
                (int) round($rightMost + $summaryGap + $batchX),
                (int) round($topMost),
            ];
        }

        [$groupX] = $this->groupProjectBasePosition($province, $mappings, $batchSlot);
        $columns = max(1, (int) ($layout['group_panel_columns'] ?? 2));
        $width = (int) ($layout['group_panel_width'] ?? 470);
        $gap = (int) ($layout['group_panel_gap'] ?? 90);
        $groupCount = $groupRows->groupBy('group_project_key')->count();
        $usedColumns = min($columns, max(1, $groupCount));
        $groupRightEdge = $groupX + (($usedColumns - 1) * ($width + $gap)) + (int) round($width / 2);

        return [
            (int) round($groupRightEdge + $summaryGap + ((int) ($layout['summary_left_width'] ?? 560) / 2)),
            (int) round($topMost),
        ];
    }

    private function batchSlot(Province $province, ImportBatch $batch): int
    {
        // Count earlier analyses for this province. Gaps are harmless and safer
        // than reusing a lane that may contain a partial/failed Miro batch.
        return ImportBatch::query()
            ->where('province_id', $province->id)
            ->where('id', '<', $batch->id)
            ->count();
    }

    private function mapBounds(Province $province, Collection $mappings): array
    {
        $rightMost = (float) $province->base_x;
        $topMost = (float) $province->base_y;
        $hasX = false;
        $hasY = false;

        foreach ($mappings as $mapping) {
            foreach ([$mapping->anchor_x, $mapping->panel_x] as $x) {
                if ($x !== null) {
                    $rightMost = $hasX ? max($rightMost, (float) $x) : (float) $x;
                    $hasX = true;
                }
            }

            foreach ([$mapping->anchor_y, $mapping->panel_y] as $y) {
                if ($y !== null) {
                    $topMost = $hasY ? min($topMost, (float) $y) : (float) $y;
                    $hasY = true;
                }
            }
        }

        return [$rightMost, $topMost];
    }

    private function formatNumber(float $value): string
    {
        return fmod($value, 1.0) === 0.0
            ? number_format($value, 0)
            : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
