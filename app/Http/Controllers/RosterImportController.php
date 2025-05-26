<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\ImportLog; // <-- AGGIUNGI MODELLO IMPORTLOG
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\MainRosterImport;
use App\Imports\FirstRowOnlyImport; // <-- Creeremo questa piccola classe di utility
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class RosterImportController extends Controller
{
    public function showUploadForm(): View
    {
        return view('uploads.roster');
    }
    
    public function handleUpload(Request $request)
    {
        Log::info('RosterImportController@handleUpload: Inizio processo di upload.');
        $request->validate(['roster_file' => 'required|mimes:xlsx,xls|max:10240']);
        Log::info('RosterImportController@handleUpload: Validazione superata.');
        
        $file = $request->file('roster_file');
        if (!$file || !$file->isValid()) {
            Log::error('RosterImportController@handleUpload: File non valido.');
            return back()->withErrors(['import_error' => 'File non valido.']);
        }
        
        $originalFileName = $file->getClientOriginalName();
        Log::info('RosterImportController@handleUpload: File "' . $originalFileName . '" ricevuto. Inizio importazione...');
        
        // Tentativo di leggere il tag dalla riga 1 del foglio "Tutti"
        $importTag = 'N/A';
        try {
            // Usiamo una classe di importazione semplice per leggere solo la prima riga del primo foglio utile
            // NOTA: Questo approccio per leggere il tag è semplificato. Assume che "Tutti" sia il primo foglio
            // o che la prima riga del primo foglio sia rappresentativa.
            // Per una soluzione più robusta, FirstRowOnlyImport dovrebbe gestire la selezione del foglio "Tutti".
            $firstRowData = Excel::toArray(new FirstRowOnlyImport('Tutti'), $file);
            
            // $firstRowData sarà un array di fogli; ogni foglio un array di righe.
            // Se FirstRowOnlyImport gestisce il nome del foglio, dovremmo avere solo i dati di 'Tutti'.
            if (!empty($firstRowData) && !empty($firstRowData[0]) && !empty($firstRowData[0][0])) {
                // Prendi il primo valore della prima riga del foglio selezionato
                $importTag = (string) ($firstRowData[0][0][0] ?? 'Tag non trovato');
            }
            Log::info('Tag importazione letto: ' . $importTag);
        } catch (\Throwable $e) {
            Log::warning('RosterImportController@handleUpload: Impossibile leggere il tag dalla riga 1. Errore: ' . $e->getMessage());
        }
        
        $importLog = ImportLog::create([
            'original_file_name' => $originalFileName,
            'import_type' => 'roster_quotazioni',
            'status' => 'in_corso',
            'details' => 'Tag: ' . $importTag,
        ]);
        
        try {
            Log::info('RosterImportController@handleUpload: Eseguo il soft-delete di tutti i giocatori esistenti...');
            Player::query()->delete();
            Log::info('RosterImportController@handleUpload: Soft-delete completato.');
            
            $mainImporter = new MainRosterImport(2); // Riga intestazioni per "Tutti" è la 2
            Excel::import($mainImporter, $file);
            
            // Qui potremmo voler ottenere un conteggio dei record processati/creati/aggiornati
            // da $mainImporter o da un evento, per ora lo omettiamo per semplicità.
            $importLog->status = 'successo';
            $importLog->details = 'Importazione completata con successo. Tag: ' . $importTag;
            // $importLog->rows_processed = ... ; // Da implementare
            $importLog->save();
            
            Log::info('RosterImportController@handleUpload: Excel::import chiamato con successo per il file: ' . $originalFileName);
            return back()->with('success', 'File "' . $originalFileName . '" importato e giocatori aggiornati!');
            
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            $failures = $e->failures();
            Log::error('RosterImportController@handleUpload: ValidationException.', ['failures' => $failures, 'file' => $originalFileName]);
            $importLog->status = 'fallito';
            $importLog->details = 'ValidationException: ' . json_encode($failures) . '. Tag: ' . $importTag;
            $importLog->save();
            return back()->withErrors(['import_error' => 'Errori di validazione durante l\'importazione.']);
        } catch (\Throwable $th) {
            Log::error('RosterImportController@handleUpload: Throwable Exception.', ['error' => $th->getMessage(), 'file' => $originalFileName]);
            $importLog->status = 'fallito';
            $importLog->details = 'Throwable Exception: ' . $th->getMessage() . '. Tag: ' . $importTag;
            $importLog->save();
            return back()->withErrors(['import_error' => 'Errore imprevisto: ' . $th->getMessage()]);
        }
    }
}