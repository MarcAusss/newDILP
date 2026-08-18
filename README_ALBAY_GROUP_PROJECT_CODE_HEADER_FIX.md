# Albay Group Project CODE Header Fix

This overlay fixes Group Project detection for the current 2026 workbook.

## Root cause

The provincial worksheets use the header `CODE` in column A. The previous importer looked for aliases such as `Project Code` but did not include plain `CODE`, so `projectCodeColumn` remained null. Because of that, the #EA9999 project block `DILP-AL20260416-005` was never classified as a Group Project and its detail rows entered the normal green municipality panels.

## Changes

- Adds `code` to `config/imports.php` under `header_aliases.project_code`.
- Adds a defensive direct `CODE` check in `SpreadsheetImportService::findHeaderRow()` so Group Project detection still works even if the alias list is accidentally changed later.
- Keeps merged-block handling: one highlighted project code block = one Group Project.
- `DILP-AL20260416-005` should be classified as one Group Project with 100 beneficiaries.
- Its detail rows are excluded from normal municipality/barangay/undertaking aggregation.

## Install

Extract over the current Laravel project and allow replacement of:

- `app/Services/SpreadsheetImportService.php`
- `config/imports.php`

Then run:

```powershell
php artisan optimize:clear
php artisan serve
```

Create a NEW Albay analysis using the current spreadsheet. Do not reuse an old analyzed import batch.

Expected result:

- Group Projects: 1
- Group Beneficiaries: 100
- One yellow Miro box for `DILP-AL20260416-005`
