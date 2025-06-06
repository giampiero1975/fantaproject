<?php

namespace App\Services;

use App\Models\Team;
use App\Models\TeamHistoricalStanding;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache; // Cache non era usata in questa versione, ma la teniamo per consistenza
use Illuminate\Support\Str; // Se usi Str per qualche normalizzazione specifica

class TeamDataService
{
    protected PlayerStatsApiService $apiService;
    // Rimosso apiKey e baseUri da qui, poiché PlayerStatsApiService ora gestisce le chiamate dirette.
    // Il TeamDataService ora orchestra e usa PlayerStatsApiService per i dati API.
    
    public function __construct(PlayerStatsApiService $apiService)
    {
        $this->apiService = $apiService;
        Log::info("TeamDataService initializzato con PlayerStatsApiService.");
    }
    
    private function normalizeName(string $name): string
    {
        $normalized = strtolower($name);
        $toRemove = [' fc', ' calcio', ' ac', ' ssc', ' spa', ' 1909', ' 1913', ' 1919', ' asd', 'cf', 'bc', 'us'];
        $normalized = str_replace($toRemove, '', $normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $normalized);
        $normalized = trim($normalized);
        return preg_replace('/\s+/', ' ', $normalized);
    }
    
    public function findTeamByName(string $name, ?string $leagueCode = null): ?Team
    {
        $team = Team::where('name', $name)->first();
        if ($team) {
            return $team;
        }
        $normalizedApiName = $this->normalizeName($name);
        $teamsInDb = Team::all();
        foreach ($teamsInDb as $dbTeam) {
            if ($this->normalizeName($dbTeam->name) === $normalizedApiName) {
                Log::info("TeamDataService: Trovata corrispondenza normalizzata per nome API '{$name}' con DB nome '{$dbTeam->name}'. ID DB: {$dbTeam->id}");
                return $dbTeam;
            }
            if ($dbTeam->short_name && $this->normalizeName($dbTeam->short_name) === $normalizedApiName) {
                Log::info("TeamDataService: Trovata corrispondenza normalizzata per nome API '{$name}' con DB short_name '{$dbTeam->short_name}'. ID DB: {$dbTeam->id}");
                return $dbTeam;
            }
            if ($dbTeam->tla && strtolower($dbTeam->tla) === strtolower($name)) {
                Log::info("TeamDataService: Trovata corrispondenza TLA per nome API '{$name}' con DB TLA '{$dbTeam->tla}'. ID DB: {$dbTeam->id}");
                return $dbTeam;
            }
        }
        Log::warning("TeamDataService: Squadra non trovata per nome '{$name}' (normalizzato: '{$normalizedApiName}')" . ($leagueCode ? " per lega {$leagueCode}" : ""));
        return null;
    }
    
    public function findTeamByApiIdOrName(string $apiId, string $apiName, ?string $leagueCode = null): ?Team
    {
        if (!empty($apiId) && is_numeric($apiId)) {
            $team = Team::where('api_football_data_id', (int)$apiId)->first();
            if ($team) {
                return $team;
            }
        }
        Log::info("TeamDataService: Team non trovato per API ID {$apiId}. Tentativo di fallback per nome API '{$apiName}'.");
        return $this->findTeamByName($apiName, $leagueCode);
    }
    
