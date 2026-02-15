<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InstallationController;
use App\Http\Controllers\ServerController;
use Illuminate\Support\Facades\Route;

// ── Public ──────────────────────────────────────────────────────

Route::get('/', function () {
    return redirect()->route('dashboard');
});

// ── Authenticated Routes ────────────────────────────────────────

Route::middleware(['auth', 'verified'])->group(function () {

    // Dashboard
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Servers
    Route::resource('servers', ServerController::class)->except(['edit', 'update']);
    Route::post('/servers/{server}/test', [ServerController::class, 'testConnection'])
        ->name('servers.test');

    // Installations
    Route::get('/installations', [InstallationController::class, 'index'])
        ->name('installations.index');
    Route::get('/installations/create', [InstallationController::class, 'create'])
        ->name('installations.create');
    Route::post('/installations', [InstallationController::class, 'store'])
        ->name('installations.store');
    Route::get('/installations/{installation}', [InstallationController::class, 'show'])
        ->name('installations.show');
    Route::get('/installations/{installation}/status', [InstallationController::class, 'status'])
        ->name('installations.status');
});

// ── Auth Routes (Laravel Breeze) ────────────────────────────────

require __DIR__.'/auth.php';
