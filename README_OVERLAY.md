# Miro Provincial Importer — New Board Replacement Overlay

This is an **overlay only**, not a complete Laravel project.

Copy the contents of this ZIP over the existing `miro-provincial-importer` project and allow Windows to replace matching files.

## What this overlay changes

- Sets the Albay target board to `https://miro.com/app/board/uXjVHxVb-XA=/` through a migration.
- Treats the existing provincial map and municipality labels as permanent Miro content.
- On the first sync to the new board, scans existing Miro shapes and detects the old green mapping boxes.
- Reuses an existing green box when it can associate it with a municipality and replaces its old text with spreadsheet data.
- Removes unused old green boxes so stale data is not left on the board.
- Rebuilds red mapping arrows.
- Detects municipality labels already on the Miro map and connects arrows directly to those Miro items when possible.
- If no green box exists for a municipality, automatically creates a new green box outward from the map and connects it with a red arrow.
- If the configured Miro board changes, old local Miro item tracking is detached automatically so IDs from the previous board are not reused.

## Apply the overlay

From the Laravel project directory after copying/replacing the files:

```powershell
php artisan migrate
php artisan optimize:clear
```

Then run the site normally:

```powershell
php artisan serve
```

or use your existing development command if you already run the project another way.

## Important first-import behavior

The first import to the new board performs legacy cleanup. It detects large green rectangle/rounded-rectangle shapes as mapping panels. Old red connectors associated with the mapping are also removed before the fresh connector chain is generated.

This is intended for the dedicated provincial mapping board you supplied. If the board contains unrelated red arrows that must not be removed, change:

`config/imports.php`

```php
'delete_red_legacy_connectors' => false,
```

before the first import.

## Normal workflow

1. Connect Miro in the website Settings page.
2. Confirm Albay points to the new board. The migration already sets the Albay board ID to `uXjVHxVb-XA=`.
3. Upload `EXTRACTED DATA for Mapping 2026.xlsx`.
4. Select **Albay**.
5. Analyze the workbook.
6. Review the preview.
7. Click **Replace Green Boxes in Miro**.

The importer only processes the selected province worksheet.


See also `README_FULL_OVERLAY.md` for the consolidated mapping overlay instructions.
