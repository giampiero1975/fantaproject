<?php

namespace App\Imports;

use App\Models\HistoricalPlayerStat;
use App\Models\Team; // Importa il modello Team
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Throwable;

class TuttiHistoricalStatsImport implements ToModel, WithHeadingRow, SkipsOnError
{
    private string $seasonYearToImport;
    private static bool $keysLoggedForHistoricalImport = false;
    private int $rowDataRowCount = 0;
    
    public int $processedCount = 0;
    public int $createdCount = 0;
    public int $updatedCount = 0;
    
    private const CLASSIC_ROLE_MAP = [
        0 => 'P', 1 => 'D', 2 => 'C', 3 => 'A'
    ];
    
    public function __construct(string $seasonYear)
    {
        $this->seasonYearToImport = $seasonYear;
        self::$keysLoggedForHistoricalImport = false;
        $this->rowDataRowCount = 0;
        $this->processedCount = 0;
        $this->createdCount = 0;
        $this->updatedCount = 0;
    }
    
    public function headingRow(): int
    {
        return 2;
    }
    
    public function model(array $row)
    {
        $this->rowDataRowCount++;
        
        if (!self::$keysLoggedForHistoricalImport && !empty($row)) {
            Log::info('TuttiHistoricalStatsImport@model: CHIAVI RICEVUTE (dalla riga d\'intestazione Excel #' . $this->headingRow() . '): ' . json_encode(array_keys($row)));
            self::$keysLoggedForHistoricalImport = true;
        }
        
        // Non loggare ogni riga in produzione
        // if ($this->rowDataRowCount <= 3 || $this->rowDataRowCount % 100 == 0) {
        //     Log::info('TuttiHistoricalStatsImport@model: Processing Excel data row #' . $this->rowDataRowCount . ' (Excel file row #' . ($this->rowDataRowCount + $this->headingRow()) . ') Data: ' . json_encode($row));
        // }
        
        $normalizedRow = [];
        foreach ($row as $key => $value) {
            $normalizedKey = strtolower( (string) $key);
            $normalizedKey = preg_replace('/[\s.]+/', '_', $normalizedKey);
            if ($normalizedKey === 'r_') {
                if (str_ends_with(strtolower( (string) $key), '+')) $normalizedKey = 'r+';
                if (str_ends_with(strtolower( (string) $key), '-')) $normalizedKey = 'r-';
            }
            $normalizedRow[$normalizedKey] = $value;
        }
        
        $playerId = $normalizedRow['id'] ?? null;
        $playerName = $normalizedRow['nome'] ?? null;
        
        if ($playerId === null || $playerName === null || trim((string)$playerName) === '') {
            Log::warning('TuttiHistoricalStatsImport@model: RIGA SALTATA (Excel file row #' . ($this->rowDataRowCount + $this->headingRow()) . ') per mancanza di "id" o "nome". Dati Normalizzati: ' . json_encode($normalizedRow));
            return null;
        }
        
        $this->processedCount++;
        $playerId = trim((string)$playerId);
        $playerName = trim((string)$playerName);
        
        $teamName = isset($normalizedRow['squadra']) ? trim((string)$normalizedRow['squadra']) : null;
        $teamId = null;
        if ($teamName) {
            $team = Team::where('name', $teamName)->first();
            if ($team) {
                $teamId = $team->id;
            } else {
                Log::warning('TuttiHistoricalStatsImport@model (Stats): Squadra "' . $teamName . '" non trovata nel DB per stats giocatore ID ' . $playerId . '. team_id sarà NULL. Il nome originale "' . $teamName . '" verrà usato per team_name_for_season.');
            }
        }
        
        $classicRole = null;
        if (isset($normalizedRow['r'])) {
            $r_value_from_row = $normalizedRow['r'];
            $rValueAsString = trim((string)$r_value_from_row);
            if ($rValueAsString !== '') {
                if (is_numeric($rValueAsString)) {
                    $rNumericValue = (int)$rValueAsString;
                    if (array_key_exists($rNumericValue, self::CLASSIC_ROLE_MAP)) {
                        $classicRole = self::CLASSIC_ROLE_MAP[$rNumericValue];
                    } else {
                        // Log::warning('TuttiHistoricalStatsImport@model: Player ID ' . $playerId . ' - Valore numerico "r" (' . $rNumericValue . ') NON TROVATO in CLASSIC_ROLE_MAP. ClassicRole impostato a NULL.');
                    }
                } else {
                    $rawRUpper = strtoupper($rValueAsString);
                    if (in_array($rawRUpper, ['P', 'D', 'C', 'A'])) {
                        $classicRole = $rawRUpper;
                    } else {
                        // Log::warning('TuttiHistoricalStatsImport@model: Player ID ' . $playerId . ' - Valore "r" non standard e non numerico (' . $rValueAsString . '). ClassicRole impostato a NULL.');
                    }
                }
            }
        }
        
        $mantraRoleValueToStore = null;
        if (isset($normalizedRow['rm'])) {
            $rawRmValue = trim((string)$normalizedRow['rm']);
            if (!empty($rawRmValue)) {
                if (strpos($rawRmValue, ';') !== false) {
                    $mantraRolesArray = array_map('trim', explode(';', $rawRmValue));
                    $mantraRolesArray = array_filter($mantraRolesArray, fn($value) => $value !== '');
                    if (!empty($mantraRolesArray)) {
                        $mantraRoleValueToStore = json_encode(array_values($mantraRolesArray));
                    }
                } else {
                    $mantraRoleValueToStore = $rawRmValue;
                }
            }
        }
        
        // Log::info('Player ID ' . $playerId . ' (' . $playerName . '): ClassicRole finale: ' . ($classicRole ?? 'NULL') . ', MantraRole da salvare: ' . ($mantraRoleValueToStore ?? 'NULL'));
        
        $dataToInsert = [
            'player_fanta_platform_id' => (int)$playerId,
            'season_year'              => $this->seasonYearToImport,
            'team_id'                  => $teamId,
            'team_name_for_season'     => $teamName, // **CORREZIONE: Popola team_name_for_season**
            'role_for_season'          => $classicRole,
            'mantra_role_for_season'   => $mantraRoleValueToStore,
            'games_played'             => isset($normalizedRow['pv']) && is_numeric($normalizedRow['pv']) ? (int)$normalizedRow['pv'] : 0,
            'avg_rating'               => isset($normalizedRow['mv']) && trim((string)$normalizedRow['mv']) !== '' ? (float)str_replace(',', '.', (string)$normalizedRow['mv']) : null,
            'fanta_avg_rating'         => isset($normalizedRow['fm']) && trim((string)$normalizedRow['fm']) !== '' ? (float)str_replace(',', '.', (string)$normalizedRow['fm']) : null,
            'goals_scored'             => isset($normalizedRow['gf']) && is_numeric($normalizedRow['gf']) ? (int)$normalizedRow['gf'] : 0,
            'goals_conceded'           => isset($normalizedRow['gs']) && is_numeric($normalizedRow['gs']) ? (int)$normalizedRow['gs'] : 0,
            'penalties_saved'          => isset($normalizedRow['rp']) && is_numeric($normalizedRow['rp']) ? (int)$normalizedRow['rp'] : 0,
            'penalties_taken'          => isset($normalizedRow['rc']) && is_numeric($normalizedRow['rc']) ? (int)$normalizedRow['rc'] : 0,
            'penalties_scored'         => isset($normalizedRow['r+']) && is_numeric($normalizedRow['r+']) ? (int)$normalizedRow['r+'] : 0,
            'penalties_missed'         => isset($normalizedRow['r-']) && is_numeric($normalizedRow['r-']) ? (int)$normalizedRow['r-'] : 0,
            'assists'                  => isset($normalizedRow['ass']) && is_numeric($normalizedRow['ass']) ? (int)$normalizedRow['ass'] : 0,
            'yellow_cards'             => isset($normalizedRow['amm']) && is_numeric($normalizedRow['amm']) ? (int)$normalizedRow['amm'] : 0,
            'red_cards'                => isset($normalizedRow['esp']) && is_numeric($normalizedRow['esp']) ? (int)$normalizedRow['esp'] : 0,
            'own_goals'                => isset($normalizedRow['au']) && is_numeric($normalizedRow['au']) ? (int)$normalizedRow['au'] : 0,
        ];
        
        try {
            // Chiave per updateOrCreate: assicurati che rifletta l'unicità desiderata.
            // Se team_name_for_season non fa più parte della chiave univoca perché team_id è primario,
            // allora la chiave qui dovrebbe essere basata su player_fanta_platform_id, season_year, team_id.
            // Tuttavia, se team_id può essere null (squadra non trovata), usare team_id nella chiave univoca
            // potrebbe non essere ideale. Bisogna decidere la strategia.
            // Per ora, manteniamo la chiave originale che usava team_name_for_season se la tua migrazione la mantiene.
            // Se hai rimosso team_name_for_season dalla tabella, devi usare team_id qui.
            // Assumendo che team_name_for_season sia ancora nella tabella e parte della chiave univoca:
            $uniqueKeys = [
                'player_fanta_platform_id' => $dataToInsert['player_fanta_platform_id'],
                'season_year'              => $dataToInsert['season_year'],
            ];
            // Se team_name_for_season è ancora una colonna e fa parte della chiave univoca
            if (array_key_exists('team_name_for_season', $dataToInsert)) {
                $uniqueKeys['team_name_for_season'] = $dataToInsert['team_name_for_season'];
            } else if (array_key_exists('team_id', $dataToInsert)) { // Altrimenti usa team_id se team_name_for_season è stato rimosso
                $uniqueKeys['team_id'] = $dataToInsert['team_id'];
            }
            
            
            $historicalStat = HistoricalPlayerStat::updateOrCreate($uniqueKeys, $dataToInsert);
            
            $isDirtyExceptTimestamps = false;
            if (!$historicalStat->wasRecentlyCreated) {
                $changes = $historicalStat->getChanges();
                if (isset($changes['updated_at'])) unset($changes['updated_at']);
                if (!empty($changes)) $isDirtyExceptTimestamps = true;
            }
            
            if ($historicalStat->wasRecentlyCreated) {
                $this->createdCount++;
            } elseif ($isDirtyExceptTimestamps) {
                $this->updatedCount++;
            }
            return $historicalStat;
            
        } catch (Throwable $dbException) {
            Log::error('TuttiHistoricalStatsImport@model DB EXCEPTION for player ID ' . $playerId . ' (' . $playerName . '): ' . $dbException->getMessage() . ' --- Data: ' . json_encode($dataToInsert) . ' --- Trace: ' . substr($dbException->getTraceAsString(),0, 500));
            throw $dbException;
        }
    }
    
    public function onError(Throwable $e)
    {
        Log::error('TuttiHistoricalStatsImport@onError: ' . $e->getMessage());
    }
    
    public function getProcessedCount(): int { return $this->processedCount; }
    public function getCreatedCount(): int { return $this->createdCount; }
    public function getUpdatedCount(): int { return $this->updatedCount; }
}
