# DILP Miro Performance / Timeout Overlay

Apply this overlay on top of the current **exact reference layout** project.

## What this fixes

The previous synchronizer created/updated Miro shapes one HTTP request at a time. Large provinces can therefore exceed PHP's 300-second execution limit, especially when Miro rate limiting adds waits between requests.

This overlay:

- disables the PHP execution-time limit for the Miro commit request;
- allows an import left in `processing` by an earlier timeout to resume;
- uses Miro's `/items/bulk` endpoint for new shapes, up to 20 shapes per request;
- falls back to individual shape creation if a workspace rejects bulk creation;
- caches the Miro OAuth connection during a request;
- preloads generated-item tracking instead of repeatedly querying the same records;
- no longer GETs every permanent municipality anchor during every synchronization;
- stores a synchronization fingerprint (`sync_hash`) for generated shapes/connectors;
- skips unchanged shapes/connectors on later re-imports;
- preserves the current exact-reference layout, yellow group-project boxes, green municipality boxes, red map arrows, and summary cards.

## Install

Extract this ZIP directly over the existing Laravel project and allow replacement of files.

Then run:

```powershell
php artisan optimize:clear
php artisan serve
```

No migration is required.

## Retry a previously timed-out import

If the old request timed out and the import batch is still `processing`, you can return to its preview page and click **Synchronize to Miro** again. The new synchronizer accepts `processing` batches and reuses the generated-item tracking records rather than intentionally starting a second duplicate layout.

The first run after installing this overlay may still update older tracked items once because those records do not yet contain `sync_hash`. Later re-imports of unchanged content should be substantially faster.

## Important

Do not delete `generated_miro_items` before retrying. Those records are what allow the importer to recognize the items already written before the timeout.