    public function updateOrCreateTeamFromApiData(array $apiTeamData, string $leagueCode): ?Team
    {
        if (empty($apiTeamData['id']) || !is_numeric($apiTeamData['id'])) {
            Log::error("TeamDataService: ID API mancante o non valido per i dati della squadra ricevuti.", ['apiTeamData' => $apiTeamData]);
            return null;
        }
        
        $apiTeamId = (int)$apiTeamData['id'];
        $teamNameFromApi = $apiTeamData['name'] ?? 'Nome Mancante';
        $shortNameFromApi = $apiTeamData['shortName'] ?? $teamNameFromApi;
        $tlaFromApi = $apiTeamData['tla'] ?? null;
        $crestFromApi = $apiTeamData['crest'] ?? null;
        
        Log::info("TeamDataService: Inizio gestione per API ID {$apiTeamId} ('{$teamNameFromApi}'). Dati API:", $apiTeamData);
        
        $dataForSave = [
            'name' => $teamNameFromApi,
            'short_name' => $shortNameFromApi,
            'tla' => $tlaFromApi,
            'crest_url' => $crestFromApi,
            'league_code' => $leagueCode,
            'api_football_data_id' => $apiTeamId,
        ];
        
        try {
            $team = Team::where('api_football_data_id', $apiTeamId)->first();
            
            if ($team) {
                Log::info("TeamDataService: Trovata squadra esistente per API ID {$apiTeamId}. ID DB: {$team->id}. Nome: '{$team->name}'. Tentativo di aggiornamento.");
                $team->fill($dataForSave);
                if ($team->isDirty()) {
                    $team->save();
                    Log::info("TeamDataService: AGGIORNATA squadra esistente '{$team->name}' (ID DB: {$team->id}, API ID: {$apiTeamId}).");
                } else {
                    Log::info("TeamDataService: Nessun aggiornamento necessario per '{$team->name}' (ID DB: {$team->id}, API ID: {$apiTeamId}). Dati identici.");
                }
            } else {
                Log::info("TeamDataService: Nessuna squadra trovata per API ID {$apiTeamId}. Tentativo di creazione con dati:", $dataForSave);
                $team = Team::create($dataForSave);
                Log::info("TeamDataService: CREATA nuova squadra '{$team->name}' (ID DB: {$team->id}, API ID: {$apiTeamId}).");
            }
            $team->refresh();
            return $team;
            
        } catch (\Illuminate\Database\QueryException $qe) {
            Log::error("TeamDataService: ECCEZIONE Query DB durante operazione per API ID {$apiTeamId} ('{$teamNameFromApi}'). Errore: {$qe->getMessage()}", [
                'sql' => $qe->getSql(),
                'bindings' => $qe->getBindings(),
                'exception_trace' => Str::limit($qe->getTraceAsString(), 1000),
                'apiTeamData' => $apiTeamData
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error("TeamDataService: ECCEZIONE GENERICA durante operazione per API ID {$apiTeamId} ('{$teamNameFromApi}'). Errore: {$e->getMessage()}", [
                'exception_trace' => Str::limit($e->getTraceAsString(), 1000),
                'apiTeamData' => $apiTeamData
            ]);
            return null;
        }
    }
    
    public function saveStandingsFromApiData(array $apiStandingsData, string $competitionCode, string $seasonYear): array
    {
        $savedCount = 0;
        $notFoundInDb = 0;
        
        if (!isset($apiStandingsData['standings'][0]['table'])) {
            Log::warning("TeamDataService: Struttura dati classifiche API inattesa per {$competitionCode} stagione {$seasonYear}. 'table' non trovato.", ['apiData' => $apiStandingsData]);
            return ['saved' => $savedCount, 'notFound' => count($apiStandingsData['standings'][0]['table'] ?? [])];
        }
        
        foreach ($apiStandingsData['standings'][0]['table'] as $apiTeamDataRow) { // Rinominato per chiarezza
            if (empty($apiTeamDataRow['team']['id']) || !isset($apiTeamDataRow['team']['name'])) {
                Log::warning("TeamDataService: Dati team incompleti nella classifica API per {$competitionCode} stagione {$seasonYear}.", ['teamData' => $apiTeamDataRow]);
                $notFoundInDb++;
                continue;
            }
            
            $apiTeamId = (string)$apiTeamDataRow['team']['id'];
            $apiTeamName = $apiTeamDataRow['team']['name'];
            
            $teamInDb = $this->findTeamByApiIdOrName($apiTeamId, $apiTeamName, $competitionCode);
            
            if (!$teamInDb) {
                Log::warning("TeamDataService: Team locale non trovato per API ID {$apiTeamId} (Nome API: '{$apiTeamName}', Short: '{$apiTeamDataRow['team']['shortName']}', TLA: '{$apiTeamDataRow['team']['tla']}') per {$competitionCode}. Classifica non salvata per {$seasonYear}.");
                $notFoundInDb++;
                continue;
            }
            
            try {
                TeamHistoricalStanding::updateOrCreate(
                    [
                        'team_id' => $teamInDb->id,
                        'season_year' => $seasonYear,
                        'league_name' => $apiStandingsData['competition']['name'] ?? $competitionCode, // Nome competizione dall'API se disponibile
                    ],
                    [
                        'position' => $apiTeamDataRow['position'],
                        'played_games' => $apiTeamDataRow['playedGames'],
                        'won' => $apiTeamDataRow['won'],
                        'draw' => $apiTeamDataRow['draw'],
                        'lost' => $apiTeamDataRow['lost'],
                        'points' => $apiTeamDataRow['points'],
                        'goals_for' => $apiTeamDataRow['goalsFor'],
                        'goals_against' => $apiTeamDataRow['goalsAgainst'],
                        'goal_difference' => $apiTeamDataRow['goalDifference'],
                        'data_source' => 'football-data.org'
                    ]
                    );
                $savedCount++;
            } catch (\Exception $e) {
                Log::error("TeamDataService: Errore durante il salvataggio della classifica per team ID {$teamInDb->id} ({$teamInDb->name}), stagione {$seasonYear}. Errore: {$e->getMessage()}");
            }
        }
        Log::info("TeamDataService: Classifiche per {$competitionCode} stagione {$seasonYear} elaborate. Salvate: {$savedCount}. Non trovate/dati API incompleti: {$notFoundInDb}.");
        return ['saved' => $savedCount, 'notFound' => $notFoundInDb];
    }
    
    /**
     * Recupera e salva le classifiche per una data competizione e stagione.
     * Nome metodo allineato alla chiamata dal comando.
     */
    public function fetchAndStoreSeasonStandings(int $seasonStartYear, string $competitionCode = 'SA'): array // Modificato per restituire array
    {
        $seasonDisplay = $seasonStartYear . '-' . substr($seasonStartYear + 1, 2);
        Log::info("TeamDataService: Tentativo di recuperare classifica per stagione {$seasonStartYear} (Competizione: {$competitionCode}).");
        
        $standingsData = $this->apiService->getStandingsForCompetitionAndSeason($competitionCode, $seasonStartYear);
        
        $defaultResult = ['saved' => 0, 'notFound' => 0, 'success' => false, 'message' => 'Fallimento recupero dati API o dati non validi.'];
        
        if ($standingsData && isset($standingsData['standings']) && !empty($standingsData['standings'][0]['table'])) {
            $result = $this->saveStandingsFromApiData($standingsData, $competitionCode, $seasonDisplay);
            $result['success'] = $result['saved'] > 0; // Aggiunge un flag di successo basato sui record salvati
            $result['message'] = $result['success'] ? "Elaborazione completata." : "Nessun record salvato durante l'elaborazione.";
            return $result;
        } elseif ($standingsData === null) {
            $defaultResult['message'] = "Chiamata API fallita o risposta vuota per classifiche {$competitionCode} stagione {$seasonStartYear}. Nessun dato da salvare.";
            Log::error("TeamDataService: " . $defaultResult['message']);
        } else {
            $defaultResult['message'] = "Nessuna classifica trovata o formato dati inatteso per {$competitionCode} stagione {$seasonStartYear}.";
            Log::warning("TeamDataService: " . $defaultResult['message'], ['response' => $standingsData]);
        }
        // Se la tabella è vuota, potremmo voler stimare il numero di team attesi per $notFound
        if (isset($standingsData['standings'][0]['table']) && empty($standingsData['standings'][0]['table']) && isset($standingsData['filters']['season'])) {
            // Questo è un caso in cui l'API potrebbe restituire una struttura valida ma senza team (es. stagione futura non ancora iniziata)
            // Non è necessariamente un errore, ma 0 salvati.
            $defaultResult['message'] = "API ha restituito una tabella classifiche vuota per {$competitionCode} stagione {$seasonStartYear}.";
            Log::info("TeamDataService: " . $defaultResult['message']);
            $defaultResult['success'] = true; // Consideriamo successo perché l'API ha risposto, ma non c'erano dati
        }
        
        
        return $defaultResult;
    }
}