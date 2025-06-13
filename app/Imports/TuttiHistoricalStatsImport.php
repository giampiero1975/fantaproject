<?php

namespace App\Imports;

use App\Models\HistoricalPlayerStat;
use App\Traits\FindsTeam; // <-- Assicurati che sia importato
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Throwable;

// Rimuovi ToModel e WithHeadingRow, usa ToCollection e WithStartRow
class TuttiHistoricalStatsImport implements ToCollection, WithStartRow, SkipsOnError
{
    use FindsTeam;
    
    // Le proprietà rimangono simili, ma usiamo quelle della nuova versione
    protected $season;
    protected $leagueName;
    
    // Contatori per il log finale
    public int $processedCount = 0;
    public int $createdCount = 0;
    public int $updatedCount = 0;
    
    // Il costruttore ora deve inizializzare anche il trait
    public function __construct(int $season, string $leagueName)
    {
        //dd(get_class_methods($this));
        $this->season = $season;
        $this->leagueName = $leagueName;
        $this->preloadTeams(); 
        
        $this->processedCount = 0;
        $this->createdCount = 0;
        $this->updatedCount = 0;
    }
    
    /**
     * Specifica da quale riga iniziare (3, per saltare titolo e intestazioni)
     * Questa funzione viene dalla vecchia logica ed è corretta.
     */
    public function startRow(): int
    {
        return 3;
    }
    
    /**
     * Metodo collection che prende il posto del vecchio metodo model()
     */
    public function collection(Collection $rows)
    {
        Log::info("[IMPORT STORICO] Inizio elaborazione di " . $rows->count() . " righe.");
        
        foreach ($rows as $rowIndex => $rowDataArray) {
            $excelActualRowNumber = $this->startRow() + $rowIndex;
            
            // Manteniamo la logica di validazione posizionale del vecchio file
            if (count($rowDataArray) < 18) {
                Log::warning("[IMPORT STORICO] RIGA SALTATA (Excel #{$excelActualRowNumber}) per numero insufficiente di colonne.");
                continue;
            }
            
            $playerIdRaw = $rowDataArray[0] ?? null;
            $playerNameRaw = $rowDataArray[3] ?? null;
            
            if ($playerIdRaw === null || trim((string)$playerIdRaw) === '' || $playerNameRaw === null || trim((string)$playerNameRaw) === '') {
                Log::warning("[IMPORT STORICO] RIGA SALTATA (Excel #{$excelActualRowNumber}) per mancanza di Id o Nome.");
                continue;
            }
            
            $this->processedCount++;
            $playerId = (int)trim((string)$playerIdRaw);
            $playerName = trim((string)$playerNameRaw);
            
            // --- UNIONE CON LA NUOVA LOGICA DEL TRAIT ---
            $teamName = isset($rowDataArray[4]) ? trim((string)$rowDataArray[4]) : null;
            $teamId = null;
            if ($teamName) {
                // Qui usiamo la funzione del trait invece della query diretta
                $teamId = $this->findTeamIdByName($teamName);
                if ($teamId === null) {
                    Log::warning('[IMPORT STORICO] Squadra "' . $teamName . '" (riga ' . $excelActualRowNumber . ') non trovata tramite Trait. team_id sarà NULL.');
                }
            }
            // --- FINE UNIONE ---
            
            // Il resto della logica per ruoli, rigori, etc. può rimanere quella del vecchio file funzionante
            // che hai fornito, dato che era corretta.
            $classicRole = strtoupper(trim((string)($rowDataArray[1] ?? '')));
            if (!in_array($classicRole, ['P', 'D', 'C', 'A'])) $classicRole = null;
            
            // Trova il player nel DB usando l'ID corretto
            // NOTA: Qui associamo lo storico a un 'player_fanta_platform_id'
            // Assicurati che il tuo modello `HistoricalPlayerStat` usi questo campo.
            // Se invece devi prima trovare il `player_id` dalla tabella `players`, la logica va adattata.
            // Assumiamo che il DB si aspetti `player_fanta_platform_id`.
            
            $dataToInsert = [
                'player_fanta_platform_id' => $playerId,
                'season_year'              => $this->season,
                'league_name'              => $this->leagueName, // Aggiunto dalla nuova logica
                'player_name'              => $playerName, // Aggiunto per coerenza
                'team_id'                  => $teamId, // Calcolato dal trait
                'team_name_for_season'     => $teamName,
                'role_for_season'          => $classicRole,
                'games_played'             => ($rowDataArray[5] !== null && is_numeric($rowDataArray[5])) ? (int)$rowDataArray[5] : 0,
                // ... e così via per tutti gli altri campi, usando la logica posizionale
                'avg_rating'        => ($v = $rowDataArray[6] ?? null) ? (float)str_replace(',', '.', $v) : null,
                'fanta_avg_rating'  => ($v = $rowDataArray[7] ?? null) ? (float)str_replace(',', '.', $v) : null,
                'goals_scored'      => ($v = $rowDataArray[8] ?? 0) ? (int)$v : 0,
                'goals_conceded'    => ($v = $rowDataArray[9] ?? 0) ? (int)$v : 0,
                'penalties_saved'   => ($v = $rowDataArray[10] ?? 0) ? (int)$v : 0,
                'penalties_taken'   => ($v = $rowDataArray[11] ?? 0) ? (int)$v : 0,
                'penalties_scored'  => ($v = $rowDataArray[12] ?? 0) ? (int)$v : 0,
                'penalties_missed'  => ($v = $rowDataArray[13] ?? 0) ? (int)$v : 0,
                'assists'           => ($v = $rowDataArray[14] ?? 0) ? (int)$v : 0,
                'yellow_cards'      => ($v = $rowDataArray[15] ?? 0) ? (int)$v : 0,
                'red_cards'         => ($v = $rowDataArray[16] ?? 0) ? (int)$v : 0,
                'own_goals'         => ($v = $rowDataArray[17] ?? 0) ? (int)$v : 0,
            ];
            
            try {
                $historicalStat = HistoricalPlayerStat::updateOrCreate(
                    [
                        'player_fanta_platform_id' => $dataToInsert['player_fanta_platform_id'],
                        'season_year'              => $dataToInsert['season_year'],
                        'team_name_for_season'     => $dataToInsert['team_name_for_season'],
                    ],
                    $dataToInsert
                    );
                
                if ($historicalStat->wasRecentlyCreated) {
                    $this->createdCount++;
                } elseif ($historicalStat->wasChanged()) {
                    $this->updatedCount++;
                }
            } catch (Throwable $dbException) {
                Log::error('[IMPORT STORICO] DB EXCEPTION per player ID ' . $playerId . ': ' . $dbException->getMessage());
                $this->onError($dbException);
            }
        }
    }
    
    public function onError(Throwable $e)
    {
        Log::error('[IMPORT STORICO - onError] Messaggio: ' . $e->getMessage());
    }
    
    // Funzioni per ottenere i contatori
    public function getProcessedCount(): int { return $this->processedCount; }
    public function getCreatedCount(): int { return $this->createdCount; }
    public function getUpdatedCount(): int { return $this->updatedCount; }
}