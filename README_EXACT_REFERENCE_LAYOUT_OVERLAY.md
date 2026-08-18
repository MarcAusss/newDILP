# DILP Miro Exact Reference Layout Overlay

Apply this ZIP over the current Laravel DILP Provincial Mapping Importer.

## What this overlay changes

### Normal projects
- Continue to generate green municipality boxes.
- Barangays are bold headings.
- Undertakings appear as `Undertaking - beneficiary count`.
- Red connectors point from the generated green boxes back to the municipality pin/label on the provincial map.

### #EA9999 Group Projects
- `#EA9999` is an Excel marker only.
- Detection happens once when a new Project Code block starts instead of checking every undertaking cell on every row.
- The ENTIRE highlighted project block is treated as one Group Project.
- Municipality, barangay, and undertaking detail rows inside that block are skipped from the normal map/totals.
- Project Code is read once from the project header/merged Project Code cell.
- Beneficiaries are read once from the project-level `No. of Beneficiaries` column.
- One highlighted Project Code = exactly ONE Miro box.
- The Miro Group Project box is YELLOW (`#FFD966`), not pink.
- The yellow box shows only:

  `PROJECT CODE`

  `XX Beneficiaries`

- No Group Project undertakings are shown.
- Repeated detail rows do not create duplicate Group Project boxes.

Example:

`DILP-SO20260525-006` + `99` beneficiaries becomes ONE yellow box:

```text
DILP-SO20260525-006

99 Beneficiaries
```

### Summary panel
The importer continues to generate the reference-style summary cluster:
- Top 3 Projects
- All regular undertaking totals
- Municipalities with highest regular assistance
- Municipalities with least regular assistance
- Total Number of Beneficiaries: Individual / Group
- Group Projects Awarded
- Total Approved Projects

Group Project detail rows do not affect regular municipality, barangay, undertaking, or individual-beneficiary totals.

## Install

Extract this ZIP directly over the existing Laravel project and allow file replacement.

Then run:

```powershell
php artisan optimize:clear
php artisan serve
```

No migration is required for this overlay.

## Important

Re-analyze the Excel workbook after applying this overlay. Existing import preview records were created using the previous parser and should not be reused for the corrected Group Project behavior.
