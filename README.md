# Provincial Miro Mapping Importer

Laravel 12 website for importing the **2026 regional mapping workbook one province at a time** and generating the green rounded data boxes and red arrow connectors used in the supplied Miro mapping example.

The provincial map image and municipality labels stay in Miro. The website manages the generated municipality anchor dots, green barangay/undertaking boxes, and connectors.

## What this build understands

The importer is designed around the uploaded workbook structure:

```text
EXTRACTED DATA for Mapping 2026.xlsx
├─ Albay
├─ Camarines Norte
├─ Camarines Sur
├─ Catanduanes
├─ Masbate
├─ Regional Office
├─ Sorsogon
└─ SUMMARY ... worksheets
```

When you select a province, only its raw provincial worksheet is read. `Regional Office` and all `SUMMARY ...` worksheets are ignored.

The raw provincial worksheet is interpreted as:

```text
Project Code | No. of Beneficiaries | Municipality | Barangay/s | undertaking columns... | TOTAL...
```

The parser also handles:

- municipality cells that are blank because the value is visually merged/carried down in Excel
- undertaking columns that differ from province to province
- multi-row undertaking headings such as `Sewing - Manual`, `Sewing - Highspeed`, `Baking - Basic`, and `Baking - Advance`
- Masbate's `No. of Bene per barangay` support column without treating it as an undertaking
- repeated barangays across multiple project rows by aggregating their undertaking counts
- obvious Albay municipality naming variants configured in `config/imports.php`

## Generated Miro format

Each municipality can have one or more green rounded boxes. Content follows the reference format:

```text
Malosbolos
Street Food Vending - 1

Marayag
Food Vending (Kakanin) - 1
Nail Care Services - 1

Sta. Cruz
Fish Vending - 2
Fishing - 3
Food Vending (Cooked Viand) - 6
Street Food Vending - 2
```

Barangay names are bold. Undertakings are shown as `Undertaking - beneficiary count`.

If one municipality contains too much content for one green box, the website automatically splits it into multiple boxes and connects them in sequence.

## Main features

- Import **one selected province at a time** from the same regional XLSX/XLS workbook
- Preview data before touching Miro
- Municipality → Barangay → Undertaking aggregation
- Dynamic undertaking-column detection
- Beneficiary totals and undertaking-entry counts in the preview
- Miro OAuth 2.0 connection and refresh-token support
- Accept either a Miro board ID or a full Miro board URL
- Green `round_rectangle` data panels
- Red municipality anchor dots
- Red elbowed arrow connectors
- Automatic multi-panel splitting for long municipalities
- Stable generated-item keys to prevent duplicate boxes on re-import
- Existing generated shapes are updated without resetting their Miro positions
- New panels added on later imports are positioned relative to the previous existing panel
- Obsolete items are removed only when they were previously created and recorded by this website for the same province
- Import history and error status
- Province configuration for Albay, Camarines Norte, Camarines Sur, Catanduanes, Masbate, and Sorsogon
- Optional per-municipality X/Y placement configuration
- Static CSS/JS: no Node/Vite build is required

## Requirements

- PHP 8.2+
- Composer
- MySQL 8+ or compatible MariaDB
- PHP extensions required by Laravel/PhpSpreadsheet, especially `zip`, `xml`, `mbstring`; `gd` is recommended
- Miro account
- Miro developer app with at least `boards:read` and `boards:write`

## Installation on Windows

### 1. Extract the ZIP

Example location:

```text
C:\laravel\miro-provincial-importer
```

### 2. Install dependencies

```powershell
cd C:\laravel\miro-provincial-importer
composer install
```

### 3. Create `.env`

```powershell
copy .env.example .env
php artisan key:generate
```

### 4. Create the database

```sql
CREATE DATABASE miro_provincial_importer
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;
```

Configure `.env`:

```env
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=miro_provincial_importer
DB_USERNAME=root
DB_PASSWORD=YOUR_MYSQL_PASSWORD

MIRO_CLIENT_ID=
MIRO_CLIENT_SECRET=
MIRO_REDIRECT_URI=http://127.0.0.1:8000/miro/callback

IMPORT_MAX_FILE_MB=20
```

Use the same host consistently. If `APP_URL` uses `127.0.0.1`, use `127.0.0.1` in the Miro redirect URI as well.

### 5. Create tables and province seed data

```powershell
php artisan migrate --seed
```

### 6. Run the website

```powershell
php artisan serve
```

Open:

```text
http://127.0.0.1:8000
```

## Miro developer-app setup

