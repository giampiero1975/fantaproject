<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TeamDataService;

class TeamsFetchHistoricalStandings extends Command
{
    // AGGIUNGI --competition ALLA SIGNATURE
    protected $signature = 'teams:fetch-historical-standings {--season=} {--all-recent=} {--competition=SA}';
    protected $description = 'Fetches historical standings for teams from Football-Data.org API for a specific competition';
    
    protected TeamDataService $teamDataService;
    
    public function __construct(TeamDataService $teamDataService)
    {
        parent::__construct();
        $this->teamDataService = $teamDataService;
    }
    
    public function handle()
    {
        $seasonStartYearOption = $this->option('season');
        $allRecentOption = $this->option('all-recent');
        $competitionId = strtoupper($this->option('competition')); // Prendi la competition dalla option
        
        // ... (logica per determinare $years rimane uguale) ...
        if ($seasonStartYearOption) {
            $years = [(int)$seasonStartYearOption];
        } elseif ($allRecentOption) {
            $currentYear = now()->year;
            $numRecent = (int)$allRecentOption;
            $years = [];
            for ($i = 0; $i < $numRecent; $i++) {
                $years[] = $currentYear - 1 - $i;
            }
        } else {
            $this->error('Specificare --season=YYYY o --all-recent=N');
            return 1;
        }
        
        $this->info("Recupero classifiche per competizione {$competitionId}, stagioni che iniziano in: " . implode(', ', $years));
        
        foreach ($years as $year) {
            $this->info("--- Processando {$competitionId} stagione che inizia nel {$year} ---");
            
            // Passa $competitionId al servizio
            if ($this->teamDataService->fetchAndStoreSeasonStandings($year, $competitionId)) {
                $this->info("Classifica {$competitionId} per la stagione {$year}-".substr($year+1, 2,2)." recuperata e salvata.");
            } else {
                $this->warn("Problemi nel recuperare/salvare classifica {$competitionId} per stagione {$year}-".substr($year+1, 2,2).". Controllare log.");
            }
            if(count($years) > 1) sleep(config('services.football_data.api_delay_seconds', 7)); // Usa una config per il delay
        }
        $this->info('Completato.');
        return 0;
    }
}