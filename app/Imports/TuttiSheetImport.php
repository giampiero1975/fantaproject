<?php

namespace App\Imports;

use App\Models\Player;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsOnError; // Manteniamo per ora
use Throwable;

class TuttiSheetImport implements ToModel, WithHeadingRow, SkipsOnError
{
    public function headingRow(): int
    {
        return 2;
    }
    
    public function model(array $row)
    {
        // LOG M1: Inizio processamento della riga
        Log::info('TuttiSheetImport@model: START processing row: ' . json_encode($row));
        
        // Controllo più robusto per id e nome
        if (!isset($row['id']) || $row['id'] === null || $row['id'] === '' ||
            !isset($row['nome']) || $row['nome'] === null || $row['nome'] === '') {
                // LOG M2: Riga saltata per dati mancanti
                Log::warning('TuttiSheetImport@model: SKIPPED row due to missing or empty "id" or "nome". Data: ' . json_encode($row));
                return null;
            }
            
            $fantaPlatformId = $row['id'];
            // LOG M3: ID piattaforma rilevato
            Log::info('TuttiSheetImport@model: Preparing data for fanta_platform_id: ' . $fantaPlatformId);
            
            $playerData = [
                'name'              => $row['nome'],
                'team_name'         => $row['squadra'] ?? null, // Usa null coalescing per sicurezza
                'role'              => $row['r'] ?? null,
                'initial_quotation' => isset($row['qti']) && is_numeric($row['qti']) ? (int)$row['qti'] : null,
                'current_quotation' => isset($row['qta']) && is_numeric($row['qta']) ? (int)$row['qta'] : null,
                'fvm'               => isset($row['fvm']) && is_numeric($row['fvm']) ? (int)$row['fvm'] : null,
            ];
            
            // LOG M4: Dati preparati per il database
            Log::info('TuttiSheetImport@model: Data prepared for DB: ' . json_encode($playerData) . ' for fanta_platform_id: ' . $fantaPlatformId);
            
            try {
                $player = Player::withTrashed()->updateOrCreate(
                    ['fanta_platform_id' => $fantaPlatformId], // Criteri di ricerca
                    $playerData  // Valori per aggiornare o creare
                    );
                
                // LOG M5: Risultato di updateOrCreate
                Log::info('TuttiSheetImport@model: updateOrCreate executed for fanta_platform_id: ' . $fantaPlatformId .
                    '. Player DB ID: ' . ($player ? $player->id : 'NULL') .
                    ', Exists: ' . ($player ? ($player->exists ? 'true' : 'false') : 'N/A') .
                    ', WasRecentlyCreated: ' . ($player ? ($player->wasRecentlyCreated ? 'true' : 'false') : 'N/A') .
                    ', WasChanged: ' . ($player && $player->wasChanged() ? 'true' : 'false') .
                    ', IsTrashed: ' . ($player && $player->trashed() ? 'true' : 'false'));
                
                if ($player && $player->trashed()) {
                    // LOG M6: Tentativo di ripristino
                    Log::info('TuttiSheetImport@model: Player fanta_platform_id: ' . $fantaPlatformId . ' was trashed. Attempting restore.');
                    $player->restore();
                    // LOG M7: Esito ripristino
                    Log::info('TuttiSheetImport@model: Player fanta_platform_id: ' . $fantaPlatformId . ' RESTORED. Is now trashed? ' . ($player->trashed() ? 'true':'false'));
                } elseif ($player) {
                    // LOG M8: Giocatore non era trashed (o appena creato)
                    Log::info('TuttiSheetImport@model: Player fanta_platform_id: ' . $fantaPlatformId . ' was not trashed (active or newly created). Is now trashed? ' . ($player->trashed() ? 'true':'false'));
                } else {
                    Log::error('TuttiSheetImport@model: Player object IS NULL after updateOrCreate for fanta_platform_id: ' . $fantaPlatformId);
                }
                
                return $player;
                
            } catch (Throwable $exception) {
                // LOG M_ERR: Eccezione durante l'operazione DB
                Log::error('TuttiSheetImport@model: EXCEPTION during DB operation for fanta_platform_id: ' . $fantaPlatformId .
                    '. Message: ' . $exception->getMessage() .
                    '. DataRow: ' . json_encode($row));
                throw $exception; // Rilancia l'eccezione così SkipsOnError può gestirla (e loggarla tramite onError)
            }
    }
    
    public function onError(Throwable $e)
    {
        // LOG M_ON_ERROR: Errore gestito da SkipsOnError
        Log::error('TuttiSheetImport@onError (SkipsOnError): Error for a row (skipped). Message: ' . $e->getMessage());
    }
}