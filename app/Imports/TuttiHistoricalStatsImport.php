<?php

namespace App\Imports;

use App\Models\HistoricalPlayerStat;
use App\Models\Team;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Throwable;

class TuttiHistoricalStatsImport implements ToModel, WithHeadingRow, SkipsOnError
{
    private string $seasonYearToImport;
    private int $rowDataRowCount = 0;
    private bool $headerKeysLoggedThisFile = false; // Flag per loggare le chiavi una volta per file
    
    public int $processedCount = 0;
    public int $createdCount = 0;
    public int $updatedCount = 0;
    
    private const CLASSIC_ROLE_MAP = [
        0 => 'P', 1 => 'D', 2 => 'C', 3 => 'A'
    ];
    
    public function __construct(string $seasonYear)
    {
        $this->seasonYearToImport = $seasonYear;
        $this->rowDataRowCount = 0;
        $this->headerKeysLoggedThisFile = false; // Resetta per ogni nuova istanza/importazione
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
        $actualFileRowNumber = $this->rowDataRowCount + $this->headingRow();
        
        if (!$this->headerKeysLoggedThisFile && !empty($row)) {
            Log::info("[IMPORT STORICO] CHIAVI GREZZE RICEVUTE dal file per stagione {$this->seasonYearToImport} (Excel Riga Intestazione #" . $this->headingRow() . "): " . json_encode(array_keys($row)));
            $this->headerKeysLoggedThisFile = true;
        }
        
        $normalizedRow = [];
        $rawKeysRead = array_keys($row);
        
        $keyMap = [
            'id' => ['id'],
            'r' => ['r', 'ruolo'],
            'rm' => ['rm', 'ruolo mantra'],
            'nome' => ['nome', 'giocatore'],
            'squadra' => ['squadra', 'sq'],
            'pv' => ['pv', 'pg', 'presenze'],
            'mv' => ['mv', 'media voto'],
            'fm' => ['fm', 'fantamedia', 'mf'],
            'gf' => ['gf', 'gol fatti'],
            'gs' => ['gs'],
            'rp' => ['rp', 'rig p', 'rigori parati'],
            'rc' => ['rc', 'rig c', 'rigori calciati'],
            'r+' => ['r+'],
            'r-' => ['r-'],
            'r-' => ['rp', 'rig p', 'rigori parati'],
            'r+' => ['rc', 'rig c', 'rigori calciati'],
            'ass' => ['ass', 'assist'],
            'amm' => ['amm', 'ammonizioni'],
            'esp' => ['esp', 'espulsioni'],
            'au' => ['au', 'autogol']
        ];
        
        foreach ($rawKeysRead as $rawKey) {
            $processedKey = strtolower(trim((string)$rawKey));
            $foundStandardKey = null;
            foreach ($keyMap as $standardKey => $possibleExcelKeys) {
                if (in_array($processedKey, array_map('strtolower', array_map('trim', $possibleExcelKeys)))) {
                    $normalizedRow[$standardKey] = $row[$rawKey];
                    $foundStandardKey = $standardKey;
                    break;
                }
            }
            if (!$foundStandardKey) {
                $genericNormalizedKey = preg_replace('/[\s.]+/', '_', $processedKey);
                $normalizedRow[$genericNormalizedKey] = $row[$rawKey];
                if ($this->rowDataRowCount === 1) {
                    Log::warning("[IMPORT STORICO] File {$this->seasonYearToImport}: Chiave grezza '{$rawKey}' (normalizzata a '{$genericNormalizedKey}') non mappata esplicitamente. Verificare se necessaria o se la mappatura va estesa.");
                }
            }
        }
        
        if ($this->rowDataRowCount === 1) {
            Log::info("[IMPORT STORICO] File {$this->seasonYearToImport}, Excel Riga Dati #" . $this->rowDataRowCount . " (File Riga #" . $actualFileRowNumber . ") CHIAVI NORMALIZZATE FINALI: " . json_encode(array_keys($normalizedRow)) . ' -- VALORI: ' . json_encode($normalizedRow));
        }
        
        // Sostituzione di ?? con operatore ternario
        $playerId = isset($normalizedRow['id']) ? $normalizedRow['id'] : null;
        $playerName = isset($normalizedRow['nome']) ? $normalizedRow['nome'] : null;
        
        if ($playerId === null || $playerName === null || trim((string)$playerName) === '') {
            Log::warning("[IMPORT STORICO] RIGA SALTATA (File Riga #" . $actualFileRowNumber . ") per mancanza di 'id' o 'nome'. Dati Normalizzati: " . json_encode($normalizedRow));
            return null;
        }
        
        $this->processedCount++;
        $playerId = trim((string)$playerId);
        $playerName = trim((string)$playerName);
        
        // Sostituzione di ?? con operatore ternario per il Log::debug
        $log_r_val = isset($normalizedRow['r']) ? $normalizedRow['r'] : 'N/A';
        $log_rm_val = isset($normalizedRow['rm']) ? $normalizedRow['rm'] : 'N/A';
        $log_rc_val = isset($normalizedRow['rc']) ? $normalizedRow['rc'] : 'N/A';
        $log_r_plus_val = isset($normalizedRow['r+']) ? $normalizedRow['r+'] : 'CHIAVE R+ NON TROVATA';
        $log_r_minus_val = isset($normalizedRow['r-']) ? $normalizedRow['r-'] : 'CHIAVE R- NON TROVATA';
        $log_gf_val = isset($normalizedRow['gf']) ? $normalizedRow['gf'] : 'N/A';
        $log_gs_val = isset($normalizedRow['gs']) ? $normalizedRow['gs'] : 'N/A';
        Log::debug("[IMPORT STORICO DEBUG] ID:{$playerId} Nome:{$playerName} -- RAW MAPPED VALUES -- R:'{$log_r_val}' Rm:'{$log_rm_val}' Rc:'{$log_rc_val}' R+:'{$log_r_plus_val}' R-:'{$log_r_minus_val}' Gf:'{$log_gf_val}' Gs:'{$log_gs_val}'");
        
        $classicRole = null;
        // Sostituzione di ?? con operatore ternario
        $roleValueFromNormalized = isset($normalizedRow['r']) ? $normalizedRow['r'] : null;
        if ($roleValueFromNormalized !== null && trim((string)$roleValueFromNormalized) !== '') {
            $roleValueTrimmed = trim((string)$roleValueFromNormalized);
            if (is_numeric($roleValueTrimmed) && isset(self::CLASSIC_ROLE_MAP[(int)$roleValueTrimmed])) {
                $classicRole = self::CLASSIC_ROLE_MAP[(int)$roleValueTrimmed];
            } elseif (in_array(strtoupper($roleValueTrimmed), ['P', 'D', 'C', 'A'])) {
                $classicRole = strtoupper($roleValueTrimmed);
            } else {
                Log::warning("[IMPORT STORICO] ID:{$playerId} Nome:{$playerName} - Ruolo classico non riconosciuto: '{$roleValueTrimmed}'. Lasciato NULL.");
            }
        }
        Log::debug("[IMPORT STORICO DEBUG] ID:{$playerId} Nome:{$playerName} -- Classic Role DERIVED: " . ($classicRole ? $classicRole : 'NULL'));
        
        
        $mantraRoleValueToStore = null;
        // Sostituzione di ?? con operatore ternario
        $mantraValueFromNormalized = isset($normalizedRow['rm']) ? $normalizedRow['rm'] : null;
        if ($mantraValueFromNormalized !== null && trim((string)$mantraValueFromNormalized) !== '') {
            $rawRmValue = trim((string)$mantraValueFromNormalized);
            if (strpos($rawRmValue, ';') !== false) {
                $mantraRolesArray = array_map('trim', explode(';', $rawRmValue));
                $mantraRolesArray = array_filter($mantraRolesArray, fn($value) => !empty($value));
                if (!empty($mantraRolesArray)) {
                    $mantraRoleValueToStore = json_encode(array_values($mantraRolesArray));
                }
            } else {
                $mantraRoleValueToStore = $rawRmValue;
            }
        }
        Log::debug("[IMPORT STORICO DEBUG] ID:{$playerId} Nome:{$playerName} -- Mantra Role DERIVED to store: " . ($mantraRoleValueToStore ? $mantraRoleValueToStore : 'NULL'));
        
        
        $teamName = isset($normalizedRow['squadra']) ? trim((string)$normalizedRow['squadra']) : null;
        $teamId = null;
        if ($teamName) {
            $team = Team::where('name', $teamName)->orWhere('short_name', $teamName)->first();
            if ($team) {
                $teamId = $team->id;
            } else {
                Log::warning('[IMPORT STORICO] Squadra "' . $teamName . '" non trovata nel DB per stats giocatore ID ' . $playerId . '. team_id sarà NULL.');
            }
        }
        
        $penTaken = isset($normalizedRow['rc']) && is_numeric($normalizedRow['rc']) ? (int)$normalizedRow['rc'] : 0;
        $penScored = 0;
        if (array_key_exists('r+', $normalizedRow)) {
            $rPlusValue = $normalizedRow['r+'];
            if (is_numeric($rPlusValue)) {
                $penScored = (int)$rPlusValue;
            } else if ($rPlusValue !== null && $rPlusValue !== '') {
                Log::warning("[IMPORT STORICO] ID:{$playerId} Nome:{$playerName} - Valore non numerico per R+ (rigori segnati): '{$rPlusValue}'. Impostato a 0.");
            }
        } else {
            if ($this->rowDataRowCount === 1) Log::warning("[IMPORT STORICO] File {$this->seasonYearToImport}: Chiave 'r+' (Rigori Segnati) non presente nelle intestazioni mappate per la riga #{$actualFileRowNumber}. 'penalties_scored' sarà 0.");
        }
        
        $penMissed = 0;
        if (array_key_exists('r-', $normalizedRow)) {
            $rMinusValue = $normalizedRow['r-'];
            if (is_numeric($rMinusValue)) {
                $penMissed = (int)$rMinusValue;
            } else if ($rMinusValue !== null && $rMinusValue !== '') {
                Log::warning("[IMPORT STORICO] ID:{$playerId} Nome:{$playerName} - Valore non numerico per R- (rigori sbagliati): '{$rMinusValue}'. Impostato a 0.");
            }
        } else {
            if ($penTaken >= $penScored) {
                $penMissed = $penTaken - $penScored;
                Log::info("[IMPORT STORICO DEBUG] ID:{$playerId} - R- calcolato: {$penMissed} (da {$penTaken} presi e {$penScored} segnati) perché chiave 'r-' non trovata.");
            } else if ($this->rowDataRowCount === 1) {
                Log::warning("[IMPORT STORICO] File {$this->seasonYearToImport}: Chiave 'r-' (Rigori Sbagliati) non trovata e non calcolabile consistentemente per riga #{$actualFileRowNumber}. 'penalties_missed' sarà 0.");
            }
        }
        Log::debug("[IMPORT STORICO DEBUG] ID:{$playerId} Nome:{$playerName} -- Rigori DERIVED -- Taken:{$penTaken}, Scored:{$penScored}, Missed:{$penMissed}");
        
        $goalsScored = isset($normalizedRow['gf']) && is_numeric($normalizedRow['gf']) ? (int)$normalizedRow['gf'] : 0;
        $goalsConceded = 0;
        if ($classicRole === 'P') {
            if (isset($normalizedRow['gs']) && is_numeric($normalizedRow['gs'])) {
                $goalsConceded = (int)$normalizedRow['gs'];
            }
        }
        
        $dataToInsert = [
            'player_fanta_platform_id' => (int)$playerId,
            'season_year'              => $this->seasonYearToImport,
            'team_id'                  => $teamId,
            'team_name_for_season'     => $teamName,
            'role_for_season'          => $classicRole,
            'mantra_role_for_season'   => $mantraRoleValueToStore,
            'games_played'             => isset($normalizedRow['pv']) && is_numeric($normalizedRow['pv']) ? (int)$normalizedRow['pv'] : 0,
            'avg_rating'               => isset($normalizedRow['mv']) && trim((string)$normalizedRow['mv']) !== '' && is_numeric(str_replace(',', '.', (string)$normalizedRow['mv'])) ? (float)str_replace(',', '.', (string)$normalizedRow['mv']) : null,
            'fanta_avg_rating'         => isset($normalizedRow['fm']) && trim((string)$normalizedRow['fm']) !== '' && is_numeric(str_replace(',', '.', (string)$normalizedRow['fm'])) ? (float)str_replace(',', '.', (string)$normalizedRow['fm']) : null,
            'goals_scored'             => $goalsScored,
            'goals_conceded'           => $goalsConceded,
            'penalties_saved'          => isset($normalizedRow['rp']) && is_numeric($normalizedRow['rp']) ? (int)$normalizedRow['rp'] : 0,
            'penalties_taken'          => $penTaken,
            'penalties_scored'         => $penScored,
            'penalties_missed'         => $penMissed,
            'assists'                  => isset($normalizedRow['ass']) && is_numeric($normalizedRow['ass']) ? (int)$normalizedRow['ass'] : 0,
            'yellow_cards'             => isset($normalizedRow['amm']) && is_numeric($normalizedRow['amm']) ? (int)$normalizedRow['amm'] : 0,
            'red_cards'                => isset($normalizedRow['esp']) && is_numeric($normalizedRow['esp']) ? (int)$normalizedRow['esp'] : 0,
            'own_goals'                => isset($normalizedRow['au']) && is_numeric($normalizedRow['au']) ? (int)$normalizedRow['au'] : 0,
        ];
        
        try {
            $uniqueKeys = [
                'player_fanta_platform_id' => $dataToInsert['player_fanta_platform_id'],
                'season_year'              => $dataToInsert['season_year'],
                'team_name_for_season'     => $dataToInsert['team_name_for_season'],
            ];
            $historicalStat = HistoricalPlayerStat::updateOrCreate($uniqueKeys, $dataToInsert);
            
            if ($historicalStat->wasRecentlyCreated) {
                $this->createdCount++;
            } else {
                $changes = $historicalStat->getChanges();
                if (isset($changes['updated_at']) && count($changes) === 1) {
                    // No significant change
                } elseif (!empty($changes)) {
                    $this->updatedCount++;
                    Log::info("[IMPORT STORICO] ID:{$playerId} Nome:{$playerName} - Record AGGIORNATO. Modifiche: " . json_encode($changes));
                }
            }
            return $historicalStat;
            
        } catch (Throwable $dbException) {
            Log::error('[IMPORT STORICO] DB EXCEPTION per player ID ' . $playerId . ' (' . $playerName . ') Stagione ' . $this->seasonYearToImport . ' Squadra ' . $teamName . ': ' . $dbException->getMessage() . ' --- Data: ' . json_encode($dataToInsert));
            $this->onError($dbException);
            return null;
        }
    }
    
    public function onError(Throwable $e)
    {
        Log::error('[IMPORT STORICO] onError Maatwebsite: ' . $e->getMessage());
    }
    
    public function getProcessedCount(): int { return $this->processedCount; }
    public function getCreatedCount(): int { return $this->createdCount; }
    public function getUpdatedCount(): int { return $this->updatedCount; }
}
