# Supported 2026 Workbook Format

The importer is customized for the regional workbook pattern supplied for this project.

## Provincial worksheets

Expected raw worksheet names seeded by default:

```text
Albay
Camarines Norte
Camarines Sur
Catanduanes
Masbate
Sorsogon
```

The selected province controls which one worksheet is read. Summary worksheets are not used.

## Header detection

The parser scans the first 15 rows until it finds both:

```text
Municipality / Municipalities
Barangay / Barangays / Barangay/s
```

This supports the observed capitalization differences between provincial sheets.

## Optional second header row

If the row after the main header does not look like a data row, it is treated as a subheader row.

Examples:

```text
Sewing
├─ Manual
└─ Highspeed
```

becomes:

```text
Sewing - Manual
Sewing - Highspeed
```

and:

```text
Baking
├─ Basic
└─ Advance
```

becomes:

```text
Baking - Basic
Baking - Advance
```

## Municipality inheritance

Rows commonly look like:

```text
Esperanza | Magsaysay
          | Poblacion
          | Baras
```

The blank municipality cells inherit the most recent non-empty municipality, producing:

```text
Esperanza / Magsaysay
Esperanza / Poblacion
Esperanza / Baras
```

## Undertaking columns

The importer starts immediately after `Barangay/s` and stops immediately before the first main header containing both `total` and `benef`.

All positive numeric cells in the undertaking range are interpreted as beneficiary counts for that undertaking.

Masbate's support column:

```text
No. of Bene per barangay
```

is skipped and is not displayed as an undertaking.

## Aggregation

If the same municipality/barangay occurs on more than one project row, undertaking values are added together.

Example input concept:

```text
Municipality | Barangay | Fishing | Food Vending
Esperanza    | Poblacion| 3       | 10
Esperanza    | Poblacion| 2       | 4
```

Generated data:

```text
Poblacion
Fishing - 5
Food Vending - 14
```

## Zero/blank values

Blank, zero, negative, or non-numeric undertaking cells are not shown.

A municipality/barangay aggregation with no positive undertaking values is omitted from the generated green-box data.

## Validation warnings

When a worksheet contains a detected per-barangay `TOTAL (Beneficiary)` column, the importer compares the sum of detected undertaking cells against that row's reported total.

Differences are shown as preview warnings so the user can review inconsistent spreadsheet rows before syncing to Miro.
