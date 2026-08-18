# DILP Group Project Merged-Block Revision Overlay

This overlay is intended to be extracted over the latest DILP Miro Mapping Laravel project after the Exact Reference Layout and Miro Performance overlays.

## What this revision fixes

The latest workbook uses a project-level merged cell/block for Group Projects. The parser now treats a highlighted Project Code block as ONE project rather than processing its municipality/barangay detail rows.

For the supplied Albay worksheet, the expected result is:

- Project Code: `DILP-AL20260416-005`
- Project-level beneficiaries: `100`
- Group Project count contribution: `1`
- Group beneficiary contribution: `100`
- Miro output: one yellow Group Project box only
- The Group Project's detail rows do not enter regular municipality, barangay, undertaking, or individual-beneficiary totals.

The parser also uses the merged Project Code range to jump over the whole project block, which reduces unnecessary workbook processing.

## Install

1. Stop `php artisan serve`.
2. Extract this ZIP directly over the current Laravel project.
3. Allow replacement of `app/Services/SpreadsheetImportService.php`.
4. Run:

```powershell
php artisan optimize:clear
php artisan serve
```

No migration is required.

## Important after installation

Create a NEW analysis/import using the latest workbook. Do not reuse an old preview/import batch, because old `import_rows` were already saved using the previous parser result.

For Albay, the new preview should show:

- Group Projects: `1`
- Group Beneficiaries: `100`
- Group Project: `DILP-AL20260416-005`

After synchronization, Miro should create/update one yellow box equivalent to:

```text
DILP-AL20260416-005

100 Beneficiaries
```

No Group Project undertaking list should be shown in that yellow box.
