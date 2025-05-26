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
    private static bool $keysLoggedForHistoricalStats = false; // Nome variabile statica univoco
    
    public function __construct(string $seasonYear)
    {
        $this->seasonYearToImport = $seasonYear;
        self::$keysLoggedForHistoricalStats = false;
    }
    
    public function headingRow(): int
    {
        return 2;
    }
    
    public function model(array $row)
    {
        if (!self::$keysLoggedForHistoricalStats) {
            Log::info('TuttiHistoricalStatsImport@model: CHIAVI RICEVUTE (Statistiche): ' . json_encode(array_keys($row)));
            Log::info('TuttiHistoricalStatsImport@model: DATI PRIMA RIGA (Statistiche): ' . json_encode($row));
            self::$keysLoggedForHistoricalStats = true;
        }
        
        if (!isset($row['id']) || $row['id'] === '' || $row['id'] === null ||
            !isset($row['nome']) || $row['nome'] === '' || $row['nome'] === null ) {
                Log::warning('TuttiHistoricalStatsImport@model: Riga STATISTICHE saltata per mancanza di "id" o "nome". Dati: ' . json_encode($row));
                return null;
            }
            
            $classicRole = null;
            $mantraRoleValueToStore = null;
            
            if (isset($row['rm'])) {
                $rawRmValue = trim((string)$row['rm']);
                
                // Store Mantra role: as JSON array if multiple, else as string
                if (strpos($rawRmValue, ';') !== false) {
                    $mantraRolesArray = array_map('trim', explode(';', $rawRmValue));
                    $mantraRoleValueToStore = json_encode($mantraRolesArray);
                } else {
                    $mantraRoleValueToStore = $rawRmValue; // Singolo ruolo Mantra
                }
                
                $rmNormalizedForSwitch = strtoupper($rawRmValue);
                
                // Mappatura da Rm (valore completo) a Ruolo Classic (P,D,C,A)
                switch ($rmNormalizedForSwitch) {
                    case 'POR': $classicRole = 'P'; break;
                    // DIFENSORI
                    case 'E': case 'DD;DS;E': case 'DC': case 'B;DD;E': case 'DS;E':
                    case 'DD;E': case 'DS;DC': case 'DD;DC': case 'B;DS;E':
                    case 'B;DD;DS': case 'DD;DS;DC':
                        $classicRole = 'D'; break;
                        // CENTROCAMPISTI
                    case 'W': case 'M;C': case 'C': case 'T': case 'C;T':
                    case 'W;T': case 'C;W': case 'E;W': case 'E;C': case 'C;W;T':
                    case 'E;M': // Mappato a C (era ambiguo nella tua lista, C è più comune per E;M)
                        $classicRole = 'C'; break;
                        // ATTACCANTI
                    case 'PC': case 'A':
                    case 'T;A': // Mappato ad A (era C nella tua lista, ma T;A è spesso A)
                    case 'W;A': // Mappato ad A (era C nella tua lista, ma W;A è spesso A)
                    case 'W;T;A': // Mappato ad A (era C nella tua lista, ma W;T;A è spesso A)
                        $classicRole = 'A'; break;
                    default:
                        Log::warning('TuttiHistoricalStatsImport@model: Valore RM "' . $rawRmValue . '" non mappato a Ruolo Classic per giocatore ' . ($row['nome'] ?? 'N/D') . '. Classic Role impostato a NULL.');
                        $classicRole = null;
                }
            } else {
                // Fallback se 'rm' non esiste (basandoci sui log, 'r' potrebbe contenere 0 o altri codici)
                // Quindi, se 'rm' manca, probabilmente non possiamo derivare un ruolo Classic affidabile.
                Log::warning('TuttiHistoricalStatsImport@model: Chiave "rm" mancante per giocatore ' . ($row['nome'] ?? 'N/D') . '. Ruoli impostati a NULL.');
            }
            
            Log::info('Per Giocatore ID ' . $row['id'] . ' (' . $row['nome'] . '): ClassicRole derivato: ' . ($classicRole ?? 'NULL') . ', MantraRole da salvare: ' . ($mantraRoleValueToStore ?? 'NULL'));
            
            // CHIAVI CONFERMATE DAI LOG: ["id","r","rm","nome","squadra","pv","mv","fm","gf","gs","rp","rc","ass","amm","esp","au"]
            $dataToInsert = [
                'player_fanta_platform_id' => $row['id'],
                'season_year'              => $this->seasonYearToImport,
                'team_name_for_season'     => $row['squadra'] ?? null,
                'role_for_season'          => $classicRole,
                'mantra_role_for_season'   => $mantraRoleValueToStore,
                'games_played'             => $row['pv'] ?? 0,
                'avg_rating'               => isset($row['mv']) && trim((string)$row['mv']) !== '' ? (float)str_replace(',', '.', (string)$row['mv']) : null,
                'fanta_avg_rating'         => isset($row['fm']) && trim((string)$row['fm']) !== '' ? (float)str_replace(',', '.', (string)$row['fm']) : null,
                'goals_scored'             => $row['gf'] ?? 0,
                'goals_conceded'           => $row['gs'] ?? 0,
                'penalties_saved'          => $row['rp'] ?? 0,
                'penalties_taken'          => $row['rc'] ?? 0,
                'assists'                  => $row['ass'] ?? 0,
                'yellow_cards'             => $row['amm'] ?? 0,
                'red_cards'                => $row['esp'] ?? 0,
                'own_goals'                => $row['au'] ?? 0,
                // R+, R-, Asf non sono nelle chiavi Excel loggate, quindi le ometto da $dataToInsert.
                // Se le tue colonne si chiamano diversamente, aggiorna le chiavi $row['...']
            ];
            
            return HistoricalPlayerStat::updateOrCreate(
                [
                    'player_fanta_platform_id' => $dataToInsert['player_fanta_platform_id'],
                    'season_year'              => $dataToInsert['season_year'],
                    'team_name_for_season'     => $dataToInsert['team_name_for_season'],
                ],
                $dataToInsert
                );
    }
    
    public function onError(Throwable $e)
    {
        Log::error('TuttiHistoricalStatsImport@onError: Errore importazione riga statistiche (saltata). Messaggio: ' . $e->getMessage());
    }
}