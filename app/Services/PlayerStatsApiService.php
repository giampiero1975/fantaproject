<?php
namespace App\Services;

use App\Models\Player;
use App\Models\Team;
use App\Models\HistoricalPlayerStat;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class PlayerStatsApiService
{
    protected array $apiConfig;
    protected string $apiKey;
    protected string $apiHost;
    protected string $apiKeyHeader;
    
    public function __construct()
    {
        // Assumiamo che le stats giocatori vengano SEMPRE da api-football.com per ora
        $activeProviderKey = config('team_tiering_settings.active_api_provider_for_player_stats', 'api_football_v3');
        $providerConfig = config("team_tiering_settings.api_providers.{$activeProviderKey}");
        
        if (!$providerConfig) {
            throw new \Exception("Configurazione provider API '{$activeProviderKey}' per stats giocatori non trovata.");
        }
        
        $this->apiKey = config($providerConfig['key_config_path']);
        $this->apiHost = config($providerConfig['host_config_path']);
        $this->apiKeyHeader = config($providerConfig['header_key_name_config_path']);
        $this->apiConfig = $providerConfig;
    }
    
    private function getHttpClient() // Metodo helper come in TeamDataService
    {
        $headers = [$this->apiKeyHeader => $this->apiKey];
        // Aggiungere 'x-rapidapi-host' se necessario per RapidAPI
        // if (str_contains($this->apiHost, 'rapidapi.com')) { $headers['x-rapidapi-host'] = $this->apiHost; }
        return Http::withHeaders($headers)->baseUrl('https://' . $this->apiHost);
    }
    
    
    public function fetchAndStorePlayerStatsForTeam(Team $team, int $seasonStartYear)
    {
        // L'ID del team qui DEVE essere l'ID di api-football.com, precedentemente mappato
        if (!$team->api_football_data_team_id) {
            Log::warning(self::class . ": API Team ID (per api-football.com) mancante per team {$team->name} (DB ID {$team->id}). Impossibile recuperare stats giocatori.");
            return 0;
        }
        $apiTeamId = $team->api_football_data_team_id;
        
        // Determina l'ID della lega API B in cui ha giocato il team in quella stagione
        // Questo potrebbe richiedere una query su team_historical_standings per vedere in che lega era.
        // Per semplicità, assumiamo che se è neopromossa, la sua ultima stagione era in Serie B.
        // Dovrai passare l'ID della lega API B come parametro o determinarlo.
        $leagueApiIdForStats = $this->apiConfig['serie_b_competition_id']; // Assunzione, potrebbe servire più logica
        // Se il team è attualmente in Serie A, le sue stats precedenti potrebbero essere state in Serie A o B
        // Bisogna sapere in quale lega ha giocato il team nella $seasonStartYear data.
        // Questo è un punto complesso. Per le neopromosse, useremo l'ID della Serie B.
        
        $endpoint = $this->apiConfig['player_stats_endpoint'] ?? 'players'; // es. 'players'
        $queryParams = [
            'team' => $apiTeamId,
            'season' => $seasonStartYear,
            'league' => $leagueApiIdForStats
        ];
        
        $cacheKey = "player_stats_apib_team_{$apiTeamId}_s{$seasonStartYear}_l{$leagueApiIdForStats}";
        Log::info(self::class . ": Recupero stats giocatori per team {$team->name} (API ID: {$apiTeamId}), stagione {$seasonStartYear}, lega API ID {$leagueApiIdForStats}. Endpoint: {$endpoint}");
        
        $responseJson = Cache::remember($cacheKey, now()->addDays(config('cache.ttl_api_player_stats', 1)), function () use ($endpoint, $queryParams) {
            try {
                $response = $this->getHttpClient()->get($endpoint, $queryParams);
                if ($response->failed()) {
                    Log::error(self::class . ": Errore API ({$response->status()}) recupero stats giocatori. Endpoint: {$endpoint}, Params: " . json_encode($queryParams) . ", Body: " . $response->body());
                    return null;
                }
                return $response->json();
            } catch (\Exception $e) {
                Log::error(self::class . ": Eccezione API recupero stats giocatori: " . $e->getMessage());
                return null;
            }
        });
            
            if (!$responseJson || !isset($responseJson['response']) || empty($responseJson['response'])) {
                Log::warning(self::class . ": Nessuna statistica giocatore trovata da API B o risposta non valida per team {$team->name}, stagione {$seasonStartYear}.");
                return 0;
            }
            
            $processedCount = 0;
            $createdCount = 0;
            $updatedCount = 0;
            $playerNotFoundCount = 0;
            $seasonYearString = $seasonStartYear . '-' . substr($seasonStartYear + 1, 2, 2);
            
            foreach ($responseJson['response'] as $playerDataWrapper) {
                $apiPlayer = $playerDataWrapper['player'] ?? null;
                $apiStatsArray = $playerDataWrapper['statistics'] ?? [];
                
                if (!$apiPlayer || empty($apiStatsArray) || !isset($apiPlayer['id']) || !isset($apiPlayer['name'])) {
                    Log::warning(self::class . ": Record giocatore API incompleto o senza statistiche: " . json_encode($playerDataWrapper));
                    continue;
                }
                // api-football.com può restituire più set di statistiche se un giocatore ha giocato in più competizioni
                // per quella squadra in quella stagione. Scegliamo il primo (o quello relativo alla $leagueApiIdForStats)
                $apiPlayerStats = null;
                foreach($apiStatsArray as $statSet) {
                    if (isset($statSet['league']['id']) && $statSet['league']['id'] == $leagueApiIdForStats) {
                        $apiPlayerStats = $statSet;
                        break;
                    }
                }
                if (!$apiPlayerStats) $apiPlayerStats = $apiStatsArray[0]; // Fallback al primo set
                
                
                // Fase 1: Trova o crea il giocatore nel DB `players`
                // È cruciale avere un modo per mappare l'ID giocatore di API B a un giocatore nel tuo DB.
                // Aggiungi una colonna `api_b_player_id` alla tabella `players`.
                $player = Player::where('api_b_player_id', $apiPlayer['id'])->first();
                
                if (!$player) {
                    // Tentativo di match per nome + team_id (team_id della squadra neopromossa)
                    // Questo è meno affidabile, l'ID API è meglio.
                    // Considera di avere un comando separato per mappare gli ID giocatori di API B.
                    // Per ora, se non c'è l'ID API B, potremmo provare un match per nome se il giocatore
                    // è stato già importato con il roster.
                    Log::info(self::class . ": Giocatore API B '{$apiPlayer['name']}' (ID: {$apiPlayer['id']}) non trovato tramite api_b_player_id. Tentativo match per nome per team {$team->name}.");
                    // Qui dovresti implementare una logica di matching più robusta o creare il giocatore se non esiste.
                    // Per ora, se non c'è match ID, lo saltiamo per evitare di creare duplicati o associazioni errate.
                    $playerNotFoundCount++;
                    continue;
                }
                
                // Mappatura delle statistiche API B ai campi del tuo modello HistoricalPlayerStat
                // ** QUESTA È LA PARTE CHE DEVI PERSONALIZZARE MAGGIORMENTE IN BASE ALLA RISPOSTA JSON REALE **
                $statsToSave = [
                    'games_played' => $apiPlayerStats['games']['appearences'] ?? 0,
                    'avg_rating' => isset($apiPlayerStats['games']['rating']) ? (float) str_replace(',', '.', $apiPlayerStats['games']['rating']) : null,
                    'fanta_avg_rating' => null, // API B difficilmente la fornisce
                    'goals_scored' => $apiPlayerStats['goals']['total'] ?? 0,
                    'assists' => $apiPlayerStats['goals']['assists'] ?? 0,
                    'yellow_cards' => $apiPlayerStats['cards']['yellow'] ?? 0,
                    'red_cards' => $apiPlayerStats['cards']['red'] ?? 0,
                    'penalties_taken' => $apiPlayerStats['penalty']['total'] ?? ($apiPlayerStats['penalty']['scored'] ?? 0) + ($apiPlayerStats['penalty']['missed'] ?? 0),
                    'penalties_scored' => $apiPlayerStats['penalty']['scored'] ?? 0,
                    'penalties_missed' => $apiPlayerStats['penalty']['missed'] ?? 0,
                    'penalties_saved' => $apiPlayerStats['penalty']['saved'] ?? 0, // Per portieri
                    'goals_conceded' => $apiPlayerStats['goals']['conceded'] ?? 0, // Per portieri
                    'data_source' => 'api_b_import',
                    'role_for_season' => $player->role, // O cerca di dedurlo da $apiPlayerStats['games']['position']
                    'team_name_for_season' => $team->name, // Nome del team per cui si stanno importando le stats
                    'mantra_role_for_season' => null, // API B non fornirà ruoli Mantra
                ];
                
                // Filtra i valori nulli non intenzionali prima di salvare
                $statsToSave = array_filter($statsToSave, function($value) { return $value !== null; });
                
                
                if (empty($statsToSave['games_played'])) { // Salta se non ci sono presenze
                    Log::info(self::class.": Giocatore {$player->name} (API B ID: {$apiPlayer['id']}) senza presenze nella lega {$leagueApiIdForStats} stagione {$seasonYearString}. Statistiche non salvate.");
                    continue;
                }
                
                
                $historicalStat = HistoricalPlayerStat::updateOrCreate(
                    [
                        'player_fanta_platform_id' => $player->fanta_platform_id,
                        'season_year' => $seasonYearString,
                        'team_id' => $team->id,
                        'league_name' => $apiPlayerStats['league']['name'] ?? "Serie B",
                    ],
                    $statsToSave
                    );
                $processedCount++;
                if($historicalStat->wasRecentlyCreated) $createdCount++;
                elseif($historicalStat->wasChanged(array_keys($statsToSave))) $updatedCount++; // Controlla solo i campi che volevi aggiornare
            }
            Log::info(self::class . ": Stats giocatori (API B) per {$team->name} stagione {$seasonYearString} processate. API Records: " . count($responseJson['response']) . ", DB Processati: {$processedCount}, Creati: {$createdCount}, Aggiornati: {$updatedCount}, Giocatori non trovati in DB: {$playerNotFoundCount}.");
            return $processedCount;
    }
}