1. Create a Miro developer app.
2. Enable the `boards:read` and `boards:write` scopes.
3. Add the exact redirect URI used by the Laravel website:

```text
http://127.0.0.1:8000/miro/callback
```

4. Copy the Client ID and Client Secret into `.env`.
5. Run:

```powershell
php artisan optimize:clear
```

6. Open the website → **Miro Settings** → **Connect Miro**.
7. Authorize the Miro team containing the mapping board.

Official references:

- https://developers.miro.com/docs/getting-started-with-oauth
- https://developers.miro.com/reference/create-shape-item-1
- https://developers.miro.com/reference/create-connector-1

## Configure a province

Open **Province Mapping**.

For the province you want to import:

1. Confirm the worksheet name, e.g. `Albay`.
2. Paste either:
   - the board ID, e.g. `uXjVGT09eQ4=`; or
   - the full Miro board URL.
3. Leave **Miro Frame ID** blank unless you specifically want generated items attached to a known frame.
4. Leave the staging X/Y defaults initially unless you already know the desired coordinates.
5. Save.

The website extracts the board ID automatically when a full Miro URL is pasted.

## First import workflow

1. Open **Import Province**.
2. Select one province, for example `ALBAY`.
3. Upload `EXTRACTED DATA for Mapping 2026.xlsx`.
4. Click **Analyze Workbook**.
5. Review the detected municipalities, barangays, undertaking entries, beneficiary counts, and warnings.
6. Click **Import Green Boxes to Miro**.

On the first import, the generated municipality groups are placed in a staging arrangement because the website does not know the exact coordinates of every municipality on your existing map.

In Miro, arrange each municipality once:

- drag the small red anchor dot onto/next to the municipality label on the map
- drag the first green box to the desired position
- arrange any additional green boxes as needed

After that, **do not delete and recreate those generated items manually**. Re-imports update their content while preserving their current Miro positions.

## Optional exact placement before first sync

After analyzing a workbook, open:

```text
Province Mapping → Municipality Placement
```

For each municipality you can configure:

- anchor X / Y
- first green box X / Y
- panel flow: Right, Left, Down, or Up

This is optional. Manual drag-and-place in Miro is usually easier for an existing illustrated provincial map.

## Re-import behavior

For each province, generated items are tracked by stable keys in `generated_miro_items`.

On a later import of the same province:

- existing green boxes are updated instead of duplicated
- existing red anchors keep their positions
- existing panels keep their positions
- new panels are created relative to the previous panel
- obsolete generated connectors/panels are removed
- other provinces are not modified
- arbitrary Miro items that were not created/recorded by this website are not deleted

## Where to adjust the mapping appearance

```text
config/imports.php
app/Services/MiroLayoutService.php
app/Services/MiroSyncService.php
```

Important values in `config/imports.php` include:

- green box color
- red connector color
- panel width/height
- maximum lines per panel
- panel gaps
- anchor size

## Workbook parser implementation

The core parser is:

```text
app/Services/SpreadsheetImportService.php
```

It finds `Municipality` and `Barangay/s`, dynamically determines the undertaking range, stops before the first total-beneficiary column, then aggregates all positive numeric undertaking cells.

See `WORKBOOK_FORMAT.md` for the detailed parsing rules.

## Production note

This build is intended as an internal/single-user mapping utility and intentionally does not include authentication for the Laravel website itself.

Before exposing it to the public internet, add application login/authorization, HTTPS, production access controls, backups, and normal Laravel production hardening.

For production:

```env
APP_ENV=production
APP_DEBUG=false
```

## Troubleshooting

### Miro redirect URI mismatch

The URI configured in the Miro developer app must exactly match `MIRO_REDIRECT_URI`, including hostname, port, scheme, and trailing slash behavior.

### Board validation failed

Check that:

- Miro is connected in **Miro Settings**
- the app has `boards:read`
- the authorized Miro user/team can access the board
- the correct board URL or ID was saved

### Workbook worksheet not found

Check the province's **Workbook worksheet name** in Province Mapping. It must match the raw provincial sheet, such as `Camarines Sur`, not `SUMMARY CAMARINES SUR`.

### No undertaking columns detected

The importer expects undertaking columns after `Barangay/s` and before the first header containing both `TOTAL` and `BENEF...`.

### Upload too large

Increase both:

```env
IMPORT_MAX_FILE_MB=20
```

and the relevant PHP `upload_max_filesize` / `post_max_size` values if necessary.
#   n e w D I L P  
 #   n e w D I L P  
 