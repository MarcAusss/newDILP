<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\MiroOAuthController;
use App\Http\Controllers\ProvinceController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');

Route::prefix('imports')->name('imports.')->group(function () {
    Route::get('/', [ImportController::class, 'index'])->name('index');
    Route::get('/create', [ImportController::class, 'create'])->name('create');
    Route::post('/analyze', [ImportController::class, 'analyze'])->name('analyze');
    Route::get('/{import}/preview', [ImportController::class, 'preview'])->name('preview');
    Route::post('/{import}/commit', [ImportController::class, 'commit'])->name('commit');
    Route::get('/{import}', [ImportController::class, 'show'])->name('show');
    Route::delete('/{import}', [ImportController::class, 'destroy'])->name('destroy');
});

Route::get('/provinces', [ProvinceController::class, 'index'])->name('provinces.index');
Route::put('/provinces/{province}', [ProvinceController::class, 'update'])->name('provinces.update');
Route::get('/provinces/{province}/municipalities', [ProvinceController::class, 'municipalities'])->name('provinces.municipalities');
Route::put('/provinces/{province}/municipalities', [ProvinceController::class, 'updateMunicipalities'])->name('provinces.municipalities.update');
Route::post('/provinces/{province}/municipalities/scan', [ProvinceController::class, 'scanMunicipalities'])->name('provinces.municipalities.scan');

Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
Route::get('/miro/connect', [MiroOAuthController::class, 'connect'])->name('miro.connect');
Route::get('/miro/callback', [MiroOAuthController::class, 'callback'])->name('miro.callback');
Route::post('/miro/disconnect', [MiroOAuthController::class, 'disconnect'])->name('miro.disconnect');
