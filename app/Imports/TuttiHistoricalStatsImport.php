<?php

namespace App\Imports;

use App\Models\HistoricalPlayerStat;
use App\Models\Team;
use Illuminate\Support\Collection; // Assicurati che sia importato
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection; // Cambiamo a ToCollection
use Maatwebsite\Excel\Concerns\WithStartRow;   // Per saltare le righe di intestazione
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Throwable;

class TuttiHistoricalStatsImport implements ToCollection, WithStartRow, SkipsOnError
{
    private string $seasonYearToImport;
    
    public int $processedCount = 0;
    public int $createdCount = 0;
    public int $updatedCount = 0;
        
    public function __construct(string $seasonYear)
    {
        $this->seasonYearToImport = $seasonYear;
        $this->processedCount = 0;
        $this->createdCount = 0;
        $this->updatedCount = 0;
    }
    
    /**
     * Specifica da quale riga iniziare a leggere i dati (1-based).
     * Riga 1: Titolo generale (saltata)
     * Riga 2: Intestazioni effettive (saltata)
     * Riga 3: Inizio dei dati effettivi
     */
    public function startRow(): int
    {
        return 3;
    }
    
    /**
     * @param Collection $rows La collezione di righe lette dal file Excel.
     * Ogni elemento della collezione ($row) dovrebbe essere un array numerico.
     */
    public function collection(Collection $rows)
    {
        Log::info("[IMPORT STORICO - ToCollection] Numero di righe ricevute (dalla startRow in poi): " . $rows->count());
        
        foreach ($rows as $rowIndex => $rowDataArray) {
            // $rowDataArray è già un array numerico qui grazie a ToCollection
            $excelActualRowNumber = $this->startRow() + $rowIndex; // Calcola il numero di riga Excel effettivo
            
            Log::debug("[IMPORT STORICO - ToCollection] Riga Excel #{$excelActualRowNumber} - Dati letti: " . json_encode($rowDataArray));
            
            // Accesso ai dati tramite indice (0-based)
            // Id:0, R:1, Rm:2, Nome:3, Squadra:4, Pv:5, Mv:6, Fm:7, Gf:8, Gs:9, Rp:10, Rc:11, R+:12, R-:13, Ass:14, Amm:15, Esp:16, Au:17
            if (count($rowDataArray) < 18) { // L'ultima colonna attesa è Au all'indice 17
                Log::warning("[IMPORT STORICO - ToCollection] RIGA SALTATA (Excel #{$excelActualRowNumber}) per numero insufficiente di colonne: " . count($rowDataArray) . " (attese almeno 18). Dati: " . json_encode($rowDataArray));
                continue;
            }
            
            $playerIdRaw = $rowDataArray[0] ?? null;
            $playerNameRaw = $rowDataArray[3] ?? null;
            
            if ($playerIdRaw === null || $playerNameRaw === null || trim((string)$playerNameRaw) === '') {
                Log::warning("[IMPORT STORICO - ToCollection] RIGA SALTATA (Excel #{$excelActualRowNumber}) per mancanza di Id (idx 0) o Nome (idx 3). Dati: " . json_encode($rowDataArray));
                continue;
            }
            
            $this->processedCount++;
            $playerId = trim((string)$playerIdRaw);
            $playerName = trim((string)$playerNameRaw);
            
            // Ruolo (R) - Indice 1. Assumiamo contenga direttamente P, D, C, A.
            $classicRole = null;
            $roleValueRaw = $rowDataArray[1] ?? null;
            if ($roleValueRaw !== null && in_array(strtoupper(trim((string)$roleValueRaw)), ['P', 'D', 'C', 'A'])) {
                $classicRole = strtoupper(trim((string)$roleValueRaw));
            } else {
                Log::warning("[IMPORT STORICO - ToCollection] ID:{$playerId} Nome:{$playerName} - Ruolo (idx 1 -> '{$roleValueRaw}') non valido o mancante. Lasciato NULL.");
            }
            //Log::debug("[IMPORT STORICO - ToCollection] ID:{$playerId} Nome:{$playerName} -- Classic Role (idx 1 -> " . ($roleValueRaw ?? 'NULL') . "): " . ($classicRole ?: 'NULL'));
            
            // Mantra (Rm) - Indice 2
            $mantraRoleValueToStore = null;
            $mantraValueRaw = $rowDataArray[2] ?? null;
            if ($mantraValueRaw !== null && trim((string)$mantraValueRaw) !== '') {
                $rawRmValue = trim((string)$mantraValueRaw);
                if (strpos($rawRmValue, ';') !== false) {
                    $mantraRolesArray = array_map('trim', explode(';', $rawRmValue));
                    $mantraRolesArray = array_filter($mantraRolesArray, fn($value) => !empty($value));
                    $mantraRoleValueToStore = !empty($mantraRolesArray) ? json_encode(array_values($mantraRolesArray)) : null;
                } else {
                    $mantraRoleValueToStore = $rawRmValue;
                }
            }
            //Log::debug("[IMPORT STORICO - ToCollection] ID:{$playerId} Nome:{$playerName} -- Mantra Role (idx 2 -> " . ($mantraValueRaw ?? 'NULL') . "): " . ($mantraRoleValueToStore ?: 'NULL'));
            
            
            // Team - Indice 4
            $teamName = isset($rowDataArray[4]) ? trim((string)$rowDataArray[4]) : null;
            $teamId = null;
            if ($teamName) {
                $team = Team::where('name', $teamName)->orWhere('short_name', $teamName)->first();
                if ($team) $teamId = $team->id;
                else Log::warning('[IMPORT STORICO - ToCollection] Squadra "' . $teamName . '" (idx 4) non trovata. team_id sarà NULL.');
            }
            
            // Rigori
            $penTakenRaw = $rowDataArray[11] ?? null; // Rc
            $rPlusRaw = $rowDataArray[12] ?? null;    // R+
            $rMinusRaw = $rowDataArray[13] ?? null;   // R-
            
            Log::debug("[IMPORT STORICO - ToCollection - LETTURA RIGORI] ID:{$playerId} Nome:{$playerName} -- Rc(idx11):'{$penTakenRaw}', R+(idx12):'{$rPlusRaw}', R-(idx13):'{$rMinusRaw}'");
            
            $penTaken = ($penTakenRaw !== null && is_numeric($penTakenRaw)) ? (int)$penTakenRaw : 0;
            $penScored = ($rPlusRaw !== null && is_numeric($rPlusRaw)) ? (int)$rPlusRaw : 0;
            $penMissed = ($rMinusRaw !== null && is_numeric($rMinusRaw)) ? (int)$rMinusRaw : 0;
            
            if (!($rPlusRaw !== null && is_numeric($rPlusRaw)) && $rPlusRaw !== null && trim((string)$rPlusRaw) !== '') {
                Log::warning("[IMPORT STORICO - ToCollection - RIGORI] ID:{$playerId} Nome:{$playerName} - Valore non numerico per R+ (da indice 12): '{$rPlusRaw}'. Impostato a 0.");
            }
            if (!($rMinusRaw !== null && is_numeric($rMinusRaw)) && $rMinusRaw !== null && trim((string)$rMinusRaw) !== '') {
                Log::warning("[IMPORT STORICO - ToCollection - RIGORI] ID:{$playerId} Nome:{$playerName} - Valore non numerico per R- (da indice 13): '{$rMinusRaw}'. Impostato a 0.");
            }
            
            // Fallback per R- se non è fornito o non è numerico, ma Rc e R+ lo sono.
            if (!($rMinusRaw !== null && is_numeric($rMinusRaw))) {
                if ($penTaken >= $penScored) { // Calcola solo se R- è problematico E Rc e R+ sono validi
                    $penMissed = $penTaken - $penScored;
                    Log::info("[IMPORT STORICO - ToCollection - RIGORI] ID:{$playerId} Nome:{$playerName} - R- (idx 13) non fornito/numerico, CALCOLATO come {$penMissed} da Rc:{$penTaken} e R+:{$penScored}.");
                }
            }
            //Log::debug("[IMPORT STORICO - ToCollection - RIGORI FINALI] ID:{$playerId} Nome:{$playerName} -- Taken:{$penTaken}, Scored:{$penScored}, Missed:{$penMissed}");
            
            $mvValue = $rowDataArray[6] ?? null;
            $fmValue = $rowDataArray[7] ?? null;
            $avgRating = ($mvValue !== null && trim((string)$mvValue) !== '' && is_numeric(str_replace(',', '.', (string)$mvValue))) ? (float)str_replace(',', '.', (string)$mvValue) : null;
            $fantaAvgRating = ($fmValue !== null && trim((string)$fmValue) !== '' && is_numeric(str_replace(',', '.', (string)$fmValue))) ? (float)str_replace(',', '.', (string)$fmValue) : null;
            
            $dataToInsert = [
                'player_fanta_platform_id' => (int)$playerId,
                'season_year'              => $this->seasonYearToImport,
                'team_id'                  => $teamId,
                'team_name_for_season'     => $teamName,
                'role_for_season'          => $classicRole,
                'mantra_role_for_season'   => $mantraRoleValueToStore,
                'games_played'             => ($rowDataArray[5] !== null && is_numeric($rowDataArray[5])) ? (int)$rowDataArray[5] : 0,
                'avg_rating'               => $avgRating,
                'fanta_avg_rating'         => $fantaAvgRating,
                'goals_scored'             => ($rowDataArray[8] !== null && is_numeric($rowDataArray[8])) ? (int)$rowDataArray[8] : 0,
                'goals_conceded'           => ($classicRole === 'P' && isset($rowDataArray[9]) && is_numeric($rowDataArray[9])) ? (int)$rowDataArray[9] : 0,
                'penalties_saved'          => ($rowDataArray[10] !== null && is_numeric($rowDataArray[10])) ? (int)$rowDataArray[10] : 0,
                'penalties_taken'          => $penTaken,
                'penalties_scored'         => $penScored,
                'penalties_missed'         => $penMissed,
                'assists'                  => ($rowDataArray[14] !== null && is_numeric($rowDataArray[14])) ? (int)$rowDataArray[14] : 0,
                'yellow_cards'             => ($rowDataArray[15] !== null && is_numeric($rowDataArray[15])) ? (int)$rowDataArray[15] : 0,
                'red_cards'                => ($rowDataArray[16] !== null && is_numeric($rowDataArray[16])) ? (int)$rowDataArray[16] : 0,
                'own_goals'                => ($rowDataArray[17] !== null && is_numeric($rowDataArray[17])) ? (int)$rowDataArray[17] : 0,
            ];
            
            try {
                $uniqueKeys = [ /* ... come prima ... */ ];
                // ... (logica updateOrCreate come prima) ...
                $historicalStat = HistoricalPlayerStat::updateOrCreate(
                    [
                        'player_fanta_platform_id' => $dataToInsert['player_fanta_platform_id'],
                        'season_year'              => $dataToInsert['season_year'],
                        'team_name_for_season'     => $dataToInsert['team_name_for_season'], // Assumendo che un giocatore sia in una sola squadra per stagione in questi dati storici
                    ],
                    $dataToInsert
                    );
                
                if ($historicalStat->wasRecentlyCreated) {
                    $this->createdCount++;
                } else {
                    if ($historicalStat->wasChanged()) { // Controlla se ci sono state modifiche effettive
                        $this->updatedCount++;
                        Log::info("[IMPORT STORICO - ToCollection] ID:{$playerId} Nome:{$playerName} - Record AGGIORNATO. Modifiche: " . json_encode($historicalStat->getChanges()));
                    }
                }
                
            } catch (Throwable $dbException) {
                Log::error('[IMPORT STORICO - ToCollection] DB EXCEPTION per player ID ' . $playerId . ' (' . $playerName . '): ' . $dbException->getMessage() . ' --- Data: ' . json_encode($dataToInsert), $dbException->getTrace());
                $this->onError($dbException); // Chiama il gestore di errori
            }
        }
    }
    
    public function onError(\Throwable $e)
    {
        // Non abbiamo più $this->excelRowCounter qui perché siamo in ToCollection
        Log::error('[IMPORT STORICO - ToCollection - onError Maatwebsite] Messaggio: ' . $e->getMessage() . '. Trace: ' . substr($e->getTraceAsString(), 0, 1000));
    }
    
    public function getProcessedCount(): int { return $this->processedCount; }
    public function getCreatedCount(): int { return $this->createdCount; }
    public function getUpdatedCount(): int { return $this->updatedCount; }
}