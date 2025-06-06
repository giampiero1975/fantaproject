<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RosterImportController;
use App\Http\Controllers\HistoricalStatsImportController;
use App\Http\Controllers\UserLeagueProfileController;
use App\Http\Controllers\DashboardController; // <--- AGGIUNGI QUESTO IMPORT

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

// Rotta per la dashboard (MODIFICATA)
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

// Rotta per mostrare il form di upload
Route::get('/upload/roster', [RosterImportController::class, 'showUploadForm'])->name('roster.show');

// Rotta per gestire l'upload del file
Route::post('/upload/roster', [RosterImportController::class, 'handleUpload'])->name('roster.upload');

// Rotte per l'importazione delle statistiche storiche
Route::get('/upload/historical-stats', [HistoricalStatsImportController::class, 'showUploadForm'])->name('historical_stats.show_upload_form');
Route::post('/upload/historical-stats', [HistoricalStatsImportController::class, 'handleUpload'])->name('historical_stats.handle_upload');

// Rotte per il Profilo Lega Utente
Route::get('/league/profile', [UserLeagueProfileController::class, 'edit'])->name('league.profile.edit');
Route::post('/league/profile', [UserLeagueProfileController::class, 'update'])->name('league.profile.update');

// Puoi mantenere la vecchia rotta 'welcome' se vuoi accedervi tramite un altro URL,
// oppure rimuoverla se la dashboard diventa la tua unica "home".
Route::get('/welcome-originale', function () { // Ho cambiato l'URL per evitare conflitti
    return view('welcome');
})->name('welcome');