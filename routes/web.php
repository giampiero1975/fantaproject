<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HistoricalStatsImportController;
use App\Http\Controllers\RosterImportController;
use App\Http\Controllers\UserLeagueProfileController;
use App\Http\Controllers\PlayerProjectionController; // <-- NUOVO: Importa il controller delle proiezioni
use Illuminate\Support\Facades\Route;

/*
 |--------------------------------------------------------------------------
 | Web Routes
 |--------------------------------------------------------------------------
 |
 | Here is where you can register web routes for your application. These
 | routes are loaded by the RouteServiceProvider within a group which
 | contains the "web" middleware group. Now create something great!
 |
 */

// Rotta per la dashboard (o home)
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

// Rotte per l'importazione del Roster (Listone)
Route::get('/upload/roster', [RosterImportController::class, 'showUploadForm'])->name('roster.show');
Route::post('/upload/roster', [RosterImportController::class, 'handleUpload'])->name('roster.upload');

// Rotte per l'importazione delle Statistiche Storiche
Route::get('/upload/historical-stats', [HistoricalStatsImportController::class, 'showImportForm'])->name('historical_stats.show_upload_form');
Route::post('/upload/historical-stats', [HistoricalStatsImportController::class, 'handleUpload'])->name('historical_stats.handle_upload');

// Rotte per la gestione del profilo lega
Route::get('/league/profile', [UserLeagueProfileController::class, 'edit'])->name('league.profile.edit');
Route::post('/league/profile', [UserLeagueProfileController::class, 'update'])->name('league.profile.update');

// --- NUOVO: Rotta per la visualizzazione delle proiezioni ---
Route::get('/projections', [PlayerProjectionController::class, 'index'])->name('projections.index');

Route::get('/dashboard/historical-coverage', [DashboardController::class, 'showHistoricalCoverage'])->name('dashboard.historical_coverage');