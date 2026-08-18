<?php

namespace App\Http\Controllers;

use App\Models\MiroConnection;
use App\Services\MiroService;
use Illuminate\View\View;
use Throwable;

class SettingsController extends Controller
{
    public function index(MiroService $miro): View
    {
        $connection = MiroConnection::query()->latest('id')->first();
        $boards = [];
        $boardError = null;

        if ($connection) {
            try {
                $boards = $miro->listBoards();
            } catch (Throwable $e) {
                $boardError = $e->getMessage();
            }
        }

        return view('settings.index', compact('connection', 'boards', 'boardError'));
    }
}
