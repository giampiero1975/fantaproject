<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\ImportLog;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\MainRosterImport;
use App\Imports\FirstRowOnlyImport;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

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
        if (!$file || !$file->isValid()) { /* ... gestione errore ... */ }
        
        $originalFileName = $file->getClientOriginalName();
        Log::info('RosterImportController@handleUpload: File "' . $originalFileName . '" ricevuto. Inizio importazione...');
        
        $importTag = 'N/A';
        try {
            $firstRowDataArray = Excel::toArray(new FirstRowOnlyImport('Tutti'), $file);
            if (!empty($firstRowDataArray) && !empty($firstRowDataArray[0]) && !empty($firstRowDataArray[0][0]) && isset($firstRowDataArray[0][0][0])) {
                $importTag = (string) $firstRowDataArray[0][0][0];
            } else {
                $importTag = 'Tag non trovato o foglio "Tutti" vuoto/mancante';
            }
            Log::info('Tag importazione letto per Roster: ' . $importTag);
        } catch (Throwable $e) {
            Log::warning('RosterImportController@handleUpload: Impossibile leggere il tag dalla riga 1 del Roster. Errore: ' . $e->getMessage());
            $importTag = 'Errore lettura tag: ' . $e->getMessage();
        }
        
        $importLog = ImportLog::create([
            'original_file_name' => $originalFileName,
            'import_type' => 'roster_quotazioni',
            'status' => 'in_corso',
            'details' => 'Avvio importazione Roster. Tag: ' . $importTag,
        ]);
        Log::info('RosterImportController@handleUpload: ImportLog ID ' . $importLog->id . ' creato con status: in_corso');
        
        $mainImporter = new MainRosterImport(); // Istanzia l'importer principale
        
        try {
            Log::info('RosterImportController@handleUpload: Eseguo il soft-delete di tutti i giocatori esistenti...');
            Player::query()->delete();
            Log::info('RosterImportController@handleUpload: Soft-delete completato.');
            
            Excel::import($mainImporter, $file); // Passa l'istanza
            
            $tuttiSheetImporter = $mainImporter->getTuttiSheetImporter(); // Ottieni l'importer del foglio
            
            $importLog->status = 'successo';
            $importLog->details = 'Importazione Roster completata con successo. Tag: ' . $importTag;
            $importLog->rows_processed = $tuttiSheetImporter->getProcessedCount();
            $importLog->rows_created = $tuttiSheetImporter->getCreatedCount();
            $importLog->rows_updated = $tuttiSheetImporter->getUpdatedCount();
            $importLog->save();
            Log::info('RosterImportController@handleUpload: ImportLog ID ' . $importLog->id . ' aggiornato a status: successo. Processed: '.$importLog->rows_processed.', Created: '.$importLog->rows_created.', Updated: '.$importLog->rows_updated);
            
            return back()->with('success', 'File "' . $originalFileName . '" importato! Righe processate: '.$importLog->rows_processed);
            
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            $failures = $e->failures();
            $errorMessages = [];
            foreach ($failures as $failure) {
                $errorMessages[] = "Riga {$failure->row()}: " . implode(', ', $failure->errors()) . " per attributo {$failure->attribute()} (Valori: " . json_encode($failure->values()) . ")";
            }
            Log::error('RosterImportController@handleUpload: ValidationException.', [
                'file' => $originalFileName,
                'failures_count' => count($failures),
                'first_failure_details' => !empty($failures) ? $failures[0]->toArray() : null,
                'error_messages_summary' => implode('; ', $errorMessages)
            ]);
            
            $importLog->status = 'fallito';
            $importLog->details = 'ValidationException: ' . implode('; ', $errorMessages) . '. Tag: ' . $importTag;
            // Recupera conteggi parziali se possibile
            if ($mainImporter && method_exists($mainImporter, 'getTuttiSheetImporter')) {
                $tuttiSheetImporter = $mainImporter->getTuttiSheetImporter();
                if($tuttiSheetImporter) {
                    $importLog->rows_processed = $tuttiSheetImporter->getProcessedCount();
                    $importLog->rows_created = $tuttiSheetImporter->getCreatedCount();
                    $importLog->rows_updated = $tuttiSheetImporter->getUpdatedCount();
                }
            }
            $importLog->save();
            Log::info('RosterImportController@handleUpload: ImportLog ID ' . $importLog->id . ' aggiornato a status (ValidationException): ' . $importLog->status);
            
            return back()->withErrors(['import_error' => 'Errori di validazione: ' . implode('; ', $errorMessages)])->withInput();
            
        } catch (Throwable $th) {
            Log::error('RosterImportController@handleUpload: Throwable Exception.', ['error' => $th->getMessage(), 'file' => $originalFileName, 'trace' => substr($th->getTraceAsString(),0,500)]);
            
            $importLog->status = 'fallito';
            $importLog->details = 'Throwable Exception: ' . $th->getMessage() . '. Tag: ' . $importTag;
            // Recupera conteggi parziali se possibile
            if ($mainImporter && method_exists($mainImporter, 'getTuttiSheetImporter')) {
                $tuttiSheetImporter = $mainImporter->getTuttiSheetImporter();
                if ($tuttiSheetImporter) {
                    $importLog->rows_processed = $tuttiSheetImporter->getProcessedCount();
                    $importLog->rows_created = $tuttiSheetImporter->getCreatedCount();
                    $importLog->rows_updated = $tuttiSheetImporter->getUpdatedCount();
                }
            }
            $importLog->save();
            Log::info('RosterImportController@handleUpload: ImportLog ID ' . $importLog->id . ' aggiornato a status (Throwable): ' . $importLog->status);
            
            return back()->withErrors(['import_error' => 'Errore imprevisto: ' . $th->getMessage()])->withInput();
        }
    }
}