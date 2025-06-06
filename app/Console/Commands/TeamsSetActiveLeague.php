<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TeamDataService;
use App\Services\PlayerStatsApiService; // Già presente
use App\Models\Team;
use Illuminate\Support\Facades\Log;
use App\Models\ImportLog; // Aggiunto per il logging delle operazioni

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
    // Rimosse le proprietà $apiKey e $apiHost locali, non sono necessarie qui
    
    /**
     * Create a new command instance.
     *
     * @param TeamDataService $teamDataService
     * @param PlayerStatsApiService $playerStatsApiService
     * @return void
     */
    public function __construct(TeamDataService $teamDataService, PlayerStatsApiService $playerStatsApiService)
    {
        parent::__construct();
        $this->teamDataService = $teamDataService;
        $this->playerStatsApiService = $playerStatsApiService; // Il servizio API è già configurato con la sua chiave
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
        // L'opzione 'set-inactive-first' viene letta come stringa 'true'/'false' o null se non passata.
        // Convertiamola in booleano in modo più robusto.
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
        $createdCount = 0; // Aggiunto per contare le squadre create dal servizio
        $notFoundCount = 0; // Rinominato da $notFoundInDb per chiarezza nel comando
        $failedApiCount = 0;
        
        if ($setInactiveFirst) {
            // Disattiva tutte le squadre per la lega specificata o tutte se la logica è globale per serie_a_team
            // Se hai un campo 'current_league_code' in teams, potresti usarlo per un reset più mirato.
            // Per ora, se è Serie A, disattiva tutte le serie_a_team.
            // Se vuoi una logica per disattivare SOLO quelle della $leagueCode specifica, dovrai adattare.
            if ($leagueCode === 'SA') { // Esempio specifico per Serie A
                $inactiveCount = Team::where('serie_a_team', true)->update(['serie_a_team' => false]);
                $this->info("Impostato serie_a_team=false per {$inactiveCount} team.");
            } else {
                // Per altre leghe, potresti avere una logica diversa o un campo diverso da resettare.
                // Se 'league_code' in teams traccia la lega corrente, potresti fare:
                // $inactiveCount = Team::where('league_code', $leagueCode)->update(['active_in_league' => false]); // Esempio
                $this->comment("Logica di inattivazione preliminare per lega {$leagueCode} non implementata specificamente, saltata.");
            }
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
            
            $team = $this->teamDataService->updateOrCreateTeamFromApiData($apiTeam, $leagueCode);
            
            if ($team) {
                // Applica il flag corretto in base alla lega
                if ($leagueCode === 'SA') {
                    $team->serie_a_team = true;
                }
                // Aggiungi logica per altre leghe se necessario, es:
                // elseif ($leagueCode === 'SB') { $team->serie_b_team = true; }
                // Potresti anche voler aggiornare $team->league_code se lo usi per la lega *corrente* della squadra
                // $team->league_code = $leagueCode; // L'abbiamo già passato a updateOrCreateTeamFromApiData
                
                if ($team->isDirty()) { // Salva solo se ci sono modifiche
                    $team->save();
                }
                
                if ($team->wasRecentlyCreated) { // Questo flag è disponibile subito dopo updateOrCreate
                    $createdCount++;
                }
                $updatedCount++; // Conta sia i creati che gli aggiornati come "processati con successo" qui
                $this->line("Processata squadra API '{$apiTeam['name']}' (API ID: {$apiTeam['id']}) -> DB Team '{$team->name}' (DB ID: {$team->id})");
                
            } else {
                // Questo log ora indica un fallimento di updateOrCreateTeamFromApiData
                $this->warn("Fallimento nel creare/aggiornare squadra API '{$apiTeam['name']}' (API ID: {$apiTeam['id']}) tramite TeamDataService.");
                $notFoundCount++; // Indica che il servizio non ha restituito un team valido
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
            'import_type' => 'set_active_teams_' . strtolower($leagueCode), // Es. 'set_active_teams_sa'
            'status' => ($notFoundCount === 0 && $failedApiCount === 0) ? 'successo' : 'parziale',
            'details' => $summary,
            'rows_created' => $createdCount,
            'rows_updated' => $updatedCount - $createdCount, // Solo gli aggiornati
            'rows_failed' => $notFoundCount + $failedApiCount,
            'original_file_name' => "API Fetch {$leagueCode} {$seasonDisplay}"
            ]);
        
        return Command::SUCCESS;
    }
}