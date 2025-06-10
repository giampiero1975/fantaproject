<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PlayerStatsApiService
{
    protected string $baseUrl;
    protected string $apiKey;
    protected string $apiKeyName;
    protected string $activeProvider;
    protected array $apiConfig;
    
    public function __construct()
    {
        $this->activeProvider = 'football_data_org';
        $configPath = "services.player_stats_api.providers.{$this->activeProvider}";
        $this->apiConfig = config($configPath);
        
        if (is_null($this->apiConfig) || empty($this->apiConfig['base_url']) || empty($this->apiConfig['api_key_name']) || empty($this->apiConfig['api_key'])) {
            $errorMessage = "Configurazione provider API '{$this->activeProvider}' non trovata o incompleta in config/services.php. Controlla la sezione '{$configPath}' e le relative variabili d'ambiente.";
            Log::error($errorMessage, ['loaded_config' => $this->apiConfig]);
            throw new \Exception($errorMessage);
        }
        
        $this->baseUrl = rtrim($this->apiConfig['base_url'], '/');
        $this->apiKey = $this->apiConfig['api_key'];
        $this->apiKeyName = $this->apiConfig['api_key_name'];
        
        if (empty($this->apiKey)) {
            $errorMessage = "Chiave API ({$this->apiKeyName}) per il provider '{$this->activeProvider}' non configurata nel file .env (variabile: {$this->getApiKeyEnvVariable($this->activeProvider)}).";
            Log::error($errorMessage);
            throw new \Exception($errorMessage);
        }
        
        Log::info("PlayerStatsApiService initializzato con provider: {$this->activeProvider}, Base URL: {$this->baseUrl}");
    }
    
    private function getApiKeyEnvVariable(string $provider): string
    {
        if ($provider === 'football_data_org') {
            return 'FOOTBALL_DATA_API_KEY';
        }
        return 'CHIAVE_API_SCONOSCIUTA';
    }
    
    protected function makeRequest(string $endpoint, array $params = [], string $method = 'GET')
    {
        $fullUrl = $this->baseUrl . '/' . ltrim($endpoint, '/');
        $method = strtoupper($method);
        
        Log::info("PlayerStatsApiService: Effettuando richiesta {$method} a {$fullUrl}", ['params' => $params]);
        
        Log::debug('PlayerStatsApiService: Dettagli autenticazione in uscita.', [
            'header_name'  => $this->apiKeyName,
            'api_key_value' => $this->apiKey ? 'Token presente (lunghezza: ' . strlen($this->apiKey) . ')' : 'TOKEN MANCANTE O VUOTO!',
        ]);
        
        try {
            $response = Http::withHeaders([
                $this->apiKeyName => $this->apiKey,
            ])->timeout(30);
            
            if ($method === 'GET') {
                $response = $response->get($fullUrl, $params);
            } else {
                // Per POST, PUT, etc. Laravel si aspetta il verbo in minuscolo
                $response = $response->{strtolower($method)}($fullUrl, $params);
            }
            
            if ($response->successful()) {
                Log::info("PlayerStatsApiService: Richiesta API a {$fullUrl} riuscita (status {$response->status()}).");
                return $response->json();
            }
            
            $errorMessage = "PlayerStatsApiService: Richiesta API fallita a {$fullUrl}. Status: {$response->status()}";
            $responseData = $response->json();
            if (isset($responseData['message'])) {
                $errorMessage .= ". Messaggio API: " . $responseData['message'];
            }
            if (isset($responseData['errorCode'])) {
                $errorMessage .= ". Codice Errore API: " . $responseData['errorCode'];
            }
            
            Log::error($errorMessage, [
                'response_status' => $response->status(),
                'response_body' => $response->body(),
            ]);
            return null;
            
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error("PlayerStatsApiService: Errore di connessione API a {$fullUrl}. Errore: {$e->getMessage()}");
            return null;
        } catch (\Exception $e) {
            Log::error("PlayerStatsApiService: Errore generico durante la richiesta API a {$fullUrl}. Errore: {$e->getMessage()}", ['exception_trace' => $e->getTraceAsString()]);
            return null;
        }
    }
    
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
    
    public function getTeamsForCompetitionAndSeason(string $competitionCode, int $seasonStartYear): ?array
    {
        $endpoint = "competitions/{$competitionCode}/teams";
        $params = ['season' => $seasonStartYear];
        return $this->makeRequest($endpoint, $params);
    }
    
    public function getStandingsForCompetitionAndSeason(string $competitionCode, int $seasonStartYear): ?array
    {
        $endpoint = "competitions/{$competitionCode}/standings";
        $params = ['season' => $seasonStartYear];
        return $this->makeRequest($endpoint, $params);
    }
    
    public function getPlayerDetails(int $apiPlayerId): ?array
    {
        $endpoint = "persons/{$apiPlayerId}";
        return $this->makeRequest($endpoint);
    }
    
    public function getTeamSquad(int $apiTeamId): ?array
    {
        $endpoint = "teams/{$apiTeamId}";
        return $this->makeRequest($endpoint);
    }
    
    // ===================================================================
    //  <<<<<<<<<<<<<<<<<< FUNZIONE MANCANTE AGGIUNTA QUI >>>>>>>>>>>>>>>>>
    // ===================================================================
    /**
     * Recupera la rosa (squad) per una squadra specifica in una data stagione.
     */
    public function getPlayersForTeamAndSeason(int $teamApiId, int $seasonYear): ?array
    {
        $endpoint = "teams/{$teamApiId}";
        $params = ['season' => $seasonYear];
        
        $response = $this->makeRequest($endpoint, $params);
        
        // La rosa dei giocatori è nell'array 'squad' della risposta API di football-data.org
        // Lo restituiamo in un formato che il nostro comando si aspetta.
        if (isset($response['squad'])) {
            return ['players' => $response['squad']];
        }
        
        Log::warning("Nessun array 'squad' trovato nella risposta API per il team ID: {$teamApiId}");
        return null;
    }
}