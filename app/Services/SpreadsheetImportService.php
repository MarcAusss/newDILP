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
        $reader = IOFactory::createReaderForFile($absolutePath);

        /*
        |--------------------------------------------------------------------------
        | Style-aware loading
        |--------------------------------------------------------------------------
        |
        | #EA9999 is only used to classify an entire PROJECT block as a Group
        | Project. We do not scan every styled cell anymore. That avoids the
        | row-by-row style traversal that caused very long imports/timeouts.
        |
        */
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($absolutePath);

        $sheet = $this->resolveProvinceSheet($spreadsheet->getAllSheets(), $province);
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

        $totalColumn = $this->findFirstTotalColumn(
            $sheet,
            $headerRow,
            $barangayColumn + 1,
            $highestColumn,
        );
        $activityEndColumn = $totalColumn ? $totalColumn - 1 : $highestColumn;
        $activityColumns = $this->buildActivityColumns(
            $sheet,
            $headerRow,
            $usesSubHeaderRow ? $subHeaderRow : null,
            $barangayColumn + 1,
            $activityEndColumn,
        );

        if ($activityColumns === []) {
            throw new InvalidArgumentException(
                'No undertaking/activity columns were detected in the '.$sheet->getTitle().' worksheet.'
            );
        }

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
