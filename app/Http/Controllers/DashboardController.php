<?php

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use App\Models\MiroConnection;
use App\Models\Province;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('dashboard', [
            'provinceCount' => Province::query()->where('active', true)->count(),
            'configuredProvinceCount' => Province::query()->whereNotNull('miro_board_id')->count(),
            'completedImportCount' => ImportBatch::query()->where('status', 'completed')->count(),
            'miroConnected' => MiroConnection::query()->exists(),
            'recentImports' => ImportBatch::query()->with('province')->latest()->limit(8)->get(),
        ]);
    }
}
