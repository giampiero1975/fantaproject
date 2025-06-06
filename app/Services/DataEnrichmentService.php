<?php

namespace App\Services;

use App\Models\Player;
use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Illuminate\Support\Str;

class DataEnrichmentService
{
    protected string $baseUrl;
    protected string $apiKey;
    protected string $apiKeyName;
    protected string $activeProvider;
    protected array $apiConfig;
    protected string $serieACode; // Codice per la Serie A (es. 'SA')
    
    public function __construct()
    {
        $this->activeProvider = 'football_data_org'; // Forziamo l'uso di football_data_org
        $configPath = "services.player_stats_api.providers.{$this->activeProvider}";
        $this->apiConfig = config($configPath);
        
        if (is_null($this->apiConfig) ||
            empty($this->apiConfig['base_url']) ||
            empty($this->apiConfig['api_key_name']) ||
            empty($this->apiConfig['api_key']) ||
            empty($this->apiConfig['serie_a_competition_id']) // Aggiunto controllo per serie_a_competition_id
            ) {
                $errorMessage = "DataEnrichmentService: Configurazione provider API '{$this->activeProvider}' non trovata o incompleta in {$configPath}. Assicurarsi che 'base_url', 'api_key_name', 'api_key', e 'serie_a_competition_id' siano definite.";
                Log::error($errorMessage, ['loaded_config' => $this->apiConfig]);
                throw new \Exception($errorMessage);
            }
            
            $this->baseUrl = rtrim($this->apiConfig['base_url'], '/');
            $this->apiKeyName = $this->apiConfig['api_key_name'];
            $this->apiKey = $this->apiConfig['api_key'];
            $this->serieACode = $this->apiConfig['serie_a_competition_id']; // Ora viene da $this->apiConfig
            
            if (empty($this->apiKey)) {
                // Questo controllo è ridondante se quello sopra in is_null($this->apiConfig) è completo, ma lo teniamo per sicurezza.
                $envVarName = ($this->activeProvider === 'football_data_org') ? 'FOOTBALL_DATA_API_KEY' : 'CHIAVE_API_SCONOSCIUTA';
                $errorMessage = "DataEnrichmentService: Chiave API ({$this->apiKeyName}) per '{$this->activeProvider}' non configurata nel .env (variabile attesa: {$envVarName}).";
                Log::error($errorMessage);
                throw new \Exception($errorMessage);
            }
            
            Log::info("DataEnrichmentService initializzato con provider API: {$this->activeProvider}, Serie A Code: {$this->serieACode}, Base URL: {$this->baseUrl}");
    }
    
    private function logAndReturnFalse(string $message, array $context = []): bool
    {
        Log::warning("DataEnrichmentService: " . $message, $context);
        return false;
    }
    
    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if (function_exists('transliterator_transliterate')) {
            $name = transliterator_transliterate('Any-Latin; Latin-ASCII;', $name);
            $name = strtolower($name);
        } else {
            $name = strtolower($name);
            $accentMap = ['á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 'ü'=>'u', 'ñ'=>'n', 'ç'=>'c', 'ò'=>'o', 'à'=>'a', 'è'=>'e', 'ì'=>'i', 'ù'=>'u'];
            $name = strtr($name, $accentMap);
        }
        $name = str_replace(['-', '_', '.'], ' ', $name);
        $name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        return trim($name);
    }
    
