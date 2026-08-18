# DILP Multi-Province Safe Sync Overlay

This overlay changes `MiroBoardAdoptionService` so importing one province does not remove or take over Miro items belonging to another province on the same board.

## What changed

- Removes destructive legacy panel cleanup from board adoption.
- Removes destructive legacy connector cleanup from board adoption.
- Stops deleting an old generated municipality anchor when a permanent label is discovered.
- Excludes Miro items already tracked to another province from the current province's adoption candidates.
- Keeps adoption/update logic scoped to the currently selected province.

## Expected behavior

- Import Camarines Norte -> Camarines Norte remains on the board.
- Import Camarines Sur -> only Camarines Sur is created/updated; Camarines Norte remains untouched.
- Re-import a province -> only that province's tracked data is updated by normal synchronization.
- Existing items belonging to other provinces are not deleted, cleared, or re-adopted by this service.

## Install

Extract the ZIP over the Laravel project root and allow the file to overwrite:

`app/Services/MiroBoardAdoptionService.php`

Then run:

```powershell
php artisan optimize:clear
```

Restart the development server if needed:

```powershell
php artisan serve
```

No migration is required.
