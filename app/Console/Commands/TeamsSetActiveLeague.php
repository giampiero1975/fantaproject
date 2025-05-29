<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TeamsSetActiveLeague extends Command
{
    // Modifica della signature
    protected $signature = 'teams:set-active-league
                           {--target-season-start-year= : The start year of the season for which to set active teams (e.g., 2024 for 2024-25)}
                           {--league-code=SA : The league code (e.g., SA for Serie A)}
                           {--set-inactive-first=true : Set all teams to inactive in this league first (only for SA currently)}';
    
    protected $description = 'Sets the active status (serie_a_team flag) for teams in a given league by fetching them from an API.';
    
    protected string $apiKey;
    protected string $baseUri;
    
    public function __construct()
    {
        parent::__construct();
        $this->apiKey = config('services.football_data.key');
        $this->baseUri = config('services.football_data.base_uri');
    }
    
    private function normalizeName(string $name): string // Funzione di utilità
    {
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
        $targetSeasonStartYear = $this->option('target-season-start-year');
        $leagueCode = strtoupper($this->option('league-code'));
        $setInactiveFirst = filter_var($this->option('set-inactive-first'), FILTER_VALIDATE_BOOLEAN);
        
        if (!$targetSeasonStartYear || !ctype_digit($targetSeasonStartYear) || strlen($targetSeasonStartYear) !== 4) {
            $this->error('Anno di inizio stagione target mancante o non valido. Usa --target-season-start-year=YYYY.');
            return Command::FAILURE;
        }
        
        if (empty($this->apiKey)) {
            $this->error('API Key per Football-Data.org non configurata.');
            return Command::FAILURE;
        }
        
        // Endpoint per ottenere i team di una competizione per una specifica stagione
        // Verifica la documentazione di Football-Data.org v4 per l'endpoint corretto.
        // Potrebbe essere /competitions/{id}/teams?season={year} o dalle classifiche /standings
        $teamsApiEndpoint = "competitions/{$leagueCode}/teams?season={$targetSeasonStartYear}";
        // Alternativa se /teams non supporta ?season, potresti dover prendere i team da /standings
        // $teamsApiEndpoint = "competitions/{$leagueCode}/standings?season={$targetSeasonStartYear}";
        
        
        $this->info("Recupero squadre da API per lega {$leagueCode}, stagione che inizia nel {$targetSeasonStartYear}...");
        Log::info(self::class . ": Recupero squadre da API per {$leagueCode}, stagione {$targetSeasonStartYear} da endpoint: {$teamsApiEndpoint}");
        
        try {
            $response = Http::withHeaders(['X-Auth-Token' => $this->apiKey])
            ->baseUrl($this->baseUri)
            ->get($teamsApiEndpoint);
            
            if ($response->failed()) {
                $this->error("Errore API ({$response->status()}) recuperando squadre per {$leagueCode} stagione {$targetSeasonStartYear}: " . $response->body());
                Log::error(self::class . ": Errore API recupero squadre {$leagueCode} st. {$targetSeasonStartYear}. Status: {$response->status()}, Body: " . $response->body());
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("Eccezione durante chiamata API: " . $e->getMessage());
            Log::error(self::class . ": Eccezione API: " . $e->getMessage());
            return Command::FAILURE;
        }
        
        $apiData = $response->json();
        $apiTeams = [];
        
        // Estrai i team dalla risposta API. La struttura può variare.
        // Se l'endpoint è /teams?season=...
        if (isset($apiData['teams']) && is_array($apiData['teams'])) {
            $apiTeams = $apiData['teams'];
        }
        // Se l'endpoint è /standings?season=... (e prendi i team dalla tabella della classifica)
        elseif (isset($apiData['standings'][0]['table']) && is_array($apiData['standings'][0]['table'])) {
            foreach ($apiData['standings'][0]['table'] as $entry) {
                if (isset($entry['team'])) {
                    $apiTeams[] = $entry['team']; // L'oggetto team qui potrebbe avere solo id, name, tla, crest
                }
            }
        }
        
        if (empty($apiTeams)) {
            $this->warn("Nessuna squadra trovata dall'API per lega {$leagueCode} stagione {$targetSeasonStartYear}. Risposta: " . substr(json_encode($apiData),0,300));
            return Command::SUCCESS;
        }
        
        $this->info("Trovate " . count($apiTeams) . " squadre dall'API per {$leagueCode} stagione {$targetSeasonStartYear}.");
        
        DB::beginTransaction();
        try {
            $activatedCount = 0;
            $alreadyActiveCount = 0;
            $notFoundInDbCount = 0;
            $deactivatedCount = 0;
            
            if ($leagueCode === 'SA' && $setInactiveFirst) {
                $this->info("Imposto 'serie_a_team = false' per tutte le squadre prima di attivare quelle specificate...");
                $deactivatedCount = Team::query()->update(['serie_a_team' => false]);
                Log::info(self::class . ": Impostato serie_a_team=false per {$deactivatedCount} team.");
            }
            
            $localTeamsByIdApi = Team::whereNotNull('api_football_data_team_id')->get()->keyBy('api_football_data_team_id');
            $localTeamsByName = Team::all()->mapWithKeys(function ($team) {
                $map = [];
                $normName = $this->normalizeName($team->name);
                $normShortName = $this->normalizeName($team->short_name ?? '');
                if ($normName) $map[$normName] = $team;
                if ($normShortName && $normShortName !== $normName) $map[$normShortName] = $team;
                return $map;
            })->filter();
            
            
            foreach ($apiTeams as $apiTeamData) {
                $apiTeamId = $apiTeamData['id'] ?? null;
                $apiTeamName = $apiTeamData['name'] ?? null;
                
                if (!$apiTeamId || !$apiTeamName) {
                    Log::warning(self::class . ": Record squadra API incompleto (manca ID o Nome): " . json_encode($apiTeamData));
                    continue;
                }
                
                $team = $localTeamsByIdApi->get($apiTeamId);
                
                if (!$team) { // Se non trovato per ID API, prova per nome
                    $normalizedApiName = $this->normalizeName($apiTeamName);
                    $team = $localTeamsByName->get($normalizedApiName);
                    
                    if ($team && $team->api_football_data_team_id != $apiTeamId) { // Trovato per nome, aggiorna ID API
                        Log::info(self::class.": Team '{$team->name}' trovato per nome, aggiorno API ID a {$apiTeamId}. Nome API: '{$apiTeamName}'");
                        $team->api_football_data_team_id = $apiTeamId;
                        // Non salvare qui, lo faremo dopo aver impostato serie_a_team
                    }
                }
                
                if ($team) {
                    if ($leagueCode === 'SA') { // Gestisci solo il flag serie_a_team per la Serie A
                        if (!$team->serie_a_team) {
                            $team->serie_a_team = true;
                            $team->save(); // Salva sia l'ID API (se aggiornato) sia il flag
                            $this->line("Team '{$team->name}' (ID DB: {$team->id}, API ID: {$apiTeamId}) impostato come ATTIVO in Serie A.");
                            Log::info(self::class . ": Team '{$team->name}' (DB ID: {$team->id}) attivato per SA.");
                            $activatedCount++;
                        } else {
                            // Se era già attivo e l'ID API è stato aggiornato sopra, salva comunque
                            if ($team->isDirty('api_football_data_team_id')) {
                                $team->save();
                                $this->line("Team '{$team->name}' (DB ID: {$team->id}) era già attivo in SA, ID API aggiornato a {$apiTeamId}.");
                            } else {
                                $this->line("Team '{$team->name}' (DB ID: {$team->id}) era già attivo in SA.");
                            }
                            $alreadyActiveCount++;
                        }
                    } else {
                        // Per altre leghe, potresti solo loggare o avere altri flag
                        $this->line("Identificato team '{$team->name}' (DB ID: {$team->id}) per lega {$leagueCode}. Flag 'serie_a_team' non modificato.");
                    }
                } else {
                    $this->warn("Squadra API '{$apiTeamName}' (API ID: {$apiTeamId}) non trovata nel DB locale. Considera di crearla o migliorare il matching.");
                    Log::warning(self::class . ": Squadra API '{$apiTeamName}' (API ID: {$apiTeamId}) non trovata nel DB locale.");
                    $notFoundInDbCount++;
                }
            }
            
            DB::commit();
            $this->info("\nOperazione completata per lega {$leagueCode}, stagione {$targetSeasonStartYear}.");
            if ($leagueCode === 'SA') {
                $this->info("Squadre il cui flag 'serie_a_team' è stato impostato a true: {$activatedCount}.");
                $this->info("Squadre che erano già attive in Serie A: {$alreadyActiveCount}.");
                if ($setInactiveFirst) {
                    $this->info("Squadre totali inizialmente disattivate: {$deactivatedCount}.");
                }
            }
            if ($notFoundInDbCount > 0) {
                $this->warn("Squadre API non trovate nel DB locale: {$notFoundInDbCount}.");
            }
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("Errore durante l'aggiornamento: " . $e->getMessage());
            Log::error(self::class . ": Eccezione: " . $e->getMessage(), $e->getTrace());
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }
}