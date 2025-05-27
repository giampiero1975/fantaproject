<?php

namespace App\Imports;

use App\Models\Player;
use App\Models\Team; // Assicurati che il modello Team sia importato
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Throwable;

class TuttiSheetImport implements ToModel, WithHeadingRow, SkipsOnError
{
    private static bool $keysLoggedForRosterImport = false;
    private int $rowDataRowCount = 0;
    
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
        // Riga 1 dell'Excel: Titolo generale (es. "Quotazioni Fantacalcio Stagione...")
        // Riga 2 dell'Excel: Intestazioni effettive delle colonne ("Id", "Nome", "Squadra", "R", "Qt. I", ...)
        return 2;
    }
    
    public function model(array $row)
    {
        $this->rowDataRowCount++;
        
        if (!self::$keysLoggedForRosterImport && !empty($row)) {
            Log::info('TuttiSheetImport@model (Roster): CHIAVI RICEVUTE (dalla riga d\'intestazione Excel #' . $this->headingRow() . '): ' . json_encode(array_keys($row)));
            self::$keysLoggedForRosterImport = true;
        }
        
        // Non loggare ogni riga in produzione, solo per debug
        // if ($this->rowDataRowCount <= 3 || $this->rowDataRowCount % 100 == 0) {
        //      Log::info('TuttiSheetImport@model (Roster): Processing Excel data row #' . $this->rowDataRowCount . ' (Excel file row #' . ($this->rowDataRowCount + $this->headingRow()) . ') Data: ' . json_encode($row));
        // }
        
        $normalizedRow = [];
        foreach ($row as $key => $value) {
            $normalizedKey = strtolower( (string) $key);
            $normalizedKey = preg_replace('/[\s.]+/', '_', $normalizedKey);
            $normalizedRow[$normalizedKey] = $value;
        }
        
        $fantaPlatformId = $normalizedRow['id'] ?? null;
        $nome = $normalizedRow['nome'] ?? null;
        
        if ($fantaPlatformId === null || $nome === null || trim((string)$nome) === '') {
            Log::warning('TuttiSheetImport@model (Roster): RIGA SALTATA (Excel file row #' . ($this->rowDataRowCount + $this->headingRow()) . ') per mancanza di "id" o "nome". Dati Normalizzati: ' . json_encode($normalizedRow));
            return null;
        }
        
        $this->processedCount++;
        $fantaPlatformId = trim((string)$fantaPlatformId);
        $nome = trim((string)$nome);
        
        $teamName = isset($normalizedRow['squadra']) ? trim((string)$normalizedRow['squadra']) : null;
        $teamId = null;
        if ($teamName) {
            $team = Team::where('name', $teamName)->first();
            if ($team) {
                $teamId = $team->id;
            } else {
                Log::warning('TuttiSheetImport@model (Roster): Squadra "' . $teamName . '" non trovata nel DB per giocatore ID ' . $fantaPlatformId . '. team_id sarà NULL. Considera di aggiungere questa squadra al TeamSeeder.');
            }
        }
        
        $qti = $normalizedRow['qti'] ?? $normalizedRow['qt_i'] ?? null;
        $qta = $normalizedRow['qta'] ?? $normalizedRow['qt_a'] ?? null;
        
        $playerData = [
            'name'              => $nome,
            'team_id'           => $teamId,
            'team_name'         => $teamName, // **CORREZIONE: Ri-aggiunto team_name**
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
            
            $isDirtyExceptTimestamps = false;
            if (!$player->wasRecentlyCreated) {
                $changes = $player->getChanges();
                if (isset($changes['updated_at'])) unset($changes['updated_at']);
                if (!empty($changes)) $isDirtyExceptTimestamps = true;
            }
            
            if ($player->wasRecentlyCreated) {
                $this->createdCount++;
            } elseif ($isDirtyExceptTimestamps) {
                $this->updatedCount++;
            }
            
            if ($player->trashed() && !$player->wasRecentlyCreated) {
                $player->restore();
            }
            return $player;
            
        } catch (Throwable $exception) {
            Log::error('TuttiSheetImport@model (Roster): EXCEPTION for fanta_platform_id: ' . $fantaPlatformId . '. Msg: ' . $exception->getMessage() . '. Data: ' . json_encode($row));
            throw $exception;
        }
    }
    
    public function onError(Throwable $e)
    {
        Log::error('TuttiSheetImport@onError (Roster): ' . $e->getMessage());
    }
    
    public function getProcessedCount(): int { return $this->processedCount; }
    public function getCreatedCount(): int { return $this->createdCount; }
    public function getUpdatedCount(): int { return $this->updatedCount; }
}
