# DILP Group Project Overlay

This overlay adds Group Project handling to the existing DILP Provincial Mapping Importer.

## New behavior

- Excel rows containing fill color `#EA9999` are classified as **Group Projects**.
- Group Project rows are **excluded** from normal:
  - municipality totals
  - barangay totals
  - regular beneficiary totals
  - regular undertaking totals
- Each Group Project is generated as a separate **pink Miro box** using fill `#EA9999`.
- Normal municipality/barangay data remains in green boxes.
- Pink Group Project boxes are kept separate from municipality green-box connectors.
- The overlay also includes the latest connector fix so normal green boxes point back to the municipality/map anchor.
- Existing generated pink boxes are updated on re-import; obsolete generated pink boxes are removed.

## Install

Extract this ZIP directly over the current Laravel project and allow Windows to replace matching files.

Then run:

```powershell
php artisan migrate
php artisan optimize:clear
php artisan serve
```

Open the system with:

```text
http://localhost:8000
```

## Important

The spreadsheet reader now loads workbook styles because Group Project detection depends on the Excel fill color.

The marker is configured in:

```text
config/imports.php
```

```php
'group_projects' => [
    'fill_colors' => [
        'EA9999',
    ],
],
```

If another fill color is later confirmed as a Group Project marker, add its 6-digit RGB value to that array.
