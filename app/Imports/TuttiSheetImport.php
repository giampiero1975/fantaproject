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
    private static bool $keysLoggedForRosterImport = false; // Nome univoco
    private int $rowDataRowCount = 0;
    
    // Contatori per ImportLog
    public int $processedCount = 0;
    public int $createdCount = 0;
    public int $updatedCount = 0;
    
    public function __construct()
    {
        self::$keysLoggedForRosterImport = false;
        $this->rowDataRowCount = 0;
        $this->processedCount = 0;
        $this->createdCount = 0;
        $this->updatedCount = 0;
    }
    
    public function headingRow(): int
    {
        // Assumendo che le intestazioni del file Quotazioni siano alla riga 2
        // "Id", "Nome", "Squadra", "R", "Qt. I", "Qt. A", "FVM"
        return 2;
    }
    
    public function model(array $row)
    {
        $this->rowDataRowCount++;
        
        if (!self::$keysLoggedForRosterImport && !empty($row)) {
            Log::info('TuttiSheetImport@model (Roster): CHIAVI RICEVUTE (dalla riga d\'intestazione Excel #' . $this->headingRow() . '): ' . json_encode(array_keys($row)));
            self::$keysLoggedForRosterImport = true;
        }
        if ($this->rowDataRowCount <= 3 || $this->rowDataRowCount % 100 == 0) {
            Log::info('TuttiSheetImport@model (Roster): Processing Excel data row #' . $this->rowDataRowCount . ' (Excel file row #' . ($this->rowDataRowCount + $this->headingRow()) . ') Data: ' . json_encode($row));
        }
        
        $normalizedRow = [];
        foreach ($row as $key => $value) {
            // Maatwebsite/Excel normalizza le intestazioni in snake_case se contengono spazi o caratteri speciali.
            // Es. "Qt. I" potrebbe diventare "qt_i". "Id" diventa "id".
            // Per sicurezza, normalizziamo noi a minuscolo la chiave letta.
            $normalizedKey = strtolower( (string) $key);
            // Sostituiamo eventuali punti con underscore per chiavi come 'qt.i' -> 'qt_i'
            $normalizedKey = str_replace('.', '_', $normalizedKey);
            $normalizedRow[$normalizedKey] = $value;
        }
        
        $fantaPlatformId = $normalizedRow['id'] ?? null;
        $nome = $normalizedRow['nome'] ?? null;
        
        if ($fantaPlatformId === null || $nome === null) {
            Log::warning('TuttiSheetImport@model (Roster): RIGA SALTATA (Excel file row #' . ($this->rowDataRowCount + $this->headingRow()) . ') per mancanza di "id" o "nome". Dati Normalizzati: ' . json_encode($normalizedRow));
            return null;
        }
        
        $this->processedCount++;
        $fantaPlatformId = trim((string)$fantaPlatformId);
        
        // Prova diverse varianti per le chiavi delle quotazioni a causa della possibile normalizzazione
        $qti = $normalizedRow['qti'] ?? $normalizedRow['qt_i'] ?? null;
        $qta = $normalizedRow['qta'] ?? $normalizedRow['qt_a'] ?? null;
        
        $playerData = [
            'name'              => trim((string)$nome),
            'team_name'         => isset($normalizedRow['squadra']) ? trim((string)$normalizedRow['squadra']) : null,
            'role'              => isset($normalizedRow['r']) ? strtoupper(trim((string)$normalizedRow['r'])) : null,
            'initial_quotation' => ($qti !== null && is_numeric($qti)) ? (int)$qti : null,
            'current_quotation' => ($qta !== null && is_numeric($qta)) ? (int)$qta : null,
            'fvm'               => isset($normalizedRow['fvm']) && is_numeric($normalizedRow['fvm']) ? (int)$normalizedRow['fvm'] : null,
        ];
        
        if (!in_array($playerData['role'], ['P', 'D', 'C', 'A'], true) && $playerData['role'] !== null) {
            Log::warning('TuttiSheetImport@model (Roster): Ruolo non valido (' . $playerData['role'] . ') per giocatore ID ' . $fantaPlatformId . '. Impostato a NULL.');
            $playerData['role'] = null;
        }
        
        try {
            $player = Player::withTrashed()->updateOrCreate(
                ['fanta_platform_id' => (int)$fantaPlatformId],
                $playerData
                );
            
            if ($player->wasRecentlyCreated) {
                $this->createdCount++;
            } elseif ($player->wasChanged()) {
                $this->updatedCount++;
            }
            
            if ($player->trashed()) {
                Log::info('TuttiSheetImport@model (Roster): Player fanta_platform_id: ' . $fantaPlatformId . ' was trashed. Attempting restore.');
                $player->restore();
            }
            return $player;
            
        } catch (Throwable $exception) {
            Log::error('TuttiSheetImport@model (Roster): EXCEPTION during DB operation for fanta_platform_id: ' . $fantaPlatformId .
                '. Message: ' . $exception->getMessage() .
                '. DataRow: ' . json_encode($row)); // Logga la riga originale
            throw $exception;
        }
    }
    
    public function onError(Throwable $e)
    {
        Log::error('TuttiSheetImport@onError (Roster): Errore durante processamento riga (saltata). Message: ' . $e->getMessage());
    }
    
    // Metodi Getter per i contatori
    public function getProcessedCount(): int { return $this->processedCount; }
    public function getCreatedCount(): int { return $this->createdCount; }
    public function getUpdatedCount(): int { return $this->updatedCount; }
}