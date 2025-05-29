<?php
namespace App\Services;
// ... (use statements come prima) ...
use App\Models\Team;
use App\Models\TeamHistoricalStanding;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;


class TeamDataService
{
    protected string $apiKey;
    protected string $baseUri;
    // Rimuovi $competitionId e $standingsEndpoint dalle proprietà della classe, verranno passati o costruiti nel metodo
    // protected string $competitionId;
    // protected string $standingsEndpoint;
    
    
    public function __construct()
    {
        $this->apiKey = config('services.football_data.key');
        $this->baseUri = config('services.football_data.base_uri');
        // Non inizializzare competitionId qui, sarà un parametro del metodo
    }
    
    private function normalizeName(string $name): string // Assicurati che questo metodo esista
    {
        // ... (funzione di normalizzazione come prima)
        $name = strtolower(trim($name));
        $commonWords = ['fc', 'cfc', 'bc', 'ac', 'ssc', 'us', 'calcio', '1909', '1913', '1919', 'spa', 'srl'];
        $patterns = [];
        foreach ($commonWords as $word) {
            $patterns[] = '/\b' . preg_quote($word, '/') . '\b/';
        }
        $patterns[] = '/[0-9]{4}/';
        $name = preg_replace($patterns, '', $name);
        $name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        return trim($name);
    }
    