    private function getApiTeamId(string $localTeamName): ?int
    {
        if (empty($this->apiKey) || empty($this->serieACode)) {
            Log::error("DataEnrichmentService: apiKey o serieACode non inizializzati in getApiTeamId.");
            return null;
        }
        
        $teamsCacheKey = "football_data_teams_league_{$this->serieACode}";
        $apiTeams = Cache::remember($teamsCacheKey, now()->addDays(config('cache.ttl_api_teams', 1)), function () use ($teamsCacheKey) {
            Log::info("DataEnrichmentService: Chiamata API (no cache) per squadre lega {$this->serieACode}");
            try {
                $response = Http::withHeaders([$this->apiKeyName => $this->apiKey])
                ->baseUrl($this->baseUrl)
                ->get("competitions/{$this->serieACode}/teams");
                
                if ($response->successful()) {
                    $data = $response->json();
                    return $data['teams'] ?? [];
                }
                Log::error("DataEnrichmentService: Errore API nel cercare squadre per lega {$this->serieACode}: Status {$response->status()} - Body: ".Str::limit($response->body(),200));
                Cache::forget($teamsCacheKey);
                return [];
            } catch (\Exception $e) {
                Log::error("DataEnrichmentService: Eccezione API nel cercare squadre ({$this->serieACode}): " . $e->getMessage());
                Cache::forget($teamsCacheKey);
                return [];
            }
        });
            
            if (empty($apiTeams)) {
                Log::warning("DataEnrichmentService: Nessuna squadra recuperata dall'API per la lega {$this->serieACode}.");
                return null;
            }
            
            $normalizedLocalTeamName = $this->normalizeName($localTeamName);
            foreach ($apiTeams as $apiTeam) {
                if (!isset($apiTeam['name']) || !isset($apiTeam['id'])) continue;
                if ($this->normalizeName($apiTeam['name']) === $normalizedLocalTeamName) {
                    Log::info("DataEnrichmentService: Trovato team API (nome completo) '{$apiTeam['name']}' (ID: {$apiTeam['id']}) per nome locale '{$localTeamName}'");
                    return (int) $apiTeam['id'];
                }
                if (isset($apiTeam['shortName']) && $this->normalizeName($apiTeam['shortName']) === $normalizedLocalTeamName) {
                    Log::info("DataEnrichmentService: Trovato team API (shortName) '{$apiTeam['name']}' (ID: {$apiTeam['id']}) per nome locale '{$localTeamName}'");
                    return (int) $apiTeam['id'];
                }
                if (isset($apiTeam['tla']) && strtolower($apiTeam['tla']) === $normalizedLocalTeamName) {
                    Log::info("DataEnrichmentService: Trovato team API (TLA) '{$apiTeam['name']}' (ID: {$apiTeam['id']}) per nome locale '{$localTeamName}'");
                    return (int) $apiTeam['id'];
                }
            }
            foreach ($apiTeams as $apiTeam) {
                if (!isset($apiTeam['name']) || !isset($apiTeam['id'])) continue;
                $normalizedApiTeamName = $this->normalizeName($apiTeam['name']);
                if ( (strlen($normalizedLocalTeamName) >= 3 && stripos($normalizedApiTeamName, $normalizedLocalTeamName) !== false) ||
                    (strlen($normalizedApiTeamName) >= 3 && stripos($normalizedLocalTeamName, $normalizedApiTeamName) !== false) )
                {
                    Log::info("DataEnrichmentService: Trovato team API POTENZIALE (contains) '{$apiTeam['name']}' (ID: {$apiTeam['id']}) per nome locale '{$localTeamName}'");
                    return (int) $apiTeam['id'];
                }
            }
            Log::warning("DataEnrichmentService: Nessun team API trovato per nome locale '{$localTeamName}' (normalizzato '{$normalizedLocalTeamName}') nella lega {$this->serieACode}.");
            return null;
    }
    
