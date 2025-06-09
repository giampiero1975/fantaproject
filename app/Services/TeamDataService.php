<?php

namespace App\Services;

use App\Models\Team;
use App\Models\TeamHistoricalStanding;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TeamDataService
{
    protected PlayerStatsApiService $playerStatsApiService;
    
    public function __construct(PlayerStatsApiService $playerStatsApiService)
    {
        $this->playerStatsApiService = $playerStatsApiService;
        Log::info(self::class . ": initializzato con PlayerStatsApiService.");
    }
    
    /**
     * Aggiorna o crea una squadra nel database basandosi sui dati API.
     *
     * @param array $apiTeamData I dati della squadra dall'API.
     * @param string $leagueCode Il codice della lega (es. 'SA' per Serie A).
     * @param int|null $seasonYear L'anno della stagione per cui questi dati API sono rilevanti.
     * @return Team|null Il modello Team aggiornato/creato, o null in caso di fallimento.
     */
    public function updateOrCreateTeamFromApiData(array $apiTeamData, string $leagueCode, ?int $seasonYear = null): ?Team
    {
        if (empty($apiTeamData['id']) || empty($apiTeamData['name'])) {
            Log::warning("Dati API squadra incompleti per creazione/aggiornamento. Saltata. Dati: " . json_encode($apiTeamData));
            return null;
        }
        
        // Cerca la squadra per api_football_data_id o per nome (se non ha ancora l'ID API)
        $team = Team::where('api_football_data_id', $apiTeamData['id'])->first();
        
        if (!$team) {
            // Se non trova per ID API, prova a cercare per nome (potrebbe essere una nuova squadra senza ID API mappato)
            $team = Team::where('name', $apiTeamData['name'])->first();
        }
        
        $teamData = [
            'name' => $apiTeamData['name'],
            'short_name' => $apiTeamData['shortName'] ?? Str::limit($apiTeamData['name'], 3, ''),
            'tla' => $apiTeamData['tla'] ?? null,
            'crest_url' => $apiTeamData['crest'] ?? null,
            'api_football_data_id' => $apiTeamData['id'],
            'league_code' => $leagueCode, // Imposta il codice della lega per la quale è stata trovata
            'season_year' => $seasonYear, // <-- SALVA IL NUOVO CAMPO season_year
        ];
        
        try {
            if ($team) {
                $team->fill($teamData);
                // Non impostare serie_a_team qui, è gestito dal comando TeamsSetActiveLeague
                // Non impostare tier qui, è gestito dal comando TeamsUpdateTiers
                $team->save();
                Log::info("Squadra esistente aggiornata: {$team->name} (ID: {$team->id})");
            } else {
                $team = new Team($teamData);
                // Imposta i default per le nuove squadre
                $team->serie_a_team = false; // Default a false, sarà impostato dal comando TeamsSetActiveLeague
                $team->tier = 0; // Default a 0, sarà calcolato dal comando TeamsUpdateTiers
                $team->save();
                Log::info("Nuova squadra creata: {$team->name} (ID: {$team->id})");
            }
            return $team;
        } catch (\Exception $e) {
            Log::error("Errore nel salvare la squadra {$apiTeamData['name']} (API ID: {$apiTeamData['id']}): " . $e->getMessage());
            return null;
        }
    }
    
    // ... (altri metodi esistenti) ...
    
    /**
     * Scarica e memorizza le classifiche storiche di una competizione per una specifica stagione.
     *
     * @param string $competitionCode
     * @param int $seasonStartYear
     * @return bool
     */
    public function fetchAndStoreSeasonStandings(string $competitionCode, int $seasonStartYear): bool
    {
        Log::info("Inizio fetch e storage classifiche per competizione {$competitionCode}, stagione {$seasonStartYear}.");
        $apiStandings = $this->playerStatsApiService->getStandingsForCompetitionAndSeason($competitionCode, $seasonStartYear);
        
        if (!$apiStandings || !isset($apiStandings['standings'][0]['table'])) {
            Log::error("Nessuna classifica ricevuta dall'API per competizione {$competitionCode}, stagione {$seasonStartYear}.");
            return false;
        }
        
        $leagueName = $apiStandings['competition']['name'] ?? 'Unknown League'; // Prendi il nome lega dalla risposta API
        
        // Assumi che ci sia una sola tabella di classifica per la lega principale
        $standingsTable = $apiStandings['standings'][0]['table'];
        
        foreach ($standingsTable as $rank) {
            $teamName = $rank['team']['name'] ?? null;
            $teamApiId = $rank['team']['id'] ?? null;
            
            if (!$teamName || !$teamApiId) {
                Log::warning("Dati classifica API incompleti per un team, saltato. Rank: " . json_encode($rank));
                continue;
            }
            
            // Trova la squadra nel nostro database
            $team = Team::where('api_football_data_id', $teamApiId)->first();
            if (!$team) {
                Log::warning("Squadra API con ID {$teamApiId} (Nome: {$teamName}) non trovata nel DB locale. Non posso salvare classifica storica.");
                // Se la squadra non esiste nel nostro DB, potremmo volerla creare qui.
                // Per ora, proseguiamo e non salviamo la classifica per questo team.
                continue;
            }
            
            TeamHistoricalStanding::updateOrCreate(
                [
                    'team_id' => $team->id,
                    'season_year' => $seasonStartYear,
                    'league_name' => $leagueName,
                ],
                [
                    'position' => $rank['position'] ?? null,
                    'points' => $rank['points'] ?? null,
                    'games_played' => $rank['playedGames'] ?? null,
                    'wins' => $rank['won'] ?? null,
                    'draws' => $rank['draw'] ?? null,
                    'losses' => $rank['lost'] ?? null,
                    'goals_for' => $rank['goalsFor'] ?? null,
                    'goals_against' => $rank['goalsAgainst'] ?? null,
                    'goal_difference' => $rank['goalDifference'] ?? null,
                ]
                );
            Log::info("Classifica storica salvata per {$teamName} ({$seasonStartYear}-{$leagueName}).");
        }
        return true;
    }
}