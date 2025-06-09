<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\PlayerSeasonStatsImport;
use App\Models\ImportLog;
use Illuminate\Support\Facades\Log;

class HistoricalStatsImportController extends Controller
{
    public function showImportForm()
    {
        return view('uploads.historical_stats');
    }
    
    public function import(Request $request)
    {
        $request->validate([
            'historical_stats_file' => 'required|mimes:xlsx,xls,csv',
            'season_start_year' => 'required|integer|min:2000|max:2099', // Aggiunto validazione per l'anno
            'league_name' => 'required|string|max:50', // Nuovo: validazione per il nome della lega
        ]);
        
        $file = $request->file('historical_stats_file');
        $seasonStartYear = $request->input('season_start_year');
        $leagueName = $request->input('league_name'); // Nuovo: recupera il nome della lega
        
        try {
            // Passa l'anno di inizio stagione e il nome della lega al costruttore dell'importer
            Excel::import(new PlayerSeasonStatsImport($seasonStartYear, $leagueName), $file);
            
            ImportLog::create([
                'original_file_name' => $file->getClientOriginalName(),
                'import_type' => 'historical_stats_manual_import',
                'status' => 'successo',
                'details' => "Importazione statistiche storiche '{$leagueName}' per la stagione {$seasonStartYear}-" . ($seasonStartYear + 1) . " completata con successo.",
                ]);
            
            return back()->with('success', 'Statistiche storiche importate con successo!');
            
        } catch (\Exception $e) {
            Log::error("Errore durante l'importazione delle statistiche storiche: " . $e->getMessage());
            ImportLog::create([
                'original_file_name' => $file->getClientOriginalName(),
                'import_type' => 'historical_stats_manual_import',
                'status' => 'fallito',
                'details' => "Errore: " . $e->getMessage(),
            ]);
            return back()->with('error', 'Errore durante l\'importazione delle statistiche storiche: ' . $e->getMessage());
        }
    }
}