<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RosterImportController;
use App\Http\Controllers\HistoricalStatsImportController;
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

// Rotta per mostrare il form di upload
Route::get('/upload/roster', [RosterImportController::class, 'showUploadForm'])->name('roster.show');

// Rotta per gestire l'upload del file
Route::post('/upload/roster', [RosterImportController::class, 'handleUpload'])->name('roster.upload');

// Rotte per l'importazione delle statistiche storiche
Route::get('/upload/historical-stats', [HistoricalStatsImportController::class, 'showUploadForm'])->name('historical_stats.show_upload_form');
Route::post('/upload/historical-stats', [HistoricalStatsImportController::class, 'handleUpload'])->name('historical_stats.handle_upload');

Route::get('/', function () {
    return view('welcome');
});
