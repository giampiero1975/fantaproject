<?php

namespace App\Imports;

use App\Models\HistoricalPlayerStat;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Throwable;

class TuttiHistoricalStatsImport implements ToModel, WithHeadingRow, SkipsOnError
{
    private string $seasonYearToImport;
    private static bool $keysLoggedForHistoricalImport = false;
    private int $rowDataRowCount = 0; // Contatore per le righe di dati lette da Excel
    
    // Contatori per ImportLog
    public int $processedCount = 0; // Righe valide che iniziano il processo di model()
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
        // Inizializza i contatori
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
        $this->rowDataRowCount++; // Incrementa per ogni riga letta da Excel dopo l'intestazione
        
        if (!self::$keysLoggedForHistoricalImport && !empty($row)) {
            Log::info('TuttiHistoricalStatsImport@model: CHIAVI RICEVUTE (dalla riga d\'intestazione Excel #' . $this->headingRow() . '): ' . json_encode(array_keys($row)));
            self::$keysLoggedForHistoricalImport = true;
        }
        
        if ($this->rowDataRowCount <= 3 || $this->rowDataRowCount % 100 == 0) {
            Log::info('TuttiHistoricalStatsImport@model: Processing Excel data row #' . $this->rowDataRowCount . ' (Excel file row #' . ($this->rowDataRowCount + $this->headingRow()) . ') Data: ' . json_encode($row));
        }
        
        $normalizedRow = [];
        foreach ($row as $key => $value) {
            $normalizedRow[strtolower( (string) $key)] = $value;
        }
        
        $playerId = $normalizedRow['id'] ?? null;
        $playerName = $normalizedRow['nome'] ?? null;
        
        if ($playerId === null || $playerName === null) {
            Log::warning('TuttiHistoricalStatsImport@model: RIGA SALTATA (Excel file row #' . ($this->rowDataRowCount + $this->headingRow()) . ') per mancanza di "id" o "nome". Dati Normalizzati: ' . json_encode($normalizedRow) );
            return null; // Salta questa riga
        }
        
        $this->processedCount++; // Incrementa solo se la riga ha id e nome
        
        $playerId = trim((string)$playerId);
        $playerName = trim((string)$playerName);
        
        $classicRole = null;
        $mantraRoleValueToStore = null;
        
        if (isset($normalizedRow['r'])) {
            $r_value_from_row = $normalizedRow['r'];
            $rValueAsString = trim((string)$r_value_from_row);
            if ($rValueAsString !== '') {
                if (is_numeric($rValueAsString)) {
                    $rNumericValue = (int)$rValueAsString;
                    if (array_key_exists($rNumericValue, self::CLASSIC_ROLE_MAP)) {
                        $classicRole = self::CLASSIC_ROLE_MAP[$rNumericValue];
                    } else {
                        Log::warning('TuttiHistoricalStatsImport@model: Player ID ' . $playerId . ' - Valore numerico "r" (' . $rNumericValue . ') NON TROVATO in CLASSIC_ROLE_MAP. ClassicRole impostato a NULL.');
                    }
                } else {
                    $rawRUpper = strtoupper($rValueAsString);
                    if (in_array($rawRUpper, ['P', 'D', 'C', 'A'])) {
                        $classicRole = $rawRUpper;
                    } else {
                        Log::warning('TuttiHistoricalStatsImport@model: Player ID ' . $playerId . ' - Valore "r" non standard e non numerico (' . $rValueAsString . '). ClassicRole impostato a NULL.');
                    }
                }
            }
        }
        
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
        
        $dataToInsert = [ /* ... come prima ... */
            'player_fanta_platform_id' => (int)$playerId,
            'season_year'              => $this->seasonYearToImport,
            'team_name_for_season'     => isset($normalizedRow['squadra']) ? trim((string)$normalizedRow['squadra']) : null,
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
            return $historicalStat;
            
        } catch (Throwable $dbException) {
            Log::error('TuttiHistoricalStatsImport@model DB EXCEPTION for player ID ' . $playerId . ' (' . $playerName . '): ' . $dbException->getMessage() . ' --- Data: ' . json_encode($dataToInsert) . ' --- Trace: ' . substr($dbException->getTraceAsString(),0, 500));
            throw $dbException;
        }
    }
    
    public function onError(Throwable $e)
    {
        Log::error('TuttiHistoricalStatsImport@onError: Errore durante processamento riga (saltata). Messaggio: ' . $e->getMessage());
    }
    
    // Metodi Getter per i contatori
    public function getProcessedCount(): int { return $this->processedCount; }
    public function getCreatedCount(): int { return $this->createdCount; }
    public function getUpdatedCount(): int { return $this->updatedCount; }
}