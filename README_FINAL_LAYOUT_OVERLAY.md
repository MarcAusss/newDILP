# DILP Final Miro Layout Overlay

This overlay applies the final provincial Miro output rules from the supplied reference diagram.

## What changes

- Normal spreadsheet rows remain municipality -> barangay -> undertaking green boxes.
- Green boxes connect back to the detected municipality pin/label on the provincial map using red arrow connectors.
- Excel rows highlighted `#EA9999` remain Group Projects and are excluded from normal municipality/barangay/individual totals.
- Each unique Group Project code becomes one compact pink box showing only:
  - Project Code
  - Number of Beneficiaries
- Group Project undertaking names are NOT shown in Miro.
- A generated provincial summary area is added after the map and Group Project boxes:
  - Top 3 Projects / undertakings by individual beneficiaries
  - Full regular undertaking totals
  - Municipalities with highest regular beneficiary totals
  - Municipalities with least regular beneficiary totals, including zero municipalities
  - Total Number of Beneficiaries: Individual / Group
  - Group Projects Awarded
  - Total Approved Projects
- Re-importing the province updates the generated boxes and removes obsolete generated items without deleting the base provincial map or permanent municipality labels.

## Install

Extract this ZIP directly over the current Laravel project and allow files to be replaced.

Then run:

```powershell
php artisan migrate
php artisan optimize:clear
php artisan serve
```

Open:

```text
http://localhost:8000
```

Re-analyze the workbook before re-importing so the new project-count fields are populated.

## Important

The summary year defaults to 2026. It can be changed with:

```env
IMPORT_SUMMARY_YEAR=2026
```

Do not delete the base Miro provincial map. The importer manages only its generated green boxes, Group Project pink boxes, red connectors, and summary boxes.
