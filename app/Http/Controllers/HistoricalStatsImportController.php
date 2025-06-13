<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\HistoricalStatsFileImport; // <-- 1. USARE IL CONTENITORE CORRETTO
use App\Models\League;
use App\Models\ImportLog;
use Illuminate\Support\Facades\Log;

class HistoricalStatsImportController extends Controller
{
    /**
     * Mostra il form per l'upload del file.
     */
    public function showUploadForm()
    {
        // Questo metodo ora serve solo a mostrare la vista
        return view('uploads.historical_stats');
    }
    
    /**
     * Gestisce l'importazione del file sottomesso dal form.
     */
    public function import(Request $request)
    {
        Log::info('HistoricalStatsImportController@import: Inizio processo di importazione.');
        
        // 2. La validazione deve corrispondere ESATTAMENTE ai campi del form
        $request->validate([
            'historical_stats_file' => 'required|mimes:xlsx,xls,csv',
            'season_start_year' => 'required|integer|min:2000|max:2099',
            'league_name' => 'required|string|max:255', // Campo di testo per il nome della lega
        ]);
        
        $file = $request->file('historical_stats_file');
        $season = (int)$request->input('season_start_year');
        $leagueName = $request->input('league_name');
        
        Log::info("Avvio import per Stagione: {$season}, Lega: {$leagueName}");
        
        try {
            // 3. LA CHIAMATA DECISIVA: Istanzia HistoricalStatsFileImport
            // Questo contenitore gestirà i fogli multipli e delegherà a TuttiHistoricalStatsImport
            $importProcessor = new HistoricalStatsFileImport($season, $leagueName);
            
            Excel::import($importProcessor, $file);
            
            ImportLog::create([
                'original_file_name' => $file->getClientOriginalName(),
                'import_type' => 'historical_stats_manual_import',
                'status' => 'successo',
                'details' => "Importazione statistiche storiche '{$leagueName}' per la stagione {$season}-" . ($season + 1) . " completata.",
                ]);
            
            return back()->with('success', 'Statistiche storiche importate con successo!');
            
        } catch (\Exception $e) {
            Log::error("ERRORE DURANTE IMPORTAZIONE STORICA: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            
            ImportLog::create([
                'original_file_name' => $file->getClientOriginalName(),
                'import_type' => 'historical_stats_manual_import',
                'status' => 'fallito',
                'details' => "Errore: " . $e->getMessage(),
            ]);
            
            return back()->with('error', 'Errore durante l\'importazione: ' . $e->getMessage());
        }
    }
}