    public function enrichPlayerFromApi(Player $player): bool
    {
        if (empty($this->apiKey)) {
            return $this->logAndReturnFalse("API Key non disponibile. Impossibile procedere con l'arricchimento per {$player->name}.");
        }
        
        $footballDataPlayerId = $player->api_football_data_id;
        
        if (!$footballDataPlayerId) {
            Log::info("DataEnrichmentService: Tentativo di trovare l'ID API per {$player->name} (Squadra DB: {$player->team_name}).");
            $footballDataPlayerId = $this->findFootballDataPlayerId($player);
            
            if (!$footballDataPlayerId) {
                return $this->logAndReturnFalse("Arricchimento saltato per {$player->name} perché l'ID API non è stato trovato.", ['player_id_db' => $player->id]);
            }
            Log::info("DataEnrichmentService: ID API {$footballDataPlayerId} trovato per {$player->name}. Procedo al fetch dei dettagli.");
        } else {
            Log::info("DataEnrichmentService: Utilizzo ID API esistente {$footballDataPlayerId} per {$player->name}.");
        }
        
        $cacheKey = "football_data_person_{$footballDataPlayerId}";
        $playerDetails = Cache::remember($cacheKey, now()->addDays(config('cache.ttl_api_person_details', 7)), function () use ($footballDataPlayerId, $player, $cacheKey) {
            Log::info("DataEnrichmentService: Chiamata API (no cache) a Football-Data per persona ID: {$footballDataPlayerId} ({$player->name})");
            try {
                $response = Http::withHeaders([$this->apiKeyName => $this->apiKey])
                ->baseUrl($this->baseUrl)
                ->get("persons/{$footballDataPlayerId}");
                
                if ($response->successful()) return $response->json();
                if ($response->status() == 404) {
                    Log::warning("DataEnrichmentService: Giocatore API ID {$footballDataPlayerId} ({$player->name}) non trovato (404).", ['url' => "persons/{$footballDataPlayerId}"]);
                    Cache::forget($cacheKey); return null;
                }
                if ($response->status() == 429) {
                    $retryAfter = $response->header('Retry-After') ?? 5;
                    Log::warning("DataEnrichmentService: Rate limit API per persona ID: {$footballDataPlayerId}. Attendere {$retryAfter}s.", ['url' => "persons/{$footballDataPlayerId}"]);
                    Cache::forget($cacheKey); return ['error' => 'rate_limit', 'retry_after' => (int)$retryAfter];
                }
                Log::error("DataEnrichmentService: Errore API recupero persona ID {$footballDataPlayerId}. Status: {$response->status()}", ['body' => Str::limit($response->body(),500)]);
                Cache::forget($cacheKey); return ['error' => 'api_error', 'status' => $response->status()];
            } catch (\Exception $e) {
                Log::error("DataEnrichmentService: Eccezione chiamata API persona ID {$footballDataPlayerId}: " . $e->getMessage());
                Cache::forget($cacheKey); return ['error' => 'exception', 'message' => $e->getMessage()];
            }
        });
            
            if (!$playerDetails) {
                return $this->logAndReturnFalse("Nessun dettaglio giocatore ricevuto da API per ID {$footballDataPlayerId} ({$player->name}).", ['player_id_db' => $player->id]);
            }
            if (isset($playerDetails['error'])) {
                return $this->logAndReturnFalse("Errore recupero dettagli API per ID {$footballDataPlayerId} ({$player->name}): " . ($playerDetails['message'] ?? $playerDetails['error']), ['player_id_db' => $player->id]);
            }
            
            $updated = false;
            if ($player->api_football_data_id != $footballDataPlayerId) { // Assicura che l'ID API sia salvato se trovato ora
                $player->api_football_data_id = $footballDataPlayerId;
                $updated = true;
            }
            
            if (isset($playerDetails['dateOfBirth']) && $playerDetails['dateOfBirth']) {
                try {
                    $apiDateOfBirth = Carbon::parse($playerDetails['dateOfBirth'])->format('Y-m-d');
                    $currentDbDate = $player->date_of_birth ? Carbon::parse($player->date_of_birth)->format('Y-m-d') : null;
                    if ($currentDbDate !== $apiDateOfBirth) {
                        $player->date_of_birth = $apiDateOfBirth;
                        $updated = true;
                    }
                } catch (\Exception $e) {
                    Log::warning("DataEnrichmentService: Formato data di nascita non valido da API per {$player->name}: {$playerDetails['dateOfBirth']}. Errore: {$e->getMessage()}");
                }
            }
            
            if (isset($playerDetails['position']) && $playerDetails['position']) {
                if ($player->detailed_position !== $playerDetails['position']) {
                    $player->detailed_position = $playerDetails['position'];
                    $updated = true;
                }
            }
            
            if ($updated) {
                $player->save();
                Log::info("DataEnrichmentService: Dati di {$player->name} (DB ID: {$player->id}) aggiornati da API. DOB: {$player->date_of_birth}, Posizione: {$player->detailed_position}, API_ID: {$player->api_football_data_id}");
            } else {
                Log::info("DataEnrichmentService: Nessun nuovo dato da aggiornare da API per {$player->name} (DB ID: {$player->id}) o dati già aggiornati.");
            }
            return true;
    }
    
