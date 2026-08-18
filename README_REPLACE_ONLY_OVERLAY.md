# DILP Miro Replace-Only Synchronization Overlay

This overlay changes normal province re-import behavior so generated Miro items are **not deleted**.

## Re-import rules

- Existing active green boxes: update/replace content in the same Miro item.
- Existing active yellow Group Project boxes: update/replace content in the same Miro item.
- Existing summary boxes: update/replace content in the same Miro item.
- Missing required items: create once, then track and reuse them.
- Previously generated box no longer needed: keep the box and clear its visible text.
- Municipality anchors/pins: never delete during normal synchronization.
- Connectors: never delete during normal synchronization; they remain attached to preserved boxes/anchors.

This keeps manually arranged Miro layouts stable across repeated imports.

## Installation

Extract this ZIP directly over the current Laravel project and allow the existing file to be replaced.

Then run:

```powershell
php artisan optimize:clear
php artisan serve
```

No database migration is required.

## Important

Do not delete `generated_miro_items`. Those records are how the importer reuses the same Miro items instead of duplicating them.
