<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PlayerStatsApiService
{
    protected string $baseUrl;
    protected string $apiKey;
    protected string $apiKeyName;
    protected string $activeProvider; // Nome del provider API attivo
    protected array $apiConfig;      // Configurazione per il provider attivo
    
    public function __construct()
    {
        // Forziamo l'uso di 'football_data_org' come unico provider
        $this->activeProvider = 'football_data_org';
        
        $configPath = "services.player_stats_api.providers.{$this->activeProvider}";
        $this->apiConfig = config($configPath);
        
        if (is_null($this->apiConfig) || empty($this->apiConfig['base_url']) || empty($this->apiConfig['api_key_name']) || empty($this->apiConfig['api_key'])) {
            $errorMessage = "Configurazione provider API '{$this->activeProvider}' non trovata o incompleta in config/services.php. Controlla la sezione '{$configPath}' e le relative variabili d'ambiente.";
            Log::error($errorMessage, ['loaded_config' => $this->apiConfig]);
            throw new \Exception($errorMessage);
        }
        
        $this->baseUrl = rtrim($this->apiConfig['base_url'], '/'); // Assicura che non ci sia uno slash finale
        $this->apiKey = $this->apiConfig['api_key'];
        $this->apiKeyName = $this->apiConfig['api_key_name'];
        
        if (empty($this->apiKey)) {
            $errorMessage = "Chiave API ({$this->apiKeyName}) per il provider '{$this->activeProvider}' non configurata nel file .env (variabile: {$this->getApiKeyEnvVariable($this->activeProvider)}).";
            Log::error($errorMessage);
            throw new \Exception($errorMessage);
        }
        
        Log::info("PlayerStatsApiService initializzato con provider: {$this->activeProvider}, Base URL: {$this->baseUrl}");
    }
    
    /**
     * Restituisce il nome della variabile d'ambiente per la chiave API del provider.
     */
    private function getApiKeyEnvVariable(string $provider): string
    {
        // Questo è un helper, potresti renderlo più generico se avessi molti provider
        // Basato sulla struttura in config/services.php
        // 'api_key' => env('FOOTBALL_DATA_API_KEY')
        if ($provider === 'football_data_org') {
            return 'FOOTBALL_DATA_API_KEY';
        }
        // Aggiungi altri casi se reintroduci altri provider
        return 'CHIAVE_API_SCONOSCIUTA';
    }
    
    /**
     * Esegue una richiesta HTTP all'API.
     */
    protected function makeRequest(string $endpoint, array $params = [], string $method = 'GET')
    {
        $fullUrl = $this->baseUrl . '/' . ltrim($endpoint, '/');
        $method = strtoupper($method); // Assicura che il metodo sia maiuscolo
        
        Log::info("PlayerStatsApiService: Effettuando richiesta {$method} a {$fullUrl}", ['params' => $params]);
        
        try {
            $response = Http::withHeaders([
                $this->apiKeyName => $this->apiKey,
            ])->timeout(30); // Timeout di 30 secondi
            
            if ($method === 'GET') {
                $response = $response->get($fullUrl, $params);
            } else {
                // Aggiungi gestione per altri metodi se necessario (POST, PUT, ecc.)
                $response = $response->{$method}($fullUrl, $params);
            }
            
            if ($response->successful()) {
                Log::info("PlayerStatsApiService: Richiesta API a {$fullUrl} riuscita (status {$response->status()}).");
                return $response->json();
            }
            
            // Gestione errori specifici di football-data.org
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
                'response_body' => $response->body(), // Logga il corpo per debug
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
    
    /**
     * Recupera le squadre per una data competizione e stagione.
     * L'endpoint per football-data.org è competitions/{competitionCode}/teams?season={seasonStartYear}
     */
    public function getTeamsForCompetitionAndSeason(string $competitionCode, int $seasonStartYear): ?array
    {
        // football-data.org usa l'anno di inizio della stagione (es. 2023 per la stagione 2023/24)
        $endpoint = "competitions/{$competitionCode}/teams";
        $params = ['season' => $seasonStartYear];
        return $this->makeRequest($endpoint, $params);
    }
    
    /**
     * Recupera le classifiche per una data competizione e stagione.
     * L'endpoint per football-data.org è competitions/{competitionCode}/standings?season={seasonStartYear}
     */
    public function getStandingsForCompetitionAndSeason(string $competitionCode, int $seasonStartYear): ?array
    {
        $endpoint = "competitions/{$competitionCode}/standings";
        $params = ['season' => $seasonStartYear];
        return $this->makeRequest($endpoint, $params);
    }
    
    /**
     * Recupera i dettagli di un singolo giocatore (persona) dall'ID API.
     * L'endpoint per football-data.org è /v4/persons/{id}
     */
    public function getPlayerDetails(int $apiPlayerId): ?array
    {
        // Nota: football-data.org usa 'persons' e non 'players' per i dettagli del singolo giocatore.
        // L'ID deve essere quello specifico di football-data.org per quella persona.
        $endpoint = "persons/{$apiPlayerId}";
        return $this->makeRequest($endpoint);
    }
}