    private function findFootballDataPlayerId(Player $player): ?int
    {
        if (empty($player->name)) {
            Log::warning("DataEnrichmentService: Nome giocatore mancante per il matching API (DB ID: {$player->id}).");
            return null;
        }
        $teamName = $player->team_name;
        if (empty($teamName) && $player->team_id) {
            $player->loadMissing('team');
            $teamName = $player->team?->name;
        }
        if(empty($teamName)) {
            Log::warning("DataEnrichmentService: Nome squadra mancante per {$player->name} (DB ID: {$player->id}). Impossibile procedere con il matching del giocatore.");
            return null;
        }
        
        $apiTeamId = $this->getApiTeamId($teamName);
        
        if (!$apiTeamId) {
            Log::warning("DataEnrichmentService: Fallito il recupero dell'API Team ID per '{$teamName}' durante la ricerca di {$player->name}.");
            return null;
        }
        
        $squadCacheKey = "football_data_squad_team_{$apiTeamId}";
        $teamSquadApiResponse = Cache::remember($squadCacheKey, now()->addHours(config('cache.ttl_api_squad', 1)), function () use ($apiTeamId, $teamName, $squadCacheKey) {
            Log::info("DataEnrichmentService: Chiamata API (no cache) per rosa squadra API ID: {$apiTeamId} ({$teamName})");
            try {
                $response = Http::withHeaders([$this->apiKeyName => $this->apiKey])
                ->baseUrl($this->baseUrl)
                ->get("teams/{$apiTeamId}");
                
                if ($response->successful()) {
                    $data = $response->json();
                    return $data;
                }
                Log::error("DataEnrichmentService: Errore recupero rosa per squadra API ID {$apiTeamId} ({$teamName}). Status: {$response->status()}", ['body' => Str::limit($response->body(), 500)]);
                Cache::forget($squadCacheKey);
                return null;
            } catch (\Exception $e) {
                Log::error("DataEnrichmentService: Eccezione durante recupero rosa per squadra API ID {$apiTeamId} ({$teamName}): " . $e->getMessage());
                Cache::forget($squadCacheKey);
                return null;
            }
        });
            
            if (!$teamSquadApiResponse || !isset($teamSquadApiResponse['squad']) || !is_array($teamSquadApiResponse['squad'])) {
                Log::warning("DataEnrichmentService: Impossibile recuperare la rosa o formato rosa non valido per squadra API ID {$apiTeamId} ({$teamName}).", ['response' => $teamSquadApiResponse]);
                return null;
            }
            $teamSquadDataArray = $teamSquadApiResponse['squad'];
            
            $playerNameDbNormalized = $this->normalizeName($player->name);
            Log::info("DataEnrichmentService: Ricerca DB '{$player->name}' (Norm: '{$playerNameDbNormalized}') in rosa API {$teamName}.");
            
            $matches = [];
            foreach ($teamSquadDataArray as $apiPlayer) {
                if (!isset($apiPlayer['name']) || !isset($apiPlayer['id'])) continue;
                $apiPlayerNameFull = trim($apiPlayer['name']);
                $apiPlayerNameNormalized = $this->normalizeName($apiPlayerNameFull);
                $score = 0; $matchType = 'NO_MATCH';
                
                if ($apiPlayerNameNormalized === $playerNameDbNormalized) { $score = 100; $matchType = "EXACT_FULL_NAME"; }
                elseif (strlen($playerNameDbNormalized) >= 3 && str_contains($apiPlayerNameNormalized, $playerNameDbNormalized)) {
                    $score = str_ends_with($apiPlayerNameNormalized, $playerNameDbNormalized) && count(explode(' ', $playerNameDbNormalized)) === 1 ? 95 : 90;
                    $matchType = $score === 95 ? "DB_LASTNAME_IS_API_LASTNAME_PART" : "DB_NAME_IS_SUBSTRING_OF_API_NAME";
                } elseif (strlen($apiPlayerNameNormalized) >=3 && str_contains($playerNameDbNormalized, $apiPlayerNameNormalized)) {
                    $score = 88; $matchType = "API_NAME_IS_SUBSTRING_OF_DB_NAME";
                } else {
                    $dbNameParts = explode(' ', $playerNameDbNormalized);
                    $dbNamePartsCount = count($dbNameParts);
                    if ($dbNamePartsCount > 0) {
                        $dbLastWord = last($dbNameParts);
                        $dbSearchTerm = (strlen($dbLastWord) === 1 && ctype_alpha($dbLastWord) && $dbNamePartsCount > 1) ? $dbNameParts[$dbNamePartsCount - 2] : $dbLastWord;
                        if (strlen($dbSearchTerm) > 2 && str_contains($apiPlayerNameNormalized, $dbSearchTerm)) {
                            $currentScore = str_ends_with($apiPlayerNameNormalized, $dbSearchTerm) ? 80 : 78;
                            if($currentScore > $score) { $score = $currentScore; $matchType = "DB_LAST_WORD_MATCH"; }
                        }
                    }
                }
                
                if ($score >= 75) {
                    $matches[] = ['id' => (int) $apiPlayer['id'], 'name_api' => $apiPlayerNameFull, 'score' => $score, 'type' => $matchType, 'dob_api' => $apiPlayer['dateOfBirth'] ?? null];
                }
            }
            
            if (empty($matches)) {
                Log::warning("DataEnrichmentService: Nessun match (score >= 75) per '{$player->name}' in rosa API {$teamName}.");
                return null;
            }
            usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);
            Log::debug("DataEnrichmentService: Potenziali Match Ordinati per '{$player->name}': " . json_encode($matches));
            
