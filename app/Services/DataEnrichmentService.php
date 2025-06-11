<?php

namespace App\Services;

use App\Models\Player;
use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;

class DataEnrichmentService
{
    protected string $baseUrl;
    protected string $apiKey;
    protected string $apiKeyName;
    
    public function __construct(protected PlayerStatsApiService $playerStatsApiService)
    {
        $configPath = "services.player_stats_api.providers.football_data_org";
        $apiConfig = config($configPath);
        
        if (is_null($apiConfig) || empty($apiConfig['base_url']) || empty($apiConfig['api_key_name']) || empty($apiConfig['api_key'])) {
            throw new \Exception("DataEnrichmentService: Configurazione provider API non trovata o incompleta.");
        }
        
        $this->baseUrl = rtrim($apiConfig['base_url'], '/');
        $this->apiKeyName = $apiConfig['api_key_name'];
        $this->apiKey = $apiConfig['api_key'];
        
        Log::info("DataEnrichmentService initializzato.");
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
        } else {
            $accentMap = ['á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 'ü'=>'u', 'ñ'=>'n', 'ç'=>'c', 'ò'=>'o', 'à'=>'a', 'è'=>'e', 'ì'=>'i', 'ù'=>'u'];
            $name = strtr(strtolower($name), $accentMap);
        }
        $name = strtolower($name);
        $name = str_replace(['-', '_', '.'], ' ', $name);
        $name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        return trim($name);
    }
    
    public function enrichPlayerFromApi(Player $player): bool
    {
        Log::debug("Inizio arricchimento per giocatore.", ['player_id' => $player->id, 'player_name' => $player->name]);
        
        $apiPlayerId = $player->api_football_data_id;
        
        if (!$apiPlayerId) {
            Log::info("ID API mancante per {$player->name}. Tentativo di trovarlo...");
            $apiPlayerId = $this->findFootballDataPlayerId($player);
            if (!$apiPlayerId) {
                return $this->logAndReturnFalse("ID API non trovato per {$player->name}. Arricchimento saltato.");
            }
            Log::info("ID API {$apiPlayerId} trovato per {$player->name}.");
        }
        
        $existingPlayer = Player::where('api_football_data_id', $apiPlayerId)->where('id', '!=', $player->id)->first();
        if ($existingPlayer) {
            return $this->mergeAndCleanDuplicates($player, $existingPlayer);
        }
        
        return $this->fetchDetailsAndSave($player, $apiPlayerId);
    }
    
    private function findFootballDataPlayerId(Player $player): ?int
    {
        $player->loadMissing('team');
        $team = $player->team;
        
        if (!$team || !$team->api_football_data_id) {
            return $this->logAndReturnFalse("Squadra non associata o senza ID API per il giocatore {$player->name} (DB ID: {$player->id}). Impossibile trovare la rosa.");
        }
        
        Log::info("Ricerca di '{$player->name}' nella rosa della squadra '{$team->name}' (API ID: {$team->api_football_data_id})");
        
        $squadData = $this->playerStatsApiService->getPlayersForTeamAndSeason($team->api_football_data_id, $team->season_year);
        
        if (!$squadData || empty($squadData['players'])) {
            return $this->logAndReturnFalse("Impossibile recuperare la rosa per il team ID {$team->api_football_data_id}.");
        }
        
        $teamSquadDataArray = $squadData['players'];
        $playerNameDbNormalized = $this->normalizeName($player->name);
        $matches = [];
        
        foreach ($teamSquadDataArray as $apiPlayer) {
            if (empty($apiPlayer['name']) || empty($apiPlayer['id'])) continue;
            
            $apiPlayerNameFull = trim($apiPlayer['name']);
            $apiPlayerNameNormalized = $this->normalizeName($apiPlayerNameFull);
            $score = 0;
            $matchType = 'NO_MATCH';
            
            if ($apiPlayerNameNormalized === $playerNameDbNormalized) {
                $score = 100; $matchType = "EXACT_FULL_NAME";
            } elseif (strlen($playerNameDbNormalized) >= 3 && str_contains($apiPlayerNameNormalized, $playerNameDbNormalized)) {
                $score = str_ends_with($apiPlayerNameNormalized, $playerNameDbNormalized) && count(explode(' ', $playerNameDbNormalized)) === 1 ? 95 : 90;
                $matchType = $score === 95 ? "DB_LASTNAME_IS_API_LASTNAME_PART" : "DB_NAME_IS_SUBSTRING_OF_API_NAME";
            }
            
            if ($score >= 75) {
                $matches[] = ['id' => (int)$apiPlayer['id'], 'name_api' => $apiPlayerNameFull, 'score' => $score, 'type' => $matchType, 'dob_api' => $apiPlayer['dateOfBirth'] ?? null];
            }
        }
        
        if (empty($matches)) {
            return $this->logAndReturnFalse("Nessun match (score >= 75) per '{$player->name}' in rosa API {$team->name}.");
        }
        
        usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);
        Log::debug("Potenziali Match Ordinati per '{$player->name}': " . json_encode($matches));
        
