<?php

namespace App\Services;

use App\Models\Province;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

class SpreadsheetImportService
{
    public function analyze(string $absolutePath, Province $province): array
    {
        /*
        |--------------------------------------------------------------------------
        | Source-sheet policy
        |--------------------------------------------------------------------------
        |
        | Selecting Albay produces one merged import from:
        |
        |   Albay + Regional Office
        |
        | "Copy of Regional Office" is intentionally ignored.
        | Every other province still reads only its own worksheet.
        |
        */
        $analyses = [];

        foreach ($this->sourceSheetsForProvince($province) as $requestedSheetName) {
            $analyses[] = $this->analyzeSheet(
                $absolutePath,
                $province,
                $requestedSheetName,
            );
        }

        return $this->mergeSheetAnalyses($analyses);
    }

    private function sourceSheetsForProvince(Province $province): array
    {
        if ($this->nameKey($province->name) === $this->nameKey('Albay')) {
            return [
                $province->sheet_name ?: 'Albay',
                'Regional Office',
            ];
        }

        return [
            $province->sheet_name ?: $province->name,
        ];
    }

    private function mergeSheetAnalyses(array $analyses): array
    {
        if ($analyses === []) {
            throw new InvalidArgumentException('No worksheet analysis results were produced.');
        }

        $regular = [];
        $regularOrder = [];
        $groups = [];
        $groupOrder = [];
        $municipalities = [];
        $regularProjectKeys = [];
        $groupProjectKeys = [];
        $warnings = [];
        $activityColumns = [];
        $sheetNames = [];
        $sourceRows = 0;

        foreach ($analyses as $analysisIndex => $analysis) {
            $sheetName = (string) ($analysis['sheet_name'] ?? ('Sheet '.($analysisIndex + 1)));
            $sheetNames[] = $sheetName;
            $sourceRows += (int) ($analysis['source_rows'] ?? 0);

            foreach ($analysis['_regular_project_keys'] ?? [] as $projectKey) {
                if ((string) $projectKey !== '') {
                    $regularProjectKeys[(string) $projectKey] = true;
                }
            }

            foreach ($analysis['_group_project_keys'] ?? [] as $projectKey) {
                if ((string) $projectKey !== '') {
                    $groupProjectKeys[(string) $projectKey] = true;
                }
            }

            foreach ($analysis['activity_columns'] ?? [] as $label) {
                $key = $this->nameKey((string) $label);

                if ($key !== '' && !isset($activityColumns[$key])) {
                    $activityColumns[$key] = (string) $label;
                }
            }

            foreach ($analysis['warnings'] ?? [] as $warning) {
                $warnings[] = '['.$sheetName.'] '.$warning;
            }

            foreach ($analysis['municipalities'] ?? [] as $municipality) {
                $key = (string) ($municipality['key'] ?? '');

                if ($key === '' || isset($municipalities[$key])) {
                    continue;
                }

                $municipalities[$key] = [
                    'name' => (string) ($municipality['name'] ?? $key),
                    'key' => $key,
                    'sort_order' => count($municipalities) + 1,
                ];
            }

            foreach ($analysis['rows'] ?? [] as $row) {
                $sortOrder = (($analysisIndex + 1) * 1000000) + (int) ($row['sort_order'] ?? 0);

                if ((bool) ($row['is_group_project'] ?? false)) {
                    $groupKey = (string) ($row['group_project_key'] ?? $row['barangay_key'] ?? '');

                    if ($groupKey === '') {
                        continue;
                    }

                    if (!isset($groups[$groupKey])) {
                        $groupOrder[] = $groupKey;
                        $groups[$groupKey] = $row;
                        $groups[$groupKey]['sort_order'] = $sortOrder;
                        $groups[$groupKey]['source_rows'] = array_values($row['source_rows'] ?? []);
                        continue;
                    }

                    // Same Project Code still means one approved Group Project.
                    $groups[$groupKey]['beneficiary_total'] = max(
                        (float) ($groups[$groupKey]['beneficiary_total'] ?? 0),
                        (float) ($row['beneficiary_total'] ?? 0),
                    );
                    $groups[$groupKey]['source_rows'] = array_values(array_unique([
                        ...($groups[$groupKey]['source_rows'] ?? []),
                        ...($row['source_rows'] ?? []),
                    ]));

                    continue;
                }

                $municipalityKey = (string) ($row['municipality_key'] ?? '');
                $barangayKey = (string) ($row['barangay_key'] ?? '');

                if ($municipalityKey === '' || $barangayKey === '') {
                    continue;
                }

                $recordKey = $municipalityKey.'|'.$barangayKey;

                if (!isset($regular[$recordKey])) {
                    $regularOrder[] = $recordKey;
                    $regular[$recordKey] = [
                        'sort_order' => $sortOrder,
                        'municipality' => (string) ($row['municipality'] ?? ''),
                        'municipality_key' => $municipalityKey,
                        'barangay' => (string) ($row['barangay'] ?? ''),
                        'barangay_key' => $barangayKey,
                        'undertakings' => [],
                        'undertaking_order' => [],
                        'source_rows' => [],
                    ];
                }

                $regular[$recordKey]['sort_order'] = min(
                    (int) $regular[$recordKey]['sort_order'],
                    $sortOrder,
                );
                $regular[$recordKey]['source_rows'] = array_values(array_unique([
                    ...$regular[$recordKey]['source_rows'],
                    ...($row['source_rows'] ?? []),
                ]));

                $this->mergeUndertakings(
                    $regular[$recordKey],
                    $row['undertakings'] ?? [],
                );
            }
        }

        $regularRows = $this->finalizeAggregatedRows($regular, $regularOrder, false);
        $groupRows = [];

        foreach ($groupOrder as $groupKey) {
            if (isset($groups[$groupKey])) {
                $groupRows[] = $groups[$groupKey];
            }
        }

        $rows = [...$regularRows, ...$groupRows];
        usort(
            $rows,
            fn (array $a, array $b) =>
                ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0)),
        );

        return [
            'sheet_name' => implode(' + ', array_values(array_unique($sheetNames))),
            'source_rows' => $sourceRows,
            'municipality_count' => count($municipalities),
            'barangay_count' => count($regularRows),
            'beneficiary_total' => round(array_sum(array_column($regularRows, 'beneficiary_total')), 2),
            'undertaking_total' => array_sum(array_column($regularRows, 'undertaking_count')),
            'regular_project_count' => count($regularProjectKeys),
            'group_project_count' => count($groupProjectKeys),
            'total_approved_projects' => count($regularProjectKeys) + count($groupProjectKeys),
            'group_beneficiary_total' => round(array_sum(array_map(
                fn (array $row) => (float) ($row['beneficiary_total'] ?? 0),
                $groupRows,
            )), 2),
            'group_undertaking_total' => 0,
            'municipalities' => array_values($municipalities),
            'rows' => $rows,
            'warnings' => array_slice(array_values(array_unique($warnings)), 0, 100),
            'activity_columns' => array_values($activityColumns),
        ];
    }

    private function analyzeSheet(string $absolutePath, Province $province, string $requestedSheetName): array
    {
        /*
        |--------------------------------------------------------------------------
        | Large workbook analysis
        |--------------------------------------------------------------------------
        |
        | The provincial workbook contains all six provinces plus summary sheets.
        | Loading every worksheet with styles is unnecessary and can exceed the
        | normal PHP web-request timeout. Group Project detection still requires
        | styles, so we keep style-aware loading but load ONLY the selected sheet.
        |
        */
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '1024M');
        ignore_user_abort(true);

        $reader = IOFactory::createReaderForFile($absolutePath);

        /*
        |--------------------------------------------------------------------------
        | Resolve the selected province before loading workbook cells
        |--------------------------------------------------------------------------
        */
        $worksheetNames = $reader->listWorksheetNames($absolutePath);
        $wantedSheetKey = $this->nameKey($requestedSheetName);
        $selectedSheetName = null;

        foreach ($worksheetNames as $worksheetName) {
            if ($this->nameKey($worksheetName) === $wantedSheetKey) {
                $selectedSheetName = $worksheetName;
                break;
            }
        }

        if (!$selectedSheetName) {
            throw new InvalidArgumentException(
                'The workbook does not contain the expected worksheet "'.
                $requestedSheetName.
                '". Available worksheets: '.implode(', ', $worksheetNames)
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Load only the selected province, WITH styles
        |--------------------------------------------------------------------------
        |
        | setReadDataOnly(false) is required because #EA9999 is the marker that
        | classifies a Project Code block as a Group Project.
        |
        */
        $reader->setReadDataOnly(false);
        $reader->setLoadSheetsOnly([$selectedSheetName]);

        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }

        if (method_exists($reader, 'setIncludeCharts')) {
            $reader->setIncludeCharts(false);
        }

        $spreadsheet = $reader->load($absolutePath);
        $sheet = $spreadsheet->getSheetByName($selectedSheetName);

        if (!$sheet) {
            throw new InvalidArgumentException(
                'Unable to load the selected worksheet "'.$selectedSheetName.'".'
            );
        }
        [
            $headerRow,
            $municipalityColumn,
            $barangayColumn,
            $projectCodeColumn,
            $projectBeneficiaryColumn,
        ] = $this->findHeaderRow($sheet);

        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $subHeaderRow = $headerRow + 1;
        $usesSubHeaderRow = !$this->looksLikeDataRow(
            $sheet,
            $subHeaderRow,
            $municipalityColumn,
            $barangayColumn,
            $highestColumn,
        );
        $dataStartRow = $usesSubHeaderRow ? $subHeaderRow + 1 : $subHeaderRow;

        /*
        |--------------------------------------------------------------------------
        | Undertaking/activity columns
        |--------------------------------------------------------------------------
        |
        | Do NOT assume the first TOTAL column ends the undertaking section.
        | The updated workbook places row beneficiary totals before activities on
        | Albay, while Regional Office has a blank row-total column before its
        | activities and additional TOTAL columns after them.
        |
        | Scan every labeled column after Barangay/s. buildActivityColumns()
        | excludes beneficiary TOTAL/helper columns and keeps only activities.
        |
        */
        $activityStartColumn = $barangayColumn + 1;
        $activityEndColumn = $highestColumn;

        $activityColumns = $this->buildActivityColumns(
            $sheet,
            $headerRow,
            $usesSubHeaderRow ? $subHeaderRow : null,
            $activityStartColumn,
            $activityEndColumn,
        );

        if ($activityColumns === [] && $usesSubHeaderRow) {
            $activityColumns = $this->buildActivityColumns(
                $sheet,
                $headerRow,
                null,
                $activityStartColumn,
                $activityEndColumn,
            );
        }

        if ($activityColumns === []) {
            throw new InvalidArgumentException(
                'No undertaking/activity columns were detected in the '.$sheet->getTitle().
                ' worksheet. Detected header row: '.$headerRow.
                ', Barangay column: '.Coordinate::stringFromColumnIndex($barangayColumn).'.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Optional row beneficiary total
        |--------------------------------------------------------------------------
        |
        | Albay uses a labeled TOTAL immediately after Barangay/s. Regional Office
        | uses an unlabeled numeric column E for the true row beneficiary count.
        | The later AB total in Regional Office is deliberately ignored.
        |
        */
        $totalColumn = $this->findReportedRowTotalColumn(
            $sheet,
            $headerRow,
            $dataStartRow,
            $barangayColumn,
            $highestColumn,
            array_column($activityColumns, 'column'),
        );

        $regularAggregated = [];
        $regularOrder = [];
        $groupProjects = [];
        $groupOrder = [];
        $municipalityDisplay = [];
        $sourceRows = 0;
        $currentMunicipality = '';
        $currentProjectCode = '';
        $currentProjectIsGroup = false;
        $warnings = [];
        $regularProjectKeys = [];

        $highestRow = $sheet->getHighestDataRow();

        /*
        |--------------------------------------------------------------------------
        | Precompute project-block metadata
        |--------------------------------------------------------------------------
        |
        | The supplied mapping workbook uses merged Project Code cells such as
        | A61:A120. A highlighted merged Project Code therefore represents ONE
        | Group Project, not one project per detail row.
        |
        | Precomputing these boundaries lets us jump over the full Group Project
        | block after reading its project code and project-level beneficiary total.
        |
        */
        $projectBlockEndRows = $this->buildProjectBlockEndRows(
            $sheet,
            $projectCodeColumn,
        );
        $groupProjectColors = $this->groupProjectColors();

        for ($rowNumber = $dataStartRow; $rowNumber <= $highestRow; $rowNumber++) {
            $projectCodeCell = '';

            if ($projectCodeColumn) {
                $projectCodeCell = $this->cleanText(
                    $this->cellValue($sheet->getCell([$projectCodeColumn, $rowNumber]))
                );
            }

            /*
            |------------------------------------------------------------------
            | A non-empty Project Code starts a NEW PROJECT BLOCK.
            |------------------------------------------------------------------
            |
            | When that project header is #EA9999, the entire merged/highlighted
            | section belongs to ONE Group Project. We read only:
            |
            |   - Project Code
            |   - project-level No. of Beneficiaries
            |
            | and skip all municipality/barangay/undertaking detail rows until
            | the next Project Code starts.
            |
            */
            if ($projectCodeCell !== '') {
                $currentProjectCode = $projectCodeCell;
                $currentMunicipality = '';

                $currentProjectIsGroup = $this->projectHeaderHasGroupProjectFill(
                    $sheet,
                    $rowNumber,
                    array_values(array_filter([
                        $projectCodeColumn,
                        $projectBeneficiaryColumn,
                        $municipalityColumn,
                        $barangayColumn,
                    ])),
                    $groupProjectColors,
                );

                if ($currentProjectIsGroup) {
                    $projectBlockEndRow = max(
                        $rowNumber,
                        (int) ($projectBlockEndRows[$rowNumber] ?? $rowNumber),
                    );

                    $beneficiaryTotal = $projectBeneficiaryColumn
                        ? $this->numericCount(
                            $this->cellValue($sheet->getCell([$projectBeneficiaryColumn, $rowNumber])),
                            allowZero: true,
                        )
                        : null;

                    if ($beneficiaryTotal === null) {
                        $beneficiaryTotal = 0.0;
                        $warnings[] = sprintf(
                            'Group Project %s (row %d) has no numeric project-level beneficiary total.',
                            $currentProjectCode,
                            $rowNumber,
                        );
                    }

                    $this->addGroupProject(
                        $groupProjects,
                        $groupOrder,
                        $rowNumber,
                        $projectBlockEndRow,
                        $currentProjectCode,
                        (float) $beneficiaryTotal,
                    );

                    $sourceRows += max(1, $projectBlockEndRow - $rowNumber + 1);

                    /*
                    |----------------------------------------------------------
                    | Skip the entire merged Group Project detail block.
                    |----------------------------------------------------------
                    |
                    | Example from the supplied Albay sheet:
                    |
                    |   Project Code: DILP-AL20260416-005
                    |   Beneficiaries: 100
                    |   merged Project Code block: A61:A120
                    |
                    | Rows 61-120 are one Group Project and must never enter
                    | municipality/barangay/undertaking aggregation.
                    |
                    */
                    if ($projectBlockEndRow > $rowNumber) {
                        $rowNumber = $projectBlockEndRow;
                    }

                    continue;
                } else {
                    $projectKey = $this->nameKey($currentProjectCode);
                    if ($projectKey !== '') {
                        $regularProjectKeys[$projectKey] = true;
                    }
                }
            }

            // Every detail row inside a highlighted Group Project is intentionally
            // excluded from normal municipality/barangay/undertaking processing.
            if ($currentProjectIsGroup) {
                continue;
            }

            $municipalityCell = $this->cleanText(
                $this->cellValue($sheet->getCell([$municipalityColumn, $rowNumber]))
            );
            if ($municipalityCell !== '') {
                $currentMunicipality = $this->canonicalMunicipality($province, $municipalityCell);
            }

            $barangay = $this->cleanText(
                $this->cellValue($sheet->getCell([$barangayColumn, $rowNumber]))
            );
            if (str_starts_with($barangay, '#')) {
                continue;
            }

            [$undertakings, $rowActivityTotal] = $this->extractUndertakings(
                $sheet,
                $rowNumber,
                $activityColumns,
            );

            if ($undertakings === []) {
                continue;
            }

            $sourceRows++;

            if ($totalColumn) {
                $reportedTotal = $this->numericCount(
                    $this->cellValue($sheet->getCell([$totalColumn, $rowNumber])),
                    allowZero: true,
                );

                if ($reportedTotal !== null && abs($reportedTotal - $rowActivityTotal) > 0.001) {
                    $warnings[] = sprintf(
                        'Row %d (%s%s%s): calculated undertaking beneficiaries %s do not match the worksheet total %s.',
                        $rowNumber,
                        $currentMunicipality ?: 'Unknown municipality',
                        $barangay !== '' ? ' / ' : '',
                        $barangay,
                        $this->formatNumber($rowActivityTotal),
                        $this->formatNumber($reportedTotal),
                    );
                }
            }

            if ($barangay === '' || $currentMunicipality === '') {
                continue;
            }

            $municipalityKey = $this->nameKey($currentMunicipality);
            $barangayKey = $this->nameKey($barangay);
            if ($municipalityKey === '' || $barangayKey === '') {
                continue;
            }

            $municipalityDisplay[$municipalityKey] ??= $currentMunicipality;
            $recordKey = $municipalityKey.'|'.$barangayKey;

            if (!isset($regularAggregated[$recordKey])) {
                $regularOrder[] = $recordKey;
                $regularAggregated[$recordKey] = [
                    'sort_order' => $rowNumber,
                    'municipality' => $municipalityDisplay[$municipalityKey],
                    'municipality_key' => $municipalityKey,
                    'barangay' => $barangay,
                    'barangay_key' => $barangayKey,
                    'undertakings' => [],
                    'undertaking_order' => [],
                    'source_rows' => [],
                ];
            }

            $regularAggregated[$recordKey]['municipality'] = $municipalityDisplay[$municipalityKey];
            $regularAggregated[$recordKey]['source_rows'][] = $rowNumber;
            $this->mergeUndertakings($regularAggregated[$recordKey], $undertakings);
        }

        $regularRows = $this->finalizeAggregatedRows($regularAggregated, $regularOrder, false);
        $groupRows = $this->finalizeGroupProjects($groupProjects, $groupOrder);

        if ($regularRows === [] && $groupRows === []) {
            throw new InvalidArgumentException(
                'No municipality/barangay undertaking data or #EA9999 Group Project blocks were found in the '.
                $sheet->getTitle().' worksheet.'
            );
        }

        $municipalities = [];
        foreach ($regularRows as $row) {
            $municipalities[$row['municipality_key']] ??= [
                'name' => $row['municipality'],
                'key' => $row['municipality_key'],
                'sort_order' => count($municipalities) + 1,
            ];
        }

        $groupProjectKeys = [];
        foreach ($groupRows as $row) {
            $groupProjectKeys[$row['group_project_key']] = true;
        }

        $rows = [...$regularRows, ...$groupRows];
        usort($rows, fn (array $a, array $b) => $a['sort_order'] <=> $b['sort_order']);

        return [
            'sheet_name' => $sheet->getTitle(),
            'source_rows' => $sourceRows,

            // Regular mapping totals only. Group Projects never enter these totals.
            'municipality_count' => count($municipalities),
            'barangay_count' => count($regularRows),
            'beneficiary_total' => round(array_sum(array_column($regularRows, 'beneficiary_total')), 2),
            'undertaking_total' => array_sum(array_column($regularRows, 'undertaking_count')),

            'regular_project_count' => count($regularProjectKeys),
            'group_project_count' => count($groupProjectKeys),
            'total_approved_projects' => count($regularProjectKeys) + count($groupProjectKeys),

            // One Group Project contributes exactly its project-level beneficiary count.
            'group_beneficiary_total' => round(array_sum(array_column($groupRows, 'beneficiary_total')), 2),
            'group_undertaking_total' => 0,

            'municipalities' => array_values($municipalities),
            'rows' => $rows,
            'warnings' => array_slice(array_values(array_unique($warnings)), 0, 100),
            'activity_columns' => array_column($activityColumns, 'label'),

            // Internal merge metadata. Removed by analyze() before returning.
            '_regular_project_keys' => array_keys($regularProjectKeys),
            '_group_project_keys' => array_keys($groupProjectKeys),
        ];
    }

    private function addGroupProject(
        array &$projects,
        array &$order,
        int $rowNumber,
        int $blockEndRow,
        string $projectCode,
        float $beneficiaryTotal,
    ): void {
        $projectLabel = $this->cleanText($projectCode);
        $groupProjectKey = $this->nameKey($projectLabel);

        if ($groupProjectKey === '') {
            $groupProjectKey = 'GROUP PROJECT ROW '.$rowNumber;
            $projectLabel = 'Group Project Row '.$rowNumber;
        }

        // A repeated Project Code still represents one approved Group Project.
        if (!isset($projects[$groupProjectKey])) {
            $order[] = $groupProjectKey;
            $projects[$groupProjectKey] = [
                'sort_order' => $rowNumber,
                'group_project_key' => $groupProjectKey,
                'group_project_label' => $projectLabel,
                'beneficiary_total' => max(0, $beneficiaryTotal),
                'source_rows' => [$rowNumber, $blockEndRow],
            ];

            return;
        }

        $projects[$groupProjectKey]['source_rows'][] = $rowNumber;
        $projects[$groupProjectKey]['source_rows'][] = $blockEndRow;

        // Prefer the largest explicit project-level beneficiary value if the
        // same Project Code appears again. Do not SUM repeated merged totals.
        $projects[$groupProjectKey]['beneficiary_total'] = max(
            (float) $projects[$groupProjectKey]['beneficiary_total'],
            max(0, $beneficiaryTotal),
        );
    }

    private function finalizeGroupProjects(array $projects, array $order): array
    {
        $rows = [];

        foreach ($order as $groupProjectKey) {
            $project = $projects[$groupProjectKey];
            $projectLabel = $project['group_project_label'];

            $rows[] = [
                'sort_order' => $project['sort_order'],
                'municipality' => 'Group Project',
                'municipality_key' => 'GROUP PROJECT',
                'barangay' => $projectLabel,
                'barangay_key' => $groupProjectKey,
                'beneficiary_total' => round((float) $project['beneficiary_total'], 2),
                'undertaking_count' => 0,
                'undertakings' => [],
                'source_rows' => array_values(array_unique($project['source_rows'])),
                'is_group_project' => true,
                'group_project_key' => $groupProjectKey,
                'group_project_label' => $projectLabel,
            ];
        }

        return $rows;
    }

    private function mergeUndertakings(array &$record, array $undertakings): void
    {
        foreach ($undertakings as $undertaking) {
            $activityKey = $this->nameKey($undertaking['name']);
            if ($activityKey === '') {
                continue;
            }

            if (!isset($record['undertakings'][$activityKey])) {
                $record['undertaking_order'][] = $activityKey;
                $record['undertakings'][$activityKey] = [
                    'name' => $undertaking['name'],
                    'count' => 0.0,
                ];
            }

            $record['undertakings'][$activityKey]['count'] += (float) $undertaking['count'];
        }
    }

    private function finalizeAggregatedRows(array $aggregated, array $order, bool $isGroupProject): array
    {
        $rows = [];

        foreach ($order as $recordKey) {
            $record = $aggregated[$recordKey];
            $undertakings = [];

            foreach ($record['undertaking_order'] as $activityKey) {
                $activity = $record['undertakings'][$activityKey];
                if ($activity['count'] <= 0) {
                    continue;
                }

                $activity['count'] = round($activity['count'], 2);
                $undertakings[] = $activity;
            }

            if ($undertakings === []) {
                continue;
            }

            $beneficiaryTotal = array_sum(array_column($undertakings, 'count'));

            $rows[] = [
                'sort_order' => $record['sort_order'],
                'municipality' => $record['municipality'],
                'municipality_key' => $record['municipality_key'],
                'barangay' => $record['barangay'],
                'barangay_key' => $record['barangay_key'],
                'beneficiary_total' => round($beneficiaryTotal, 2),
                'undertaking_count' => count($undertakings),
                'undertakings' => $undertakings,
                'source_rows' => array_values(array_unique($record['source_rows'])),
                'is_group_project' => $isGroupProject,
                'group_project_key' => $isGroupProject ? $record['group_project_key'] : null,
                'group_project_label' => $isGroupProject ? $record['group_project_label'] : null,
            ];
        }

        return $rows;
    }

    private function extractUndertakings(Worksheet $sheet, int $rowNumber, array $activityColumns): array
    {
        $undertakings = [];
        $rowActivityTotal = 0.0;

        foreach ($activityColumns as $column) {
            $raw = $this->cellValue($sheet->getCell([$column['column'], $rowNumber]));
            $count = $this->numericCount($raw);
            if ($count <= 0) {
                continue;
            }

            $rowActivityTotal += $count;
            $undertakings[] = [
                'name' => $column['label'],
                'count' => $count,
            ];
        }

        return [$undertakings, $rowActivityTotal];
    }

    private function groupProjectColors(): array
    {
        $colors = [];

        foreach (config('imports.group_projects.fill_colors', ['EA9999']) as $color) {
            $normalized = $this->normalizeColor((string) $color);

            if ($normalized !== '') {
                $colors[$normalized] = true;
            }
        }

        return $colors;
    }

    private function projectHeaderHasGroupProjectFill(
        Worksheet $sheet,
        int $rowNumber,
        array $columns,
        array $wantedColors,
    ): bool {
        if ($wantedColors === []) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Project-header-only style check
        |--------------------------------------------------------------------------
        |
        | We intentionally inspect only the structural cells on the first row of
        | the project block. This correctly recognizes merged #EA9999 Group
        | Projects and avoids scanning dozens of undertaking cells per row.
        |
        */
        foreach (array_values(array_unique($columns)) as $column) {
            if (!$column) {
                continue;
            }

            $fill = $sheet->getCell([$column, $rowNumber])->getStyle()->getFill();

            foreach ([
                $fill->getStartColor()->getRGB(),
                $fill->getStartColor()->getARGB(),
                $fill->getEndColor()->getRGB(),
                $fill->getEndColor()->getARGB(),
            ] as $color) {
                $normalized = $this->normalizeColor((string) $color);

                if ($normalized !== '' && isset($wantedColors[$normalized])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function buildProjectBlockEndRows(
        Worksheet $sheet,
        ?int $projectCodeColumn,
    ): array {
        if (!$projectCodeColumn) {
            return [];
        }

        $blocks = [];

        /*
        |--------------------------------------------------------------------------
        | Merged Project Code blocks
        |--------------------------------------------------------------------------
        |
        | PhpSpreadsheet exposes merged ranges such as A61:A120. When a merge
        | spans the Project Code column, map its first row to its last row.
        |
        */
        foreach ($sheet->getMergeCells() as $range) {
            try {
                [$start, $end] = Coordinate::rangeBoundaries($range);
            } catch (Throwable) {
                continue;
            }

            [$startColumn, $startRow] = $start;
            [$endColumn, $endRow] = $end;

            if (
                $projectCodeColumn < $startColumn ||
                $projectCodeColumn > $endColumn ||
                $endRow <= $startRow
            ) {
                continue;
            }

            $projectCode = $this->cleanText(
                $this->cellValue($sheet->getCell([$projectCodeColumn, $startRow]))
            );

            if ($projectCode === '') {
                continue;
            }

            $blocks[$startRow] = max(
                (int) ($blocks[$startRow] ?? $startRow),
                (int) $endRow,
            );
        }

        return $blocks;
    }

    private function normalizeColor(string $color): string
    {
        $color = strtoupper(ltrim(trim($color), '#'));

        if (strlen($color) === 8) {
            $color = substr($color, -6);
        }

        return preg_match('/^[0-9A-F]{6}$/', $color) ? $color : '';
    }

    private function resolveProvinceSheet(array $sheets, Province $province): Worksheet
    {
        $wanted = $this->nameKey($province->sheet_name ?: $province->name);

        foreach ($sheets as $sheet) {
            if ($this->nameKey($sheet->getTitle()) === $wanted) {
                return $sheet;
            }
        }

        $available = collect($sheets)
            ->map(fn (Worksheet $sheet) => $sheet->getTitle())
            ->implode(', ');

        throw new InvalidArgumentException(
            'The workbook does not contain the expected worksheet "'.($province->sheet_name ?: $province->name).'". '.
            'Available worksheets: '.$available
        );
    }

    private function findHeaderRow(Worksheet $sheet): array
    {
        $maxRow = min(15, $sheet->getHighestDataRow());
        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($row = 1; $row <= $maxRow; $row++) {
            $projectCodeColumn = null;
            $projectBeneficiaryColumn = null;
            $municipalityColumn = null;
            $barangayColumn = null;

            for ($column = 1; $column <= $highestColumn; $column++) {
                $header = $this->headerKey($this->cellValue($sheet->getCell([$column, $row])));
                if ($header === '') {
                    continue;
                }

                if (
                    $header === 'code' ||
                    $this->matchesHeaderAlias($header, config('imports.header_aliases.project_code', []))
                ) {
                    $projectCodeColumn = $column;
                }
                if ($this->matchesHeaderAlias($header, config('imports.header_aliases.project_beneficiaries', []))) {
                    $projectBeneficiaryColumn = $column;
                }
                if ($this->matchesHeaderAlias($header, config('imports.header_aliases.municipality', []))) {
                    $municipalityColumn = $column;
                }
                if ($this->matchesHeaderAlias($header, config('imports.header_aliases.barangay', []))) {
                    $barangayColumn = $column;
                }
            }

            if ($municipalityColumn && $barangayColumn) {
                // In the supplied workbooks the project beneficiary column is
                // immediately after Project Code. Keep that as a safe fallback.
                if (!$projectBeneficiaryColumn && $projectCodeColumn) {
                    $projectBeneficiaryColumn = $projectCodeColumn + 1;
                }

                return [
                    $row,
                    $municipalityColumn,
                    $barangayColumn,
                    $projectCodeColumn,
                    $projectBeneficiaryColumn,
                ];
            }
        }

        throw new InvalidArgumentException(
            'Could not find Municipality and Barangay/s headers in the selected provincial worksheet.'
        );
    }

    private function looksLikeDataRow(
        Worksheet $sheet,
        int $row,
        int $municipalityColumn,
        int $barangayColumn,
        int $highestColumn,
    ): bool {
        if (
            $this->cleanText($this->cellValue($sheet->getCell([$municipalityColumn, $row]))) !== '' ||
            $this->cleanText($this->cellValue($sheet->getCell([$barangayColumn, $row]))) !== ''
        ) {
            return true;
        }

        for ($column = $barangayColumn + 1; $column <= $highestColumn; $column++) {
            $value = $this->cellValue($sheet->getCell([$column, $row]));
            if (is_numeric($value)) {
                return true;
            }
        }

        return false;
    }

    private function findReportedRowTotalColumn(
        Worksheet $sheet,
        int $headerRow,
        int $dataStartRow,
        int $barangayColumn,
        int $highestColumn,
        array $activityColumnIndexes,
    ): ?int {
        /*
        |--------------------------------------------------------------------------
        | Find the REAL per-row beneficiary total without reading helper totals
        |--------------------------------------------------------------------------
        |
        | Albay:
        |   D = Barangay/s
        |   E = TOTAL (Beneficiaries)
        |   F = blank separator
        |   G... = activities
        |
        | Regional Office:
        |   D = Barangay/s
        |   E = unlabeled beneficiary count
        |   F...AA = activities
        |   AB/AC = helper/project totals (must NOT be used here)
        |
        | We therefore only inspect NON-ACTIVITY columns between Barangay/s and
        | the first undertaking column. This prevents the later AB/AC totals in
        | Regional Office from being mistaken for the row beneficiary count.
        |
        */
        $activityColumnIndexes = array_values(array_filter(array_map('intval', $activityColumnIndexes)));
        $firstActivityColumn = $activityColumnIndexes !== []
            ? min($activityColumnIndexes)
            : ($highestColumn + 1);

        $candidateStart = $barangayColumn + 1;
        $candidateEnd = min($highestColumn, $firstActivityColumn - 1);

        if ($candidateEnd < $candidateStart) {
            return null;
        }

        // Prefer an explicitly labeled beneficiary TOTAL (Albay column E).
        for ($column = $candidateStart; $column <= $candidateEnd; $column++) {
            $header = $this->headerKey(
                $this->cellValue($sheet->getCell([$column, $headerRow]))
            );

            if (
                $header !== '' &&
                str_contains($header, 'total') &&
                str_contains($header, 'benef')
            ) {
                return $column;
            }

            if ($this->matchesHeaderAlias(
                $header,
                config('imports.header_aliases.per_barangay_total', []),
            )) {
                return $column;
            }
        }

        // Regional Office column E is intentionally unlabeled. Detect the first
        // pre-activity helper column that actually contains numeric row counts.
        $sampleEndRow = min($sheet->getHighestDataRow(), $dataStartRow + 30);

        for ($column = $candidateStart; $column <= $candidateEnd; $column++) {
            $numericSamples = 0;

            for ($row = $dataStartRow; $row <= $sampleEndRow; $row++) {
                $value = $this->numericCount(
                    $this->cellValue($sheet->getCell([$column, $row])),
                    allowZero: true,
                );

                if ($value !== null) {
                    $numericSamples++;

                    if ($numericSamples >= 2) {
                        return $column;
                    }
                }
            }
        }

        return null;
    }

    private function findFirstTotalColumn(
        Worksheet $sheet,
        int $headerRow,
        int $startColumn,
        int $highestColumn,
    ): ?int {
        for ($column = $startColumn; $column <= $highestColumn; $column++) {
            $header = $this->headerKey($this->cellValue($sheet->getCell([$column, $headerRow])));
            if (str_contains($header, 'total') && str_contains($header, 'benef')) {
                return $column;
            }
        }

        return null;
    }

    private function buildActivityColumns(
        Worksheet $sheet,
        int $headerRow,
        ?int $subHeaderRow,
        int $startColumn,
        int $endColumn,
    ): array {
        $columns = [];
        $lastBaseHeader = '';

        for ($column = $startColumn; $column <= $endColumn; $column++) {
            $baseHeader = $this->cleanText($this->cellValue($sheet->getCell([$column, $headerRow])));
            $subHeader = $subHeaderRow
                ? $this->cleanText($this->cellValue($sheet->getCell([$column, $subHeaderRow])))
                : '';

            if ($baseHeader === '' && $subHeader !== '') {
                $baseHeader = $lastBaseHeader;
            }
            if ($baseHeader !== '') {
                $lastBaseHeader = $baseHeader;
            }

            if ($baseHeader === '') {
                continue;
            }

            if ($this->matchesHeaderAlias(
                $this->headerKey($baseHeader),
                config('imports.header_aliases.per_barangay_total', []),
            )) {
                continue;
            }

            $label = $baseHeader;
            if ($subHeader !== '') {
                $label .= ' - '.$subHeader;
            }
            $label = $this->cleanText($label);

            if ($label === '' || str_contains($this->headerKey($label), 'totalbenef')) {
                continue;
            }

            $columns[] = [
                'column' => $column,
                'label' => $label,
            ];
        }

        return $columns;
    }

    private function canonicalMunicipality(Province $province, string $value): string
    {
        $clean = $this->cleanText($value);
        $key = $this->nameKey($clean);

        foreach (config('imports.municipality_aliases.'.$province->name, []) as $from => $to) {
            if ($this->nameKey($from) === $key) {
                return $this->cleanText($to);
            }
        }

        return $clean;
    }

    private function cellValue(Cell $cell): mixed
    {
        if (!$cell->isFormula()) {
            return $cell->getValue();
        }

        // Preserve the cached value written by Excel when available. This keeps
        // style-aware loading compatible with formula-based totals/counts.
        $cached = $cell->getOldCalculatedValue();
        if ($cached !== null && $cached !== '') {
            return $cached;
        }

        try {
            return $cell->getCalculatedValue();
        } catch (Throwable) {
            return $cell->getValue();
        }
    }

    private function numericCount(mixed $value, bool $allowZero = false): float|null
    {
        if ($value === null || $value === '') {
            return $allowZero ? null : 0.0;
        }

        if (!is_numeric($value)) {
            return $allowZero ? null : 0.0;
        }

        $number = (float) $value;
        if ($number < 0 || (!$allowZero && $number <= 0)) {
            return $allowZero ? null : 0.0;
        }

        return $number;
    }

    private function matchesHeaderAlias(string $header, array $aliases): bool
    {
        foreach ($aliases as $alias) {
            if ($header === $this->headerKey($alias)) {
                return true;
            }
        }

        return false;
    }

    private function headerKey(mixed $value): string
    {
        return Str::of($this->cleanText($value))
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->toString();
    }

    public function nameKey(mixed $value): string
    {
        return Str::of($this->cleanText($value))
            ->ascii()
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', ' ')
            ->trim()
            ->toString();
    }

    private function cleanText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return Str::of((string) $value)
            ->replace(["\r", "\n", "\t"], ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }

    private function formatNumber(float $value): string
    {
        return fmod($value, 1.0) === 0.0
            ? number_format($value, 0)
            : number_format($value, 2);
    }
}
