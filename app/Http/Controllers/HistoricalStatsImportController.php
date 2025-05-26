<?php

namespace App\Http\Controllers;

use App\Models\ImportLog;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\HistoricalStatsFileImport; // Assicurati che il nome sia corretto
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class HistoricalStatsImportController extends Controller
{
    public function showUploadForm(): View
    {
        return view('uploads.historical_stats');
    }
    
    public function handleUpload(Request $request)
    {
        Log::info('HistoricalStatsImportController@handleUpload: Inizio processo upload statistiche.');
        
        $request->validate([
            'historical_stats_file' => 'required|mimes:xlsx,xls|max:10240',
        ]);
        
        Log::info('HistoricalStatsImportController@handleUpload: Validazione file superata.');
        
        $file = $request->file('historical_stats_file');
        
        if (!$file || !$file->isValid()) {
            Log::error('HistoricalStatsImportController@handleUpload: File statistiche non valido o non caricato.');
            return back()->withErrors(['import_error' => ['Il file fornito non è valido o non è stato caricato correttamente.']]);
        }
        
        $originalFileName = $file->getClientOriginalName();
        Log::info('HistoricalStatsImportController@handleUpload: File "' . $originalFileName . '" ricevuto. Inizio importazione statistiche...');
        
        // Determinare la stagione
        $seasonForFile = '2024-25'; // IMPOSTAZIONE TEMPORANEA PER TEST
        // Logica per derivare la stagione dal nome del file (esempio):
        if (preg_match('/_(\d{4})_(\d{2})\.xlsx/i', $originalFileName, $matches)) {
            $yearStart = $matches[1];
            // Assumendo che _YY sia la fine dell'anno, es. 2023_24 -> 2023-24
            // Se fosse 2023_2024, la logica di $yearEndShort andrebbe cambiata
            $yearEndShort = $matches[2];
            // Ricostruisci la stagione nel formato "AAAA-AA" o "AAAA-AAAA"
            // Questo esempio assume che $yearEndShort sia solo le ultime due cifre dell'anno finale.
            // Se il formato del nome file è Stagione_AAAA_AAAA.xlsx, questa logica va cambiata.
            $seasonForFile = $yearStart . '-' . $yearEndShort; // Es. 2024-25 se il file fosse _2024_25
        } else if (preg_match('/_(\d{4}-\d{4})\.xlsx/i', $originalFileName, $matches)) { // Es. Stagione_2024-2025
            $seasonForFile = $matches[1];
        } else if (preg_match('/_(\d{4}-\d{2})\.xlsx/i', $originalFileName, $matches)) { // Es. Stagione_2024-25
            $seasonForFile = $matches[1];
        }
        else {
            Log::warning('HistoricalStatsImportController@handleUpload: Impossibile derivare la stagione dal nome del file "' . $originalFileName . '". Uso placeholder: ' . $seasonForFile);
        }
        Log::info('HistoricalStatsImportController@handleUpload: Stagione determinata/impostata per l\'importazione: ' . $seasonForFile);
        
        
        $importTag = 'Statistiche Storiche ' . $seasonForFile . ' - ' . $originalFileName;
        $importLog = ImportLog::create([
            'original_file_name' => $originalFileName,
            'import_type'        => 'statistiche_storiche',
            'status'             => 'in_corso',
            'details'            => 'Tag: ' . $importTag,
        ]);
        
        try {
            // Passa la stagione determinata al costruttore di HistoricalStatsFileImport
            Excel::import(new HistoricalStatsFileImport($seasonForFile), $file);
            
            $importLog->status = 'successo';
            $importLog->details = 'Importazione statistiche completata. Tag: ' . $importTag;
            $importLog->save();
            
            Log::info('HistoricalStatsImportController@handleUpload: Excel::import (statistiche) chiamato con successo per: ' . $originalFileName);
            return back()->with('success', 'File statistiche "' . $originalFileName . '" importato con successo!');
            
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            $failures = $e->failures();
            Log::error('HistoricalStatsImportController@handleUpload: ValidationException import statistiche.', ['failures' => $failures, 'file' => $originalFileName]);
            if ($importLog) {
                $importLog->status = 'fallito';
                $importLog->details = 'ValidationException: ' . json_encode($failures) . '. Tag: ' . $importTag;
                $importLog->save();
            }
            $errorMessages = [];
            foreach ($failures as $failure) {
                $problematicValues = $failure->values();
                $attributeKey = $failure->attribute();
                $valueDisplay = isset($problematicValues[$attributeKey]) ? $problematicValues[$attributeKey] : 'N/A';
                $errorMessages[] = "Riga {$failure->row()}: {$failure->errors()[0]} per attributo {$attributeKey} (Valore: {$valueDisplay})";
            }
            return back()->with('import_errors', $errorMessages);
            
        } catch (\Throwable $th) {
            Log::error('HistoricalStatsImportController@handleUpload: Throwable Exception import statistiche.', ['error' => $th->getMessage(), 'file' => $originalFileName, 'trace' => substr($th->getTraceAsString(),0,500)]);
            if ($importLog) {
                $importLog->status = 'fallito';
                $importLog->details = 'Throwable Exception: ' . $th->getMessage() . '. Tag: ' . $importTag;
                $importLog->save();
            }
            return back()->withErrors(['import_error' => ['Errore imprevisto durante l\'importazione: ' . $th->getMessage()]]);
        }
    }
}