        $bestMatch = $matches[0];
        $topScore = $bestMatch['score'];
        $topScoreMatches = array_filter($matches, fn($match) => $match['score'] === $topScore);
        
        if (count($topScoreMatches) > 1) {
            if ($player->date_of_birth) {
                $playerDob = Carbon::parse($player->date_of_birth)->toDateString();
                foreach ($topScoreMatches as $potentialMatch) {
                    if (isset($potentialMatch['dob_api']) && Carbon::parse($potentialMatch['dob_api'])->toDateString() === $playerDob) {
                        return $potentialMatch['id'];
                    }
                }
            }
            return $this->logAndReturnFalse("AMBIGUITÀ NON RISOLTA per '{$player->name}'. Multipli match con score {$topScore}", ['matches' => $topScoreMatches]);
        }
        
        if ($bestMatch['score'] >= 85) {
            return $bestMatch['id'];
        }
        
        return $this->logAndReturnFalse("Giocatore '{$player->name}' non trovato con certezza (Best Score: {$bestMatch['score']} < 85)");
    }
    
    private function mergeAndCleanDuplicates(Player $duplicatePlayer, Player $masterPlayer): bool
    {
        Log::warning("Trovato DUPLICATO! L'ID API {$masterPlayer->api_football_data_id} è già usato da '{$masterPlayer->name}'. Eseguo merge e cancello '{$duplicatePlayer->name}'.");
        
        if (!$masterPlayer->fanta_platform_id && $duplicatePlayer->fanta_platform_id) $masterPlayer->fanta_platform_id = $duplicatePlayer->fanta_platform_id;
        if (!$masterPlayer->initial_quotation && $duplicatePlayer->initial_quotation) $masterPlayer->initial_quotation = $duplicatePlayer->initial_quotation;
        
        $masterPlayer->save();
        $duplicatePlayer->delete();
        
        Log::info("Merge completato. Record duplicato (ID: {$duplicatePlayer->id}) cancellato.");
        return true;
    }
    
    private function fetchDetailsAndSave(Player $player, int $apiPlayerId): bool
    {
        $playerDetails = $this->playerStatsApiService->getPlayerDetails($apiPlayerId);
        if (!$playerDetails) {
            return $this->logAndReturnFalse("Nessun dettaglio ricevuto da API per ID {$apiPlayerId}.");
        }
        
        $player->api_football_data_id = $apiPlayerId;
        $player->date_of_birth = isset($playerDetails['dateOfBirth']) ? Carbon::parse($playerDetails['dateOfBirth'])->format('Y-m-d') : $player->date_of_birth;
        $player->detailed_position = $playerDetails['position'] ?? $player->detailed_position;
        
        if (isset($playerDetails['currentTeam']['id'])) {
            $teamApiId = $playerDetails['currentTeam']['id'];
            $teamInDb = Team::where('api_football_data_id', $teamApiId)->first();
            if ($teamInDb && $player->team_id !== $teamInDb->id) {
                $player->team_id = $teamInDb->id;
                $player->team_name = $teamInDb->short_name;
                Log::info("Squadra aggiornata per {$player->name} a {$teamInDb->name} (trasferimento).");
            }
        }
        
        if ($player->isDirty()) {
            $player->save();
            Log::info("Dati di {$player->name} aggiornati.");
        } else {
            Log::info("Nessun nuovo dato da aggiornare per {$player->name}.");
        }
        return true;
    }
}