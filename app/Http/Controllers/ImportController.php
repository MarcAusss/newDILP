<?php

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\MunicipalityMapping;
use App\Models\Province;
use App\Services\MiroSyncService;
use App\Services\MunicipalityMappingService;
use App\Services\SpreadsheetImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Illuminate\View\View;
use Throwable;

class ImportController extends Controller
{
    public function index(): View
    {
        return view('imports.index', [
            'imports' => ImportBatch::query()->with('province')->latest()->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('imports.create', [
            'provinces' => Province::query()->where('active', true)->orderBy('name')->get(),
            'maxFileMb' => config('imports.max_file_mb'),
        ]);
    }

    public function analyze(
        Request $request,
        SpreadsheetImportService $spreadsheet,
        MunicipalityMappingService $mappingService,
    ): RedirectResponse {
        /*
        |--------------------------------------------------------------------------
        | Spreadsheet analysis can exceed PHP's default 30-second web timeout.
        |--------------------------------------------------------------------------
        */
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '1024M');
        ignore_user_abort(true);

        $maxKb = config('imports.max_file_mb') * 1024;

        $validated = $request->validate([
            'province_id' => ['required', 'exists:provinces,id'],
            'spreadsheet' => [
                'required',
                File::types(['xlsx', 'xls'])->max($maxKb.'kb'),
            ],
        ], [
            'spreadsheet.max' => 'The workbook exceeds the '.config('imports.max_file_mb').' MB upload limit.',
            'spreadsheet.mimes' => 'Upload the Excel workbook as XLSX or XLS.',
        ]);

        $province = Province::query()->findOrFail($validated['province_id']);
        $file = $request->file('spreadsheet');
        $storedPath = $file->storeAs(
            'imports/'.now()->format('Y/m'),
            uniqid('mapping_', true).'.'.$file->getClientOriginalExtension(),
            'local',
        );

        try {
            $analysis = $spreadsheet->analyze(
                Storage::disk('local')->path($storedPath),
                $province,
            );

            $batch = DB::transaction(function () use (
                $province,
                $file,
                $storedPath,
                $analysis,
                $mappingService,
            ) {
                $batch = ImportBatch::create([
                    'province_id' => $province->id,
                    'original_filename' => $file->getClientOriginalName(),
                    'stored_path' => $storedPath,
                    'sheet_name' => $analysis['sheet_name'],
                    'status' => 'preview',
                    'source_rows' => $analysis['source_rows'],

                    // Regular totals only. #EA9999 Group Projects are excluded.
                    'municipality_count' => $analysis['municipality_count'],
                    'barangay_count' => $analysis['barangay_count'],
                    'beneficiary_total' => $analysis['beneficiary_total'],
                    'undertaking_total' => $analysis['undertaking_total'],
                    'regular_project_count' => $analysis['regular_project_count'],
                    'total_approved_projects' => $analysis['total_approved_projects'],

                    // Separate Group Project totals.
                    'group_project_count' => $analysis['group_project_count'],
                    'group_beneficiary_total' => $analysis['group_beneficiary_total'],
                    'group_undertaking_total' => $analysis['group_undertaking_total'],

                    'warnings' => $analysis['warnings'],
                ]);

                foreach ($analysis['rows'] as $row) {
                    ImportRow::create([
                        'import_batch_id' => $batch->id,
                        ...$row,
                    ]);
                }

                // Only normal municipality rows are returned here by the parser.
                $mappingService->ensure($province, $analysis['municipalities']);

                return $batch;
            });

            return redirect()->route('imports.preview', $batch);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($storedPath);

            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function preview(ImportBatch $import): View
    {
        $import->load(['province', 'rows']);

        $regularRows = $import->rows
            ->where('is_group_project', false)
            ->sortBy('sort_order')
            ->values();

        $groupRows = $import->rows
            ->where('is_group_project', true)
            ->sortBy('sort_order')
            ->values();

        $keys = $regularRows
            ->pluck('municipality_key')
            ->unique()
            ->values();

        return view('imports.preview', [
            'import' => $import,
            'groupedRows' => $regularRows->groupBy('municipality'),
            'groupProjectRows' => $groupRows->groupBy('group_project_key'),
            'previousCompleted' => ImportBatch::query()
                ->where('province_id', $import->province_id)
                ->where('status', 'completed')
                ->whereKeyNot($import->id)
                ->latest('completed_at')
                ->first(),
            'unconfiguredMappings' => MunicipalityMapping::query()
                ->where('province_id', $import->province_id)
                ->when($keys->isNotEmpty(), fn ($query) => $query->whereIn('municipality_key', $keys))
                ->when($keys->isEmpty(), fn ($query) => $query->whereRaw('1 = 0'))
                ->where('configured', false)
                ->orderBy('sort_order')
                ->get(),
        ]);
    }

    public function commit(ImportBatch $import, MiroSyncService $sync): RedirectResponse
    {
        // Large Miro boards can exceed the normal web-request execution time.
        // Keep this local synchronization request alive until Miro finishes.
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        ignore_user_abort(true);

        // A previous PHP timeout may have left the batch in "processing" even
        // though only part of the Miro layout was written. The synchronization
        // service is idempotent, so processing batches are safe to resume.
        if (!in_array($import->status, ['preview', 'failed', 'processing'], true)) {
            return back()->with('error', 'This import cannot be synchronized from its current status.');
        }

        try {
            $sync->sync($import);

            return redirect()->route('imports.show', $import)
                ->with(
                    'success',
                    $import->province->name.' map boxes, Group Project boxes, connectors, and summary panel were synchronized to Miro successfully.'
                );
        } catch (Throwable $e) {
            return redirect()->route('imports.preview', $import)
                ->with('error', 'Miro synchronization failed: '.$e->getMessage());
        }
    }

    public function show(ImportBatch $import): View
    {
        $import->load(['province', 'rows']);

        $regularRows = $import->rows
            ->where('is_group_project', false)
            ->sortBy('sort_order')
            ->values();

        $groupRows = $import->rows
            ->where('is_group_project', true)
            ->sortBy('sort_order')
            ->values();

        return view('imports.show', [
            'import' => $import,
            'groupedRows' => $regularRows->groupBy('municipality'),
            'groupProjectRows' => $groupRows->groupBy('group_project_key'),
        ]);
    }

    public function destroy(ImportBatch $import): RedirectResponse
    {
        if ($import->status === 'processing') {
            return back()->with('error', 'A processing import cannot be deleted.');
        }

        Storage::disk('local')->delete($import->stored_path);
        $import->delete();

        return redirect()->route('imports.index')->with('success', 'Import record deleted.');
    }
}
