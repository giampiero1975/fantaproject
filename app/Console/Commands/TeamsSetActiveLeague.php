<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TeamDataService;
use App\Services\PlayerStatsApiService;
use App\Models\Team;
use Illuminate\Support\Facades\Log; // <-- AGGIUNGI QUESTA RIGA!
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
        
        $this->info("Avvio comando TeamsSetActiveLeague per lega {$leagueCode}, stagione {$seasonDisplay}. Inattivazione preliminare: " . ($setInactiveFirst ? 'Sì' : 'No'));
        
        $startTime = microtime(true);
        $updatedCount = 0;
        $createdCount = 0;
        $notFoundCount = 0;
        $failedApiCount = 0;
        
        if ($setInactiveFirst) {
            // Disattiva tutte le squadre in Serie A, indipendentemente dalla stagione
            $inactiveCount = Team::where('serie_a_team', true)->update(['serie_a_team' => false]);
            $this->info("Impostato serie_a_team=false per {$inactiveCount} team.");
        }
        
        $this->info("Recupero squadre da API per {$leagueCode}, stagione {$targetSeasonStartYear}...");
        // Usa il PlayerStatsApiService iniettato
        $apiTeamsData = $this->playerStatsApiService->getTeamsForCompetitionAndSeason($leagueCode, $targetSeasonStartYear);
        
        if (!$apiTeamsData || !isset($apiTeamsData['teams']) || empty($apiTeamsData['teams'])) {
            $errorMessage = "Nessuna squadra ricevuta dall'API per lega {$leagueCode}, stagione {$targetSeasonStartYear}, o risposta API vuota/malformata.";
            $this->error($errorMessage);
            Log::error($errorMessage, ['api_response' => $apiTeamsData]);
            ImportLog::create([
                'import_type' => 'set_active_teams_' . strtolower($leagueCode),
                'status' => 'fallito',
                'details' => $errorMessage,
                'original_file_name' => "API Fetch {$leagueCode} {$seasonDisplay}"
                ]);
            return Command::FAILURE;
        }
        
        $this->info("Trovate " . count($apiTeamsData['teams']) . " squadre dall'API. Processamento...");
        
        foreach ($apiTeamsData['teams'] as $apiTeam) {
            if (empty($apiTeam['id']) || empty($apiTeam['name'])) {
                $this->warn("Dati squadra API incompleti o corrotti, saltata: " . json_encode($apiTeam));
                $failedApiCount++;
                continue;
            }
            
            // Passa l'anno della stagione a TeamDataService per salvarlo
            $team = $this->teamDataService->updateOrCreateTeamFromApiData($apiTeam, $leagueCode, $targetSeasonStartYear); // <-- PASSATO targetSeasonYear
            
            if ($team) {
                // Applica il flag corretto in base alla lega
                if ($leagueCode === 'SA') {
                    $team->serie_a_team = true;
                }
                // Il campo season_year è già impostato in TeamDataService::updateOrCreateTeamFromApiData
                
                if ($team->isDirty() || $team->wasRecentlyCreated) {
                    $team->save();
                }
                
                if ($team->wasRecentlyCreated) {
                    $createdCount++;
                }
                $updatedCount++;
                $this->line("Processata squadra API '{$apiTeam['name']}' (API ID: {$apiTeam['id']}) -> DB Team '{$team->name}' (DB ID: {$team->id})");
                
            } else {
                $this->warn("Fallimento nel creare/aggiornare squadra API '{$apiTeam['name']}' (API ID: {$apiTeam['id']}) tramite TeamDataService.");
                $notFoundCount++;
            }
        }
        
        $duration = microtime(true) - $startTime;
        $summary = "Comando TeamsSetActiveLeague completato per lega {$leagueCode}, stagione {$seasonDisplay} in " . round($duration, 2) . " secondi. ";
        $summary .= "Squadre processate con successo (create/aggiornate nel DB): {$updatedCount} (di cui nuove: {$createdCount}). ";
        $summary .= "Fallimenti creazione/aggiornamento via servizio: {$notFoundCount}. ";
        $summary .= "Squadre API con dati incompleti saltate: {$failedApiCount}.";
        
        $this->info($summary);
        Log::info($summary);
        
        ImportLog::create([
            'import_type' => 'set_active_teams_' . strtolower($leagueCode),
            'status' => ($notFoundCount === 0 && $failedApiCount === 0) ? 'successo' : 'parziale',
            'details' => $summary,
            'rows_created' => $createdCount,
            'rows_updated' => $updatedCount - $createdCount,
            'rows_failed' => $notFoundCount + $failedApiCount,
            'original_file_name' => "API Fetch {$leagueCode} {$seasonDisplay}"
            ]);
        
        return Command::SUCCESS;
    }
}