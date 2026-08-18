# DILP Provincial Mapping Importer — Full Overlay

This ZIP is an overlay for the existing Laravel project. Extract it into the Laravel project root and allow Windows to replace files.

It consolidates the current changes:

- new Albay target-board overlay behavior
- preservation of the base provincial map
- replacement/adoption of existing green data boxes
- creation of missing green boxes during import
- red connector generation
- non-expiring Miro OAuth token handling fix
- Albay municipality Mapping Setup scanner
- read-only detection of existing municipality text/shape items and green boxes
- manual coordinate correction UI

## Install

From the Laravel project root after extracting the overlay:

```powershell
php artisan migrate
php artisan optimize:clear
php artisan serve
```

Use the application through:

```text
http://localhost:8000
```

## Mapping Setup

1. Confirm Miro is connected in **Miro Settings**.
2. Confirm **Albay** points to board ID `uXjVHxVb-XA=` in **Province Mapping**.
3. Open **Albay → Municipality Mapping**.
4. Click **Scan Miro Board**.
5. Review which municipality labels were found and which green boxes were detected.
6. Correct coordinates manually if needed and save.

The scan itself does not modify Miro board items. Actual box replacement/creation occurs during spreadsheet import.

## Important

If municipality names are baked into the provincial map image rather than separate Miro text/shape items, Miro's REST API cannot discover those label coordinates from the image automatically. Those rows will show **Missing** and should be positioned manually using the mapping coordinates before import.
