<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MapTeamApiIdsCommand extends Command
{
    // MODIFICA QUI la signature per accettare --competition e dargli un default SA
    protected $signature = 'teams:map-api-ids {--season=} {--competition=SA}'; // Default SA
    protected $description = 'Maps local teams to Football-Data.org API team IDs for a given competition';
    
    protected string $apiKey;
    protected string $baseUri;
    // Rimuovi $competitionId dal costruttore e dalle proprietà, lo prendiamo dall'opzione
    
    public function __construct()
    {
        parent::__construct();
        $this->apiKey = config('services.football_data.key');
        $this->baseUri = config('services.football_data.base_uri');
        // $this->competitionId NON è più inizializzato qui
    }
    
    private function normalizeName(string $name): string
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
    
    public function handle()
    {
        if (empty($this->apiKey)) {
            $this->error('API Key per Football-Data.org non configurata.');
            Log::error(self::class . ": API Key non configurata.");
            return Command::FAILURE;
        }
        
        $seasonYear = $this->option('season') ?? now()->year;
        $competitionId = strtoupper($this->option('competition')); // Prendi dalla option
        
        $teamsEndpoint = "competitions/{$competitionId}/teams";
        // Se l'API supporta il filtro per stagione per l'endpoint /teams, aggiungilo:
        // $teamsEndpoint .= "?season={$seasonYear}";
        // Altrimenti, l'endpoint /teams di solito restituisce le squadre della stagione corrente di quella competizione.
        
        $this->info("Recupero squadre da API per la competizione {$competitionId} (endpoint: {$teamsEndpoint})");
        Log::info(self::class . ": Recupero squadre da API per {$competitionId} (endpoint: {$teamsEndpoint})");
        
        // ... (resto del codice per chiamata API, matching e salvataggio è identico a prima)
        // Assicurati che il logging usi $competitionId dove appropriato
        try {
            $response = Http::withHeaders(['X-Auth-Token' => $this->apiKey])
            ->baseUrl($this->baseUri)
            ->get($teamsEndpoint);
            
            if (!$response->successful()) {
                $this->error("Errore API ({$response->status()}) recuperando squadre per competizione {$competitionId}: " . $response->body());
                Log::error(self::class . ": Errore API recupero squadre {$competitionId}. Status: {$response->status()}, Body: " . $response->body());
                return Command::FAILURE;
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->error("Eccezione di connessione API per competizione {$competitionId}: " . $e->getMessage());
            Log::error(self::class . ": Eccezione di connessione API per {$competitionId}: " . $e->getMessage());
            return Command::FAILURE;
        }
        
        $apiTeamsData = $response->json();
        
        if (!isset($apiTeamsData['teams']) || empty($apiTeamsData['teams'])) {
            $this->warn("Nessuna squadra trovata dall'API per la competizione {$competitionId}. Risposta: " . substr(json_encode($apiTeamsData), 0, 300));
            Log::warn(self::class . ": Nessuna squadra API trovata per {$competitionId}.");
            return Command::SUCCESS;
        }
        
        $localTeams = Team::all(); // Carica tutti i team locali per il matching
        $mappedCount = 0;
        $this->info("Processo di mappatura per " . count($apiTeamsData['teams']) . " squadre API dalla competizione {$competitionId}...");
        Log::info(self::class . ": Inizio mappatura per " . count($apiTeamsData['teams']) . " squadre API ({$competitionId}). Squadre locali caricate: " . $localTeams->count());
        
        // ... (LA LOGICA DI MATCHING DEI TEAM RIMANE IDENTICA) ...
        foreach ($apiTeamsData['teams'] as $apiTeam) {
            $apiTeamId = $apiTeam['id'];
            $apiTeamName = $apiTeam['name'];
            $apiTeamShortName = $apiTeam['shortName'] ?? '';
            $apiTeamTla = $apiTeam['tla'] ?? '';
            
            $normalizedApiName = $this->normalizeName($apiTeamName);
            $normalizedApiShortName = $this->normalizeName($apiTeamShortName);
            $normalizedApiTla = $this->normalizeName($apiTeamTla);
            
            Log::debug(self::class . ": API Team ({$competitionId}) -> ID: {$apiTeamId}, Nome: '{$apiTeamName}' (Norm: '{$normalizedApiName}'), Short: '{$apiTeamShortName}' (Norm: '{$normalizedApiShortName}'), TLA: '{$apiTeamTla}' (Norm: '{$normalizedApiTla}')");
            
            $foundTeam = null;
            
            $teamById = $localTeams->firstWhere('api_football_data_team_id', $apiTeamId);
            if ($teamById) {
                Log::info(self::class . ": Team '{$teamById->name}' (DB ID: {$teamById->id}) già mappato con API ID {$apiTeamId}.");
                continue;
            }
            
            foreach ($localTeams as $localTeam) {
                if ($localTeam->api_football_data_team_id && $localTeam->api_football_data_team_id != $apiTeamId) {
                    continue;
                }
                
                $normalizedLocalName = $this->normalizeName($localTeam->name);
                $normalizedLocalShortName = $this->normalizeName($localTeam->short_name ?? '');
                
                Log::debug(self::class . ":   Confronto con Local Team -> ID: {$localTeam->id}, Nome: '{$localTeam->name}' (Norm: '{$normalizedLocalName}'), Short: '{$localTeam->short_name}' (Norm: '{$normalizedLocalShortName}')");
                
                if ($normalizedLocalName === $normalizedApiName) { $foundTeam = $localTeam; break; }
                if ($normalizedLocalShortName && $normalizedLocalShortName === $normalizedApiShortName && $normalizedApiShortName) { $foundTeam = $localTeam; break; }
                if ($normalizedLocalShortName && $normalizedLocalShortName === $normalizedApiTla && $normalizedApiTla) { $foundTeam = $localTeam; break; }
                if (str_contains($normalizedLocalName, $normalizedApiName) || str_contains($normalizedApiName, $normalizedLocalName)) {
                    if (abs(strlen($normalizedLocalName) - strlen($normalizedApiName)) < 5) { $foundTeam = $localTeam; break; }
                }
                if ($normalizedLocalShortName && $normalizedApiShortName && (str_contains($normalizedLocalShortName, $normalizedApiShortName) || str_contains($normalizedApiShortName, $normalizedLocalShortName))) {
                    if (abs(strlen($normalizedLocalShortName) - strlen($normalizedApiShortName)) < 4) { $foundTeam = $localTeam; break; }
                }
            }
            
            if ($foundTeam) {
                if (!$foundTeam->api_football_data_team_id || $foundTeam->api_football_data_team_id != $apiTeamId) {
                    $this->line("Mappatura Trovata ({$competitionId}): '{$foundTeam->name}' (DB ID: {$foundTeam->id}) -> '{$apiTeamName}' (API ID: {$apiTeamId})");
                    Log::info(self::class . ": Mappatura Trovata ({$competitionId}): '{$foundTeam->name}' (DB ID: {$foundTeam->id}) -> API ID {$apiTeamId} ('{$apiTeamName}')");
                    $foundTeam->api_football_data_team_id = $apiTeamId;
                    $foundTeam->save();
                    $mappedCount++;
                }
            } else {
                $this->warn("Nessuna corrispondenza locale trovata per API Team ({$competitionId}): '{$apiTeamName}' (ID: {$apiTeamId}, Short: '{$apiTeamShortName}', TLA: '{$apiTeamTla}')");
                Log::warning(self::class . ": Nessuna corrispondenza DB per API Team ({$competitionId}): '{$apiTeamName}' (ID: {$apiTeamId})");
            }
        }
        $this->info("Mappatura completata per {$competitionId}. {$mappedCount} squadre locali aggiornate/mappate con ID API.");
        return Command::SUCCESS;
    }
}