    // MODIFICA LA FIRMA DEL METODO per accettare $competitionId
    public function fetchAndStoreSeasonStandings(int $seasonStartYear, string $competitionId = 'SA'): bool
    {
        if (empty($this->apiKey)) {
            Log::error(self::class . ": API Key non disponibile. Impossibile recuperare classifiche.");
            return false;
        }
        
        // Costruisci l'endpoint usando il $competitionId passato
        $standingsEndpointTemplate = config('team_tiering_settings.api_football_data.standings_endpoint', 'competitions/{competitionId}/standings?season={year}');
        $endpoint = str_replace(['{competitionId}', '{year}'], [$competitionId, $seasonStartYear], $standingsEndpointTemplate);
        
        $cacheKey = "football_data_standings_{$competitionId}_{$seasonStartYear}";
        $currentSeasonStartYear = now()->month >= 7 ? now()->year : now()->year - 1;
        $cacheDurationHours = ($seasonStartYear >= $currentSeasonStartYear - 1) ? config('cache.ttl_api_standings_recent_hours', 6) : config('cache.ttl_api_standings_historical_days', 30) * 24;
        
        
        Log::info(self::class . ": Tentativo di recuperare classifica per stagione {$seasonStartYear} (Competizione: {$competitionId}) da endpoint: {$this->baseUri}{$endpoint}");
        
        $responseJson = Cache::remember($cacheKey, now()->addHours($cacheDurationHours), function () use ($endpoint) {
            // ... (logica chiamata API e gestione errori/eccezioni come nel codice che ti ho fornito prima) ...
            try {
                $response = Http::withHeaders(['X-Auth-Token' => $this->apiKey])
                ->baseUrl($this->baseUri)
                ->get($endpoint);
                
                if ($response->failed()) {
                    Log::error(self::class . ": Errore API ({$response->status()}) recupero classifiche {$endpoint}. Body: " . substr($response->body(), 0, 500));
                    return null;
                }
                return $response->json();
                
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                Log::error(self::class . ": Eccezione di connessione API per {$endpoint}: " . $e->getMessage());
                return null;
            } catch (\Exception $e) {
                Log::error(self::class . ": Eccezione generica API per {$endpoint}: " . $e->getMessage());
                return null;
            }
        });
            // ... (resto del metodo fetchAndStoreSeasonStandings con la logica di parsing della tabella e matching team rimane identica)
            if (!$responseJson) {
                Log::error(self::class . ": Recupero fallito da API/cache per classifiche {$competitionId} stagione {$seasonStartYear}.");
                Cache::forget($cacheKey);
                return false;
            }
            
            if (isset($responseJson['message']) && isset($responseJson['errorCode']) && $responseJson['errorCode'] == 403) {
                Log::error(self::class . ": Accesso ristretto API per {$competitionId} stagione {$seasonStartYear}. Msg: " . $responseJson['message']);
                return false;
            }
            
            if (!isset($responseJson['standings'][0]['table'])) {
                Log::error(self::class . ": Struttura risposta API non valida per {$competitionId} stagione {$seasonStartYear}. Risposta: " . substr(json_encode($responseJson), 0, 500));
                Cache::forget($cacheKey);
                return false;
            }
            
            $table = $responseJson['standings'][0]['table'];
            $seasonYearString = $seasonStartYear . '-' . substr($seasonStartYear + 1, 2, 2);
            $teamsProcessedCount = 0;
            $teamsNotFoundCount = 0;
            
            $localTeamsByIdApi = Team::whereNotNull('api_football_data_team_id')->get()->keyBy('api_football_data_team_id');
            $localTeamsByName = Team::all()->mapWithKeys(function ($team) {
                $normalizedName = $this->normalizeName($team->name);
                $normalizedShortName = $this->normalizeName($team->short_name ?? '');
                $map = [];
                if ($normalizedName) $map[$normalizedName] = $team;
                if ($normalizedShortName && $normalizedShortName !== $normalizedName) $map[$normalizedShortName] = $team; // Evita sovrascritture se nome e shortname normalizzati sono uguali
                return $map;
            })->filter();
            
            
            foreach ($table as $entry) {
                $apiTeamId = $entry['team']['id'] ?? null;
                $apiTeamName = $entry['team']['name'] ?? null;
                $apiTeamShortName = $entry['team']['shortName'] ?? null;
                $apiTeamTla = $entry['team']['tla'] ?? null;
                
                if (!$apiTeamId || !$apiTeamName) {
                    Log::warning(self::class . ": ID o Nome squadra API mancante in classifica {$competitionId} stagione {$seasonYearString}. Dati: " . json_encode($entry['team']));
                    continue;
                }
                
                $team = $localTeamsByIdApi->get($apiTeamId);
                
                if (!$team) {
                    $normalizedApiName = $this->normalizeName($apiTeamName);
                    $team = $localTeamsByName->get($normalizedApiName);
                    
                    if (!$team && $apiTeamShortName) {
                        $normalizedApiShortName = $this->normalizeName($apiTeamShortName);
                        $team = $localTeamsByName->get($normalizedApiShortName);
                    }
                    if (!$team && $apiTeamTla) {
                        $normalizedApiTla = $this->normalizeName($apiTeamTla);
                        $team = $localTeamsByName->get($normalizedApiTla);
                    }
                    
                    if ($team) {
                        if ($team->api_football_data_team_id != $apiTeamId) {
                            Log::info(self::class . ": Trovato team '{$team->name}' (DB ID: {$team->id}) per {$competitionId} tramite nome/short/tla. Aggiorno/Imposto api_football_data_team_id a {$apiTeamId}. Nome API: '{$apiTeamName}'");
                            $team->api_football_data_team_id = $apiTeamId;
                            $team->save();
                            $localTeamsByIdApi->put($apiTeamId, $team);
                        }
                    }
                }
                
                if (!$team) {
                    Log::warning(self::class . ": Team locale non trovato per API ID {$apiTeamId} (Nome API: '{$apiTeamName}', Short: '{$apiTeamShortName}', TLA: '{$apiTeamTla}') per {$competitionId}. Classifica non salvata per {$seasonYearString}.");
                    $teamsNotFoundCount++;
                    continue;
                }
                
                TeamHistoricalStanding::updateOrCreate(
                    [
                        'team_id' => $team->id,
                        'season_year' => $seasonYearString,
                        'league_name' => $responseJson['competition']['name'] ?? (isset($responseJson['filters']['competition']) ? $responseJson['filters']['competition'] : $competitionId),
                    ],
                    [
                        'position' => $entry['position'],
                        'played_games' => $entry['playedGames'],
                        'won' => $entry['won'],
                        'draw' => $entry['draw'],
                        'lost' => $entry['lost'],
                        'points' => $entry['points'],
                        'goals_for' => $entry['goalsFor'],
                        'goals_against' => $entry['goalsAgainst'],
                        'goal_difference' => $entry['goalDifference'],
                        'data_source' => 'api_football-data_v4',
                    ]
                    );
                $teamsProcessedCount++;
            }
            Log::info(self::class . ": Classifiche per {$competitionId} stagione {$seasonYearString} elaborate. Salvate: {$teamsProcessedCount}. Non trovate nel DB locale: {$teamsNotFoundCount}.");
            return true;
    }
}