<?php

namespace App\Http\Controllers;

use App\Models\ImportLog;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\HistoricalStatsFileImport;
// Non più necessario importare TuttiHistoricalStatsImport direttamente qui se si accede tramite l'importer principale
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class HistoricalStatsImportController extends Controller
{
    public function showUploadForm(): View
    {
        return view('uploads.historical_stats');
    }
    
    public function handleUpload(Request $request)
    {
        Log::info('HistoricalStatsImportController@handleUpload: Inizio processo upload statistiche.');
        $request->validate(['historical_stats_file' => 'required|mimes:xlsx,xls|max:10240']);
        Log::info('HistoricalStatsImportController@handleUpload: Validazione file superata.');
        
        $file = $request->file('historical_stats_file');
        if (!$file || !$file->isValid()) { /* ... gestione errore ... */ }
        
        $originalFileName = $file->getClientOriginalName();
        Log::info('HistoricalStatsImportController@handleUpload: File "' . $originalFileName . '" ricevuto. Inizio importazione statistiche...');
        
        $seasonForFile = 'YYYY-YY'; // Default
        // ... (la tua logica per derivare seasonForFile) ...
        if (preg_match('/_(\d{4})_(\d{2,4})\.(xlsx|xls)/i', $originalFileName, $matches)) {
            $yearStart = $matches[1];
            $yearEndPart = $matches[2];
            if (strlen($yearEndPart) === 2) {
                $seasonForFile = $yearStart . '-' . $yearEndPart;
            } elseif (strlen($yearEndPart) === 4) {
                $seasonForFile = $yearStart . '-' . substr($yearEndPart, 2, 2);
            }
        } elseif (preg_match('/_(\d{4}-\d{2,4})\.(xlsx|xls)/i', $originalFileName, $matches)) {
            $seasonForFile = $matches[1];
            if (strlen(explode('-', $matches[1])[1]) === 4) {
                $parts = explode('-', $matches[1]);
                $seasonForFile = $parts[0] . '-' . substr($parts[1], 2, 2);
            }
        } else {
            Log::warning('HistoricalStatsImportController@handleUpload: Impossibile derivare la stagione dal nome del file "' . $originalFileName . '". Uso default: ' . $seasonForFile);
        }
        Log::info('HistoricalStatsImportController@handleUpload: Stagione impostata: ' . $seasonForFile);
        
        
        $importTag = 'Statistiche Storiche ' . $seasonForFile . ' - ' . $originalFileName;
        
        $importLog = ImportLog::create([
            'original_file_name' => $originalFileName,
            'import_type'        => 'statistiche_storiche',
            'status'             => 'in_corso',
            'details'            => 'Avvio importazione. Tag: ' . $importTag,
        ]);
        Log::info('HistoricalStatsImportController@handleUpload: ImportLog ID ' . $importLog->id . ' creato con status: in_corso');
        
        $importerInstance = new HistoricalStatsFileImport($seasonForFile); // Istanzia l'importer principale
        
        try {
            Excel::import($importerInstance, $file); // Passa l'istanza
            
            $tuttiSheetImporter = $importerInstance->getTuttiSheetImporter(); // Ottieni l'importer del foglio
            
            $importLog->status = 'successo';
            $importLog->details = 'Importazione statistiche completata. Tag: ' . $importTag;
            $importLog->rows_processed = $tuttiSheetImporter->getProcessedCount();
            $importLog->rows_created = $tuttiSheetImporter->getCreatedCount();
            $importLog->rows_updated = $tuttiSheetImporter->getUpdatedCount();
            $importLog->save();
            Log::info('HistoricalStatsImportController@handleUpload: ImportLog ID ' . $importLog->id . ' aggiornato a status: successo. Processed: '.$importLog->rows_processed.', Created: '.$importLog->rows_created.', Updated: '.$importLog->rows_updated);
            
            return back()->with('success', 'File statistiche "' . $originalFileName . '" importato! Righe processate: '.$importLog->rows_processed);
            
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            // ... (codice di gestione eccezione come prima, assicurati di salvare $importLog)
            $failures = $e->failures();
            $errorMessages = [];
            foreach ($failures as $failure) {
                $errorMessages[] = "Riga {$failure->row()}: " . implode(', ', $failure->errors()) . " per attributo {$failure->attribute()} (Valori: " . json_encode($failure->values()) . ")";
            }
            Log::error('HistoricalStatsImportController@handleUpload: ValidationException import statistiche.', [
                'file' => $originalFileName,
                'failures_count' => count($failures),
                'first_failure_details' => !empty($failures) ? $failures[0]->toArray() : null,
                'error_messages_summary' => implode('; ', $errorMessages)
            ]);
            
            $importLog->status = 'fallito';
            $importLog->details = 'ValidationException: ' . implode('; ', $errorMessages) . '. Tag: ' . $importTag;
            // Puoi anche aggiungere i conteggi parziali se disponibili
            $tuttiSheetImporter = $importerInstance->getTuttiSheetImporter();
            $importLog->rows_processed = $tuttiSheetImporter->getProcessedCount();
            $importLog->rows_created = $tuttiSheetImporter->getCreatedCount();
            $importLog->rows_updated = $tuttiSheetImporter->getUpdatedCount();
            $importLog->save();
            Log::info('HistoricalStatsImportController@handleUpload: ImportLog ID ' . $importLog->id . ' aggiornato a status (ValidationException): ' . $importLog->status);
            
            return back()->with('import_errors', $errorMessages)->withInput();
            
            
        } catch (Throwable $th) {
            // ... (codice di gestione eccezione come prima, assicurati di salvare $importLog)
            Log::error('HistoricalStatsImportController@handleUpload: Throwable Exception import statistiche.', [
                'error' => $th->getMessage(),
                'file' => $originalFileName,
                'trace' => substr($th->getTraceAsString(),0,1000)
            ]);
            
            $importLog->status = 'fallito';
            $importLog->details = 'Throwable Exception: ' . $th->getMessage() . '. Tag: ' . $importTag;
            // Puoi anche aggiungere i conteggi parziali se disponibili
            if($importerInstance && method_exists($importerInstance, 'getTuttiSheetImporter')) { // Verifica per sicurezza
                $tuttiSheetImporter = $importerInstance->getTuttiSheetImporter();
                if ($tuttiSheetImporter) {
                    $importLog->rows_processed = $tuttiSheetImporter->getProcessedCount();
                    $importLog->rows_created = $tuttiSheetImporter->getCreatedCount();
                    $importLog->rows_updated = $tuttiSheetImporter->getUpdatedCount();
                }
            }
            $importLog->save();
            Log::info('HistoricalStatsImportController@handleUpload: ImportLog ID ' . $importLog->id . ' aggiornato a status (Throwable): ' . $importLog->status);
            
            return back()->withErrors(['import_error' => ['Errore imprevisto durante l\'importazione: ' . $th->getMessage()]])->withInput();
        }
    }
}