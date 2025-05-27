<?php

namespace App\Services;

use App\Models\Player;
use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class DataEnrichmentService
{
    protected string $apiKey;
    protected string $baseUri;
    protected string $serieACode;
    
    public function __construct()
    {
        $this->apiKey = config('services.football_data.key');
        $this->baseUri = config('services.football_data.base_uri');
        $this->serieACode = config('services.football_data.serie_a_code', 'SA');
        
        if (empty($this->apiKey)) {
            Log::error('DataEnrichmentService: API Key per Football-Data.org non configurata.');
            // Considera di lanciare un'eccezione se l'API key è fondamentale per il funzionamento
        }
    }
    
    /**
     * Metodo principale per arricchire i dati di un giocatore.
     *
     * @param Player $player
     * @return bool True se l'arricchimento ha avuto almeno un parziale successo, false altrimenti.
     */
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
                // Il messaggio di log dettagliato è già in findFootballDataPlayerId
                return $this->logAndReturnFalse("Arricchimento saltato per {$player->name} perché l'ID API non è stato trovato.");
            }
            $player->api_football_data_id = $footballDataPlayerId;
            $player->saveQuietly(); // Salva l'ID API trovato
            Log::info("DataEnrichmentService: ID API {$footballDataPlayerId} trovato e salvato per {$player->name}.");
        } else {
            Log::info("DataEnrichmentService: Utilizzo ID API esistente {$footballDataPlayerId} per {$player->name}.");
        }
        
        $cacheKey = "football_data_person_{$footballDataPlayerId}";
        $playerDetails = Cache::remember($cacheKey, now()->addDays(config('cache.ttl_api_person_details', 7)), function () use ($footballDataPlayerId, $player, $cacheKey) {
            Log::info("DataEnrichmentService: Chiamata API (no cache) a Football-Data per persona ID: {$footballDataPlayerId} ({$player->name})");
            try {
                $response = Http::withHeaders(['X-Auth-Token' => $this->apiKey])
                ->baseUrl($this->baseUri)
                // ->withoutVerifying() // SOLO PER DEBUG SSL LOCALE ESTREMO
                ->get("persons/{$footballDataPlayerId}");
                
                if ($response->successful()) {
                    return $response->json();
                } elseif ($response->status() == 404) {
                    Log::warning("DataEnrichmentService: Giocatore con API ID {$footballDataPlayerId} ({$player->name}) non trovato su Football-Data (404).");
                    Cache::forget($cacheKey);
                    return null;
                } elseif ($response->status() == 429) {
                    $retryAfter = $response->header('Retry-After') ?? 5; // Default a 5 secondi se non specificato
                    Log::warning("DataEnrichmentService: Rate limit raggiunto per Football-Data API (persona ID: {$footballDataPlayerId}). Attendere {$retryAfter} secondi.");
                    Cache::forget($cacheKey); // Rimuovi dalla cache per permettere un nuovo tentativo
                    // Potresti voler implementare un meccanismo di retry qui o segnalare al chiamante
                    return ['error' => 'rate_limit', 'message' => "Rate limit hit. Wait {$retryAfter} seconds.", 'retry_after' => (int)$retryAfter];
                } else {
                    Log::error("DataEnrichmentService: Errore API recupero persona ID {$footballDataPlayerId}. Status: {$response->status()}, Body: " . substr($response->body(), 0, 500));
                    Cache::forget($cacheKey);
                    return ['error' => 'api_error', 'status' => $response->status(), 'message' => 'API error occurred'];
                }
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                Log::error("DataEnrichmentService: Eccezione di connessione chiamata API persona ID {$footballDataPlayerId}: " . $e->getMessage());
                Cache::forget($cacheKey);
                return ['error' => 'connection_exception', 'message' => $e->getMessage()];
            } catch (\Exception $e) {
                Log::error("DataEnrichmentService: Eccezione generica chiamata API persona ID {$footballDataPlayerId}: " . $e->getMessage());
                Cache::forget($cacheKey);
                return ['error' => 'exception', 'message' => $e->getMessage()];
            }
        });
            
            if (!$playerDetails) {
                return $this->logAndReturnFalse("Nessun dettaglio giocatore ricevuto dall'API (o cache) per API ID {$footballDataPlayerId} ({$player->name}). Potrebbe non esistere sull'API o essere stato un errore di rete.");
            }
            if (isset($playerDetails['error'])) {
                // Se è un errore di rate limit, potremmo volerlo gestire diversamente (es. non marcare come fallito permanentemente)
                if ($playerDetails['error'] === 'rate_limit') {
                    Log::warning("DataEnrichmentService: Tentativo di arricchimento per {$player->name} (API ID {$footballDataPlayerId}) fallito a causa di rate limit. Riprovare più tardi.");
                } else {
                    Log::warning("DataEnrichmentService: Errore API o eccezione recuperando dettagli per API ID {$footballDataPlayerId} ({$player->name}): " . ($playerDetails['message'] ?? $playerDetails['error']));
                }
                return false;
            }
            
            $updated = false;
            if (isset($playerDetails['dateOfBirth']) && $playerDetails['dateOfBirth']) {
                try {
                    $apiDateOfBirth = Carbon::parse($playerDetails['dateOfBirth'])->format('Y-m-d');
                    $currentDbDate = $player->date_of_birth ? ($player->date_of_birth instanceof Carbon ? $player->date_of_birth->format('Y-m-d') : Carbon::parse($player->date_of_birth)->format('Y-m-d')) : null;
                    if ($currentDbDate !== $apiDateOfBirth) {
                        $player->date_of_birth = $apiDateOfBirth;
                        $updated = true;
                    }
                } catch (\Exception $e) {
                    Log::warning("DataEnrichmentService: Formato data di nascita non valido dall'API per {$player->name}: " . $playerDetails['dateOfBirth'] . ". Errore: " . $e->getMessage());
                }
            }
            
            if (isset($playerDetails['position']) && $playerDetails['position']) {
                if ($player->detailed_position !== $playerDetails['position']) {
                    $player->detailed_position = $playerDetails['position'];
                    $updated = true;
                }
            }
            // Aggiungi qui altri campi se li recuperi (es. nationality)
            
            if ($updated) {
                $player->save();
                Log::info("DataEnrichmentService: Dati di {$player->name} (DB ID: {$player->id}) aggiornati da API.");
            } else {
                Log::info("DataEnrichmentService: Nessun nuovo dato da aggiornare da API per {$player->name} (DB ID: {$player->id}) o dati già aggiornati.");
            }
            return true;
    }
    
    /**
     * Normalizza un nome per il confronto.
     */
    private function normalizeName(string $name): string
    {
        $name = trim($name);
        // Tenta la translitterazione per rimuovere accenti e caratteri speciali
        if (function_exists('transliterator_transliterate')) {
            // Converti in minuscolo DOPO la translitterazione per gestire correttamente caratteri come 'İ' -> 'i'
            $name = transliterator_transliterate('Any-Latin; Latin-ASCII;', $name);
            $name = strtolower($name);
        } else {
            // Fallback se intl non è disponibile (meno efficace)
            $name = strtolower($name);
            // Sostituzioni manuali per accenti comuni se intl non c'è (molto limitato)
            $accentMap = ['á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 'ü'=>'u', 'ñ'=>'n', 'ç'=>'c', /* ... etc ... */];
            $name = strtr($name, $accentMap);
        }
        // Sostituisci i trattini e altri separatori comuni con spazi
        $name = str_replace(['-', '_', '.'], ' ', $name);
        // Rimuovi altra punteggiatura residua (es. apostrofi)
        $name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name); // Mantieni lettere, numeri e spazi
        // Rimuovi spazi doppi o multipli
        $name = preg_replace('/\s+/', ' ', $name);
        return trim($name);
    }
    
    
    /**
     * Tenta di trovare l'ID di un giocatore su Football-Data.org.
     */
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
                $response = Http::withHeaders(['X-Auth-Token' => $this->apiKey])
                ->baseUrl($this->baseUri)
                // ->withoutVerifying() // SOLO PER DEBUG SSL LOCALE ESTREMO
                ->get("teams/{$apiTeamId}");
                if ($response->successful()) {
                    return $response->json();
                }
                Log::error("DataEnrichmentService: Errore recupero rosa per squadra API ID {$apiTeamId} ({$teamName}). Status: {$response->status()}, Body: ".substr($response->body(),0,200));
                Cache::forget($squadCacheKey);
                return null;
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                Log::error("DataEnrichmentService: Eccezione di connessione durante recupero rosa per squadra API ID {$apiTeamId} ({$teamName}): " . $e->getMessage());
                Cache::forget($squadCacheKey);
                return null;
            } catch (\Exception $e) {
                Log::error("DataEnrichmentService: Eccezione generica durante recupero rosa per squadra API ID {$apiTeamId} ({$teamName}): " . $e->getMessage());
                Cache::forget($squadCacheKey);
                return null;
            }
        });
            
            if (!$teamSquadApiResponse || !isset($teamSquadApiResponse['squad']) || !is_array($teamSquadApiResponse['squad'])) {
                Log::warning("DataEnrichmentService: Impossibile recuperare la rosa o formato rosa non valido per squadra API ID {$apiTeamId} ({$teamName}). Risposta API: " . substr(json_encode($teamSquadApiResponse), 0, 200));
                return null;
            }
            $teamSquadDataArray = $teamSquadApiResponse['squad'];
            
            $playerNameDb = $player->name;
            $playerNameDbNormalized = $this->normalizeName($playerNameDb);
            $dbNameParts = explode(' ', $playerNameDbNormalized);
            $dbNamePartsCount = count($dbNameParts);
            
            Log::info("DataEnrichmentService: Ricerca DB '{$playerNameDb}' (Norm: '{$playerNameDbNormalized}') in rosa API {$teamName}.");
            
            $matches = [];
            
            foreach ($teamSquadDataArray as $apiPlayer) {
                if (!isset($apiPlayer['name']) || !isset($apiPlayer['id'])) continue;
                
                $apiPlayerNameFull = trim($apiPlayer['name']);
                $apiPlayerNameNormalized = $this->normalizeName($apiPlayerNameFull);
                
                $score = 0;
                $matchType = '';
                
                // Livello 1: Match esatto del nome normalizzato completo
                if ($apiPlayerNameNormalized === $playerNameDbNormalized) {
                    $score = 100; $matchType = "EXACT_FULL_NAME";
                }
                // Livello 2: Il nome DB normalizzato è una sottostringa significativa del nome API normalizzato
                // (Evita match parziali troppo generici come "de" in "federico")
                elseif (strlen($playerNameDbNormalized) >= 3 && str_contains($apiPlayerNameNormalized, $playerNameDbNormalized)) {
                    // Diamo più peso se il nome DB è più lungo o se è l'ultima parte del nome API
                    if (str_ends_with($apiPlayerNameNormalized, $playerNameDbNormalized) && $dbNamePartsCount === 1) { // DB "Vrij", API "Stefan De Vrij"
                        $score = 95; $matchType = "DB_LASTNAME_IS_API_LASTNAME_PART";
                    } else {
                        $score = 90; $matchType = "DB_NAME_IS_SUBSTRING_OF_API_NAME";
                    }
                }
                // Livello 2.5: Il nome API normalizzato è una sottostringa del nome DB normalizzato
                elseif (strlen($apiPlayerNameNormalized) >=3 && str_contains($playerNameDbNormalized, $apiPlayerNameNormalized)) {
                    $score = 88; $matchType = "API_NAME_IS_SUBSTRING_OF_DB_NAME";
                }
                
                // Livello 3: Confronto basato sull'ultima parola (presunto cognome)
                // Questo è utile se il DB ha "Cognome Iniziale" e l'API ha "Nome Cognome"
                if ($score < 85 && $dbNamePartsCount > 0) {
                    $dbLastWord = last($dbNameParts);
                    // Se l'ultima parola del DB è un'iniziale, prova a usare la penultima come "cognome da cercare"
                    if (strlen($dbLastWord) === 1 && ctype_alpha($dbLastWord) && $dbNamePartsCount > 1) {
                        $dbSearchTerm = $dbNameParts[$dbNamePartsCount - 2];
                    } else {
                        $dbSearchTerm = $dbLastWord;
                    }
                    
                    if (strlen($dbSearchTerm) > 2 && str_contains($apiPlayerNameNormalized, $dbSearchTerm)) {
                        // Se il termine di ricerca DB è l'ultima parola anche dell'API, è un buon segno
                        if (str_ends_with($apiPlayerNameNormalized, $dbSearchTerm)) {
                            $score = max($score, 80); $matchType = $matchType ?: "DB_LAST_WORD_MATCHES_API_LAST_WORD";
                        } else {
                            $score = max($score, 78); $matchType = $matchType ?: "DB_LAST_WORD_IN_API_NAME";
                        }
                    }
                }
                
                // Livello 4: Logica per nomi composti del DB (tuo suggerimento)
                // Esempio: DB "Van Der Sar" (3 parti), API "Edwin Van Der Sar"
                // Cerca "Der Sar" e "Van Der"
                if ($score < 75 && $dbNamePartsCount >= 2) {
                    $dbLastTwoParts = $dbNameParts[$dbNamePartsCount - 2] . ' ' . $dbNameParts[$dbNamePartsCount - 1]; // es. "Der Sar" o "De Vrij"
                    if (str_contains($apiPlayerNameNormalized, $dbLastTwoParts)) {
                        $score = max($score, 75); $matchType = $matchType ?: "DB_LAST_TWO_PARTS_IN_API_NAME";
                    }
                    
                    if ($dbNamePartsCount >= 3 && $score < 75) {
                        $dbFirstTwoOfThree = $dbNameParts[0] . ' ' . $dbNameParts[1]; // es. "Van Der"
                        if (str_contains($apiPlayerNameNormalized, $dbFirstTwoOfThree)) {
                            // Meno peso perché "Van Der" da solo è meno distintivo
                            $score = max($score, 68); $matchType = $matchType ?: "DB_FIRST_TWO_OF_THREE_IN_API_NAME";
                        }
                    }
                }
                
                
                // Livello 5: Levenshtein (come fallback, se score ancora basso)
                if ($score < 65) {
                    $nameLengthDb = strlen($playerNameDbNormalized);
                    if ($nameLengthDb > 2) { // Evita su nomi troppo corti
                        $levenshteinThreshold = $nameLengthDb > 10 ? 3 : ($nameLengthDb > 5 ? 2 : 1);
                        $distance = levenshtein($playerNameDbNormalized, $apiPlayerNameNormalized);
                        if ($distance >= 0 && $distance <= $levenshteinThreshold) {
                            $currentLevScore = 60 - ($distance * 10); // Punteggio inversamente proporzionale
                            if ($currentLevScore > $score) {
                                $score = $currentLevScore;
                                $matchType = "LEVENSHTEIN_MATCH (Dist:{$distance})";
                            }
                        }
                    }
                }
                
                if ($score >= 55) { // Soglia minima per considerare un match potenziale
                    $matches[] = ['id' => (int) $apiPlayer['id'], 'name_api' => $apiPlayerNameFull, 'score' => $score, 'type' => $matchType];
                    Log::info("DataEnrichmentService: Potenziale Match: DB '{$playerNameDb}' con API '{$apiPlayerNameFull}' (ID: {$apiPlayer['id']}) - Score: {$score}, Type: {$matchType}");
                }
            }
            
            if (empty($matches)) {
                Log::warning("DataEnrichmentService: Nessun potenziale match trovato (score >= 55) per '{$playerNameDb}' in rosa API {$teamName}.");
                return null;
            }
            
            usort($matches, function ($a, $b) {
                return $b['score'] <=> $a['score']; // Ordina per score decrescente
            });
                
                $bestMatch = $matches[0];
                Log::debug("DataEnrichmentService: Potenziali Match Ordinati per '{$playerNameDb}': " . json_encode($matches));
                
                $topScore = $bestMatch['score'];
                $topScoreMatches = array_filter($matches, function($match) use ($topScore) {
                    return $match['score'] === $topScore;
                });
                    
                    // Logica di selezione finale
                    if ($topScore >= 90) { // Match esatto o DB name è sottostringa API (alta confidenza)
                        if (count($topScoreMatches) === 1) {
                            Log::info("DataEnrichmentService: Selezionato UNICO MIGLIOR match (score >= 90) per '{$playerNameDb}': API '{$bestMatch['name_api']}' (ID: {$bestMatch['id']}) con Score {$bestMatch['score']}.");
                            return $bestMatch['id'];
                        } else {
                            Log::warning("DataEnrichmentService: AMBIGUITÀ (score >= 90) per '{$playerNameDb}'. Multipli match con score {$topScore}: " . json_encode($topScoreMatches));
                            return null; // Ambiguità anche con score alto
                        }
                    } elseif ($topScore >= 75) { // Match buono (es. cognome significativo, parti composte)
                        if (count($topScoreMatches) === 1) {
                            Log::info("DataEnrichmentService: Selezionato UNICO match (score {$topScore} >= 75) per '{$playerNameDb}': API '{$bestMatch['name_api']}' (ID: {$bestMatch['id']}).");
                            return $bestMatch['id'];
                        } else {
                            Log::warning("DataEnrichmentService: AMBIGUITÀ (score {$topScore} >= 75) per '{$playerNameDb}'. Multipli match: " . json_encode($topScoreMatches));
                            return null;
                        }
                    } elseif ($topScore >= 55) { // Match Levenshtein o meno certo
                        if (count($topScoreMatches) === 1) {
                            Log::info("DataEnrichmentService: Selezionato match (score {$topScore} >= 55, prob. Levenshtein) per '{$playerNameDb}': API '{$bestMatch['name_api']}' (ID: {$bestMatch['id']}). Valutare accuratezza.");
                            return $bestMatch['id'];
                        } else {
                            Log::warning("DataEnrichmentService: AMBIGUITÀ (score {$topScore} >= 55) per '{$playerNameDb}'. Multipli match: " . json_encode($topScoreMatches));
                            return null;
                        }
                    }
                    
                    Log::warning("DataEnrichmentService: Giocatore '{$playerNameDb}' non trovato con sufficiente CERTEZZA (Best Score: {$topScore} < 55) nella rosa API della squadra {$teamName}. Miglior candidato (scartato): " . json_encode($bestMatch));
                    return null;
    }
    
    
    /**
     * Recupera l'ID di una squadra da Football-Data.org.
     */
    private function getApiTeamId(string $localTeamName): ?int
    {
        // Cerca prima nella cache/DB locale se hai una mappatura (non implementato qui, ma consigliato per efficienza)
        // $teamModel = Team::where('name', $localTeamName)->orWhere('short_name', $localTeamName)->first();
        // if ($teamModel && $teamModel->api_football_data_team_id) {
        //     Log::info("DataEnrichmentService: Trovato API Team ID {$teamModel->api_football_data_team_id} da DB per {$localTeamName}");
        //     return $teamModel->api_football_data_team_id;
        // }
        
        $teamsCacheKey = "football_data_teams_league_{$this->serieACode}";
        $apiTeams = Cache::remember($teamsCacheKey, now()->addDays(config('cache.ttl_api_teams', 1)), function () use ($teamsCacheKey) {
            Log::info("DataEnrichmentService: Chiamata API (no cache) per squadre lega {$this->serieACode}");
            try {
                $response = Http::withHeaders(['X-Auth-Token' => $this->apiKey])
                ->baseUrl($this->baseUri)
                // ->withoutVerifying() // SOLO PER DEBUG SSL LOCALE ESTREMO
                ->get("competitions/{$this->serieACode}/teams");
                if ($response->successful()) {
                    $data = $response->json();
                    return $data['teams'] ?? [];
                }
                Log::error("Errore API nel cercare squadre per lega {$this->serieACode}: Status {$response->status()} - Body: ".substr($response->body(),0,200));
                Cache::forget($teamsCacheKey); // Rimuovi in caso di errore per ritentare
                return [];
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                Log::error("DataEnrichmentService: Eccezione di connessione API nel cercare squadre ({$this->serieACode}): " . $e->getMessage());
                Cache::forget($teamsCacheKey);
                return [];
            } catch (\Exception $e) {
                Log::error("Eccezione generica API nel cercare squadre ({$this->serieACode}): " . $e->getMessage());
                Cache::forget($teamsCacheKey);
                return [];
            }
        });
            
            if (empty($apiTeams)) {
                Log::warning("DataEnrichmentService: Nessuna squadra recuperata dall'API per la lega {$this->serieACode}.");
                return null;
            }
            
            $normalizedLocalTeamName = $this->normalizeName($localTeamName);
            
            // Priorità ai match più esatti
            foreach ($apiTeams as $apiTeam) {
                if (!isset($apiTeam['name']) || !isset($apiTeam['id'])) continue;
                $normalizedApiTeamName = $this->normalizeName($apiTeam['name']);
                if ($normalizedApiTeamName === $normalizedLocalTeamName) {
                    Log::info("DataEnrichmentService: Trovato team API ESATTO (nome completo) '{$apiTeam['name']}' (ID: {$apiTeam['id']}) per nome locale '{$localTeamName}'");
                    return (int) $apiTeam['id'];
                }
            }
            foreach ($apiTeams as $apiTeam) {
                if (!isset($apiTeam['id'])) continue;
                $normalizedApiTeamShortName = isset($apiTeam['shortName']) ? $this->normalizeName($apiTeam['shortName']) : '';
                $normalizedApiTla = isset($apiTeam['tla']) ? $this->normalizeName($apiTeam['tla']) : ''; // Acronimo (Three Letter Acronym)
                if (($normalizedApiTeamShortName && $normalizedApiTeamShortName === $normalizedLocalTeamName) ||
                    ($normalizedApiTla && $normalizedApiTla === $normalizedLocalTeamName))
                {
                    Log::info("DataEnrichmentService: Trovato team API ESATTO (shortName/TLA) '{$apiTeam['name']}' (ID: {$apiTeam['id']}) per nome locale '{$localTeamName}'");
                    return (int) $apiTeam['id'];
                }
            }
            // Match "contains" come fallback
            foreach ($apiTeams as $apiTeam) {
                if (!isset($apiTeam['name']) || !isset($apiTeam['id'])) continue;
                $normalizedApiTeamName = $this->normalizeName($apiTeam['name']);
                if ( (strlen($normalizedLocalTeamName) >= 3 && stripos($normalizedApiTeamName, $normalizedLocalTeamName) !== false) ||
                    (strlen($normalizedApiTeamName) >= 3 && stripos($normalizedLocalTeamName, $normalizedApiTeamName) !== false) )
                {
                    Log::info("DataEnrichmentService: Trovato team API POTENZIALE (contains) '{$apiTeam['name']}' (ID: {$apiTeam['id']}) per nome locale '{$localTeamName}'");
                    return (int) $apiTeam['id']; // Prende il primo match 'contains'
                }
            }
            
            Log::warning("DataEnrichmentService: Nessun team API trovato per nome locale '{$localTeamName}' (normalizzato '{$normalizedLocalTeamName}') nella lega {$this->serieACode}.");
            return null;
    }
    
    /**
     * Funzione helper per logging e return false.
     */
    private function logAndReturnFalse(string $message): bool
    {
        Log::warning($message); // Rimosso il prefisso duplicato
        return false;
    }
}