            $bestMatch = $matches[0];
            $topScore = $bestMatch['score'];
            $topScoreMatches = array_filter($matches, fn($match) => $match['score'] === $topScore);
            
            if (count($topScoreMatches) > 1) {
                if ($player->date_of_birth) {
                    $playerDob = Carbon::parse($player->date_of_birth)->toDateString();
                    foreach ($topScoreMatches as $potentialMatch) {
                        if (isset($potentialMatch['dob_api']) && Carbon::parse($potentialMatch['dob_api'])->toDateString() === $playerDob) {
                            Log::info("DataEnrichmentService: AMBIGUITÀ RISOLTA con DOB per '{$player->name}'. Scelto: API '{$potentialMatch['name_api']}' (ID: {$potentialMatch['id']}) Score {$potentialMatch['score']}.");
                            return $potentialMatch['id'];
                        }
                    }
                }
                Log::warning("DataEnrichmentService: AMBIGUITÀ NON RISOLTA per '{$player->name}'. Multipli match con score {$topScore}: " . json_encode($topScoreMatches));
                return null;
            }
            
            if ($bestMatch['score'] >= 85) {
                Log::info("DataEnrichmentService: Selezionato match (score >= 85) per '{$player->name}': API '{$bestMatch['name_api']}' (ID: {$bestMatch['id']}) Score {$bestMatch['score']}.");
                return $bestMatch['id'];
            } elseif ($bestMatch['score'] >=75 ) {
                Log::info("DataEnrichmentService: Selezionato match (score >= 75) per '{$player->name}': API '{$bestMatch['name_api']}' (ID: {$bestMatch['id']}) Score {$bestMatch['score']}. Valutare accuratezza.");
                return $bestMatch['id'];
            }
            
            Log::warning("DataEnrichmentService: Giocatore '{$player->name}' non trovato con certezza (Best Score: {$bestMatch['score']} < 75). Miglior candidato (scartato): " . json_encode($bestMatch));
            return null;
    }
}