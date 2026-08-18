# Architecture

## Data flow

```text
EXTRACTED DATA for Mapping 2026.xlsx
            |
            | user selects ONE province
            v
ImportController
            |
            v
SpreadsheetImportService
            |
            +-- selects matching raw worksheet only
            +-- finds Municipality + Barangay/s headers
            +-- detects optional second header row
            +-- carries merged/blank Municipality cells downward
            +-- detects undertaking columns dynamically
            +-- skips support columns such as Masbate per-barangay total
            +-- stops before TOTAL beneficiary columns
            +-- aggregates Municipality -> Barangay -> Undertaking -> count
            |
            v
ImportBatch + ImportRows (MySQL)
            |
            v
Preview UI
            |
            v
MunicipalityMappingService
            |
            +-- creates placement records without overwriting prior placement
            |
            v
MiroSyncService
      |                 |
      |                 +--> MiroService
      |                        OAuth / refresh token
      |                        board validation
      |                        shape CRUD
      |                        connector CRUD
      |
      +--> MiroLayoutService
             |
             +-- one small red anchor per municipality
             +-- chunks barangay blocks into green round rectangles
             +-- bold barangay names
             +-- Undertaking - count lines
             +-- anchor -> panel -> panel red connectors
            |
            v
GeneratedMiroItem tracking (MySQL)
```

## Miro ownership boundary

The existing provincial map, map image, municipality labels, and unrelated Miro content remain user-managed.

The website only tracks and mutates item IDs stored in `generated_miro_items` for the selected province.

```text
Existing Miro map          Laravel-managed items
------------------         ---------------------
Province map image         Red anchor dots
Municipality labels        Green data boxes
Other annotations          Red connectors
```

## Position strategy

The existing provincial maps have custom visual layouts. Exact coordinates are therefore not guessed from the spreadsheet.

First sync:

```text
configured municipality coordinates
        OR
staging coordinates generated from province base X/Y
```

After the first sync, the user can drag generated shapes in Miro. Later imports call the shape update endpoint **without a position payload**, preserving those manual placements.

When a re-import requires a new panel after an already-existing panel, the service reads the previous panel's current Miro position and creates the new panel using the configured directional offset.

## Stable keys

Generated items use deterministic keys derived from the normalized municipality key:

```text
anchor:<municipality-hash>
panel:<municipality-hash>:0
panel:<municipality-hash>:1
connector:<municipality-hash>:0
connector:<municipality-hash>:1
```

This allows re-import to update rather than duplicate items.

## Safety behavior

- Synchronization is scoped by `province_id`.
- The target board comes from that province's saved Miro board ID.
- A re-import never deletes arbitrary board content.
- Obsolete removal only operates on rows recorded in `generated_miro_items`.
- API/network errors are propagated instead of being treated as a missing item, except for a real HTTP 404.
- OAuth access/refresh tokens are encrypted by Laravel model casts before database storage.
