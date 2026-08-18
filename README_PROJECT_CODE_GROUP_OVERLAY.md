# DILP Group Project Project-Code Overlay

This overlay changes only the #EA9999 Group Project presentation.

## Miro behavior

Each unique #EA9999 Group Project / Project Code becomes one separate pink box.

The pink box format is:

PROJECT CODE

Beneficiaries: N
Undertakings: N

Undertaking Name - beneficiary count
Undertaking Name - beneficiary count

Rows sharing the same project code are combined into the same pink box. Undertaking counts with the same undertaking name are summed. Group Projects remain excluded from normal municipality, barangay, regular beneficiary, and regular undertaking totals.

## Install

Extract this ZIP directly over the current Laravel project and allow the files to be replaced.

Then run:

```powershell
php artisan optimize:clear
php artisan serve
```

No new migration is required for this overlay.

Re-analyze/re-import the province so the latest workbook data is used, then synchronize it to Miro. Old extra pink panel parts from the previous layout are removed by the existing synchronization cleanup because the new layout keeps only one stable pink panel per project code.
