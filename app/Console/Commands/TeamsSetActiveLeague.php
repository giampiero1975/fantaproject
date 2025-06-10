<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TeamDataService;
use App\Services\PlayerStatsApiService;
use App\Models\Team;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\ImportLog;

class TeamsSetActiveLeague extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'teams:set-active-league
                            {--target-season-start-year= : Anno di inizio della stagione target (es. 2023 per 2023-24)}
                            {--league-code=SA : Codice della lega (es. SA per Serie A, SB per Serie B)}
                            {--set-inactive-first=true : Imposta tutte le squadre a inactive prima di procedere (true o false)}';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Imposta le squadre attive per una lega e stagione specificata, recuperandole da API esterna.';
    
    protected TeamDataService $teamDataService;
    protected PlayerStatsApiService $playerStatsApiService;
    
    public function __construct(TeamDataService $teamDataService, PlayerStatsApiService $playerStatsApiService)
    {
        parent::__construct();
        $this->teamDataService = $teamDataService;
        $this->playerStatsApiService = $playerStatsApiService;
        Log::info('TeamsSetActiveLeague Command initializzato con TeamDataService e PlayerStatsApiService.');
    }
    
    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // --- SETUP INIZIALE ---
        $targetSeasonStartYear = $this->option('target-season-start-year');
        $leagueCode = strtoupper($this->option('league-code'));
        $setInactiveFirstOption = $this->option('set-inactive-first');
        $setInactiveFirst = filter_var($setInactiveFirstOption, FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]) ?? true;
        
        if (empty($targetSeasonStartYear) || !is_numeric($targetSeasonStartYear)) {
            $this->error('Anno di inizio stagione target non specificato o non valido (--target-season-start-year).');
            return Command::FAILURE;
        }
        $targetSeasonStartYear = (int)$targetSeasonStartYear;
        $seasonDisplay = $targetSeasonStartYear . '-' . substr($targetSeasonStartYear + 1, 2);
        
        $this->info("Avvio comando per lega {$leagueCode}, stagione {$seasonDisplay}. Inattivazione preliminare: " . ($setInactiveFirst ? 'Sì' : 'No'));
        
        $startTime = microtime(true);
        $updatedCount = 0;
        $createdCount = 0;
        $failedApiCount = 0;
        
        if ($setInactiveFirst) {
            $inactiveCount = Team::where('serie_a_team', true)->update(['serie_a_team' => false]);
            $this->info("Impostato serie_a_team=false per {$inactiveCount} team.");
        }
        
        $this->info("Recupero squadre da API per {$leagueCode}, stagione {$targetSeasonStartYear}...");
        $apiTeamsData = $this->playerStatsApiService->getTeamsForCompetitionAndSeason($leagueCode, $targetSeasonStartYear);
        
        if (empty($apiTeamsData['teams'])) {
            $errorMessage = "Nessuna squadra ricevuta dall'API per lega {$leagueCode}, stagione {$targetSeasonStartYear}.";
            $this->error($errorMessage);
            Log::error($errorMessage, ['api_response' => $apiTeamsData ?? null]);
            return Command::FAILURE;
        }
        
        $this->info("Trovate " . count($apiTeamsData['teams']) . " squadre dall'API. Processamento...");
        foreach ($apiTeamsData['teams'] as $apiTeam) {
            if (empty($apiTeam['id']) || empty($apiTeam['name'])) {
                $this->warn("Dati squadra API incompleti, saltata: " . json_encode($apiTeam));
                $failedApiCount++;
                continue;
            }
            
            $apiTeamId = $apiTeam['id'];
            $apiTeamName = $apiTeam['name'];
            $apiTeamShortName = $apiTeam['shortName'] ?? null;
            $apiTla = $apiTeam['tla'] ?? null;
            $apiCrestUrl = $apiTeam['crest'] ?? null;
            
            // --- Livello 1: Cerca per API ID ---
            $team = Team::where('api_football_data_id', $apiTeamId)->first();
            
            if ($team) {
                // --- TROVATO TRAMITE ID ---
                $this->line("Match su API ID ({$apiTeamId}): Aggiorno '{$team->name}'.");
                $team->name = $apiTeamName;
                $team->short_name = $apiTeamShortName;
                $team->tla = $apiTla;
                $team->crest_url = $apiCrestUrl;
                $team->league_code = $leagueCode;
                $team->season_year = $targetSeasonStartYear;
                if ($leagueCode === 'SA') {
                    $team->serie_a_team = true;
                }
                $team->save();
                $updatedCount++;
            } else {
                // --- Livello 2: Cerca per TLA ---
                $this->line("Nessun match per API ID {$apiTeamId}. Cerco per TLA: '{$apiTla}'...");
                $team = null;
                if ($apiTla) {
                    $team = Team::where('tla', $apiTla)->first();
                }
                
                if ($team) {
                    // --- TROVATO TRAMITE TLA ---
                    $this->line("Match su TLA: '{$apiTla}' (DB ID: {$team->id}). Collego API ID {$apiTeamId}.");
                    $team->api_football_data_id = $apiTeamId;
                    $team->name = $apiTeamName;
                    $team->short_name = $apiTeamShortName;
                    $team->crest_url = $apiCrestUrl;
                    $team->league_code = $leagueCode;
                    $team->season_year = $targetSeasonStartYear;
                    if ($leagueCode === 'SA') {
                        $team->serie_a_team = true;
                    }
                    $team->save();
                    $updatedCount++;
                } else {
                    // --- Livello 3: Cerca con LIKE su nome e short_name ---
                    $this->line("Nessun match per TLA. Cerco per nome con LIKE/LOWER...");
                    $team = Team::where(function ($query) use ($apiTeamName, $apiTeamShortName) {
                        $query->where(DB::raw('LOWER(name)'), 'LIKE', '%' . strtolower($apiTeamName) . '%')
                        ->orWhere(DB::raw('LOWER(short_name)'), 'LIKE', '%' . strtolower($apiTeamName) . '%');
                        
                        if ($apiTeamShortName && $apiTeamShortName !== $apiTeamName) {
                            $query->orWhere(DB::raw('LOWER(name)'), 'LIKE', '%' . strtolower($apiTeamShortName) . '%')
                            ->orWhere(DB::raw('LOWER(short_name)'), 'LIKE', '%' . strtolower($apiTeamShortName) . '%');
                        }
                    })->first();
                    
                    if ($team) {
                        // --- TROVATO TRAMITE LIKE ---
                        $this->line("Match su NOME (LIKE): '{$apiTeamName}' (DB ID: {$team->id}). Collego API ID {$apiTeamId}.");
                        $team->api_football_data_id = $apiTeamId;
                        $team->name = $apiTeamName;
                        $team->short_name = $apiTeamShortName;
                        $team->tla = $apiTla;
                        $team->crest_url = $apiCrestUrl;
                        $team->league_code = $leagueCode;
                        $team->season_year = $targetSeasonStartYear;
                        if ($leagueCode === 'SA') {
                            $team->serie_a_team = true;
                        }
                        $team->save();
                        $updatedCount++;
                    } else {
                        // --- Livello 4: Crea Nuova Squadra ---
                        $this->line("Nessun match. Creo nuova squadra: '{$apiTeamName}'.");
                        Team::create([
                            'name' => $apiTeamName,
                            'short_name' => $apiTeamShortName,
                            'tla' => $apiTla,
                            'api_football_data_id' => $apiTeamId,
                            'crest_url' => $apiCrestUrl,
                            'league_code' => $leagueCode,
                            'season_year' => $targetSeasonStartYear,
                            'serie_a_team' => ($leagueCode === 'SA'),
                        ]);
                        $createdCount++;
                    }
                }
            }
        }
        
        // --- SOMMARIO FINALE ---
        $duration = microtime(true) - $startTime;
        $summary = "Comando completato per lega {$leagueCode}, stagione {$seasonDisplay} in " . round($duration, 2) . "s. ";
        $summary .= "Create: {$createdCount}, Aggiornate: " . ($updatedCount) . ". ";
        $summary .= "Squadre API con dati incompleti: {$failedApiCount}.";
        
        $this->info($summary);
        Log::info($summary);
        
        ImportLog::create([
            'import_type' => 'set_active_teams_' . strtolower($leagueCode),
            'status' => ($failedApiCount === 0) ? 'successo' : 'parziale',
            'details' => $summary,
            'rows_created' => $createdCount,
            'rows_updated' => $updatedCount,
            'rows_failed' => $failedApiCount,
            'original_file_name' => "API Fetch {$leagueCode} {$seasonDisplay}"
            ]);
        
        return Command::SUCCESS;
    }
}