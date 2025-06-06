<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TeamDataService;
use App\Models\ImportLog; // <-- AGGIUNGI QUESTO
use Illuminate\Support\Facades\Log; // <-- AGGIUNGI QUESTO se non già presente

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
        $competitionId = strtoupper($this->option('competition'));
        
        $years = [];
        if ($seasonStartYearOption) {
            $years = [(int)$seasonStartYearOption];
        } elseif ($allRecentOption) {
            $currentYear = now()->year;
            $numRecent = (int)$allRecentOption;
            for ($i = 0; $i < $numRecent; $i++) {
                $years[] = $currentYear - 1 - $i; // Classifiche per stagioni concluse
            }
        } else {
            $this->error('Specificare --season=YYYY o --all-recent=N');
            return Command::FAILURE;
        }
        
        $this->info("Recupero classifiche per competizione {$competitionId}, stagioni che iniziano in: " . implode(', ', $years));
        Log::info(self::class . ": Inizio recupero classifiche per {$competitionId}, anni: " . implode(', ', $years));
        
        $overallSuccess = true; // Diventa true se almeno una stagione ha successo
        $anySeasonProcessedSuccessfully = false;
        $totalSaved = 0;
        $totalNotFound = 0;
        $processedSeasonsCount = 0; // Stagioni per cui abbiamo tentato il fetch
        $successfullyProcessedSeasonsCount = 0; // Stagioni per cui $result['success'] è true
        
        foreach ($years as $year) {
            $seasonDisplay = $year . '-' . substr($year + 1, 2, 2);
            $this->info("--- Processando {$competitionId} stagione {$seasonDisplay} ---");
            $processedSeasonsCount++;
            
            $result = $this->teamDataService->fetchAndStoreSeasonStandings($year, $competitionId);
            
            // $result ora è un array: ['saved' => x, 'notFound' => y, 'success' => bool, 'message' => string]
            if (isset($result['success']) && $result['success']) {
                $totalSaved += $result['saved'];
                $totalNotFound += $result['notFound'];
                $successfullyProcessedSeasonsCount++;
                $anySeasonProcessedSuccessfully = true;
                $this->info("Classifica {$competitionId} per stagione {$seasonDisplay} elaborata. Record salvati/agg.: {$result['saved']}. Team non trovati: {$result['notFound']}. Msg: {$result['message']}");
                Log::info(self::class.": Classifica {$competitionId} st. {$seasonDisplay} - Salvati: {$result['saved']}, Non Trovati: {$result['notFound']}. Msg: {$result['message']}");
            } else {
                $this->warn("Problemi nel recuperare/salvare classifica {$competitionId} per stagione {$seasonDisplay}. Msg: " . ($result['message'] ?? 'Errore generico'));
                Log::warning(self::class.": Tentativo per {$competitionId} stagione {$seasonDisplay} fallito. Msg: " . ($result['message'] ?? 'Errore generico'), ['api_result_details' => $result]);
                // $overallSuccess non viene impostato a false qui a meno che non sia un fallimento totale
            }
            
            if (count($years) > 1 && $year !== end($years)) {
                $delay = config('services.football_data.api_delay_seconds', 7);
                $this->line("Attesa di {$delay} secondi prima della prossima stagione...");
                sleep($delay);
            }
        }
        
        $logStatus = 'fallito';
        if ($anySeasonProcessedSuccessfully) {
            if ($successfullyProcessedSeasonsCount === $processedSeasonsCount) {
                $logStatus = 'successo';
            } else {
                $logStatus = 'parziale';
            }
        }
        
        $summary = "Recupero classifiche API per {$competitionId} completato. Stagioni totali tentate: {$processedSeasonsCount}. Stagioni processate con successo: {$successfullyProcessedSeasonsCount}. ";
        $summary .= "Record classifica totali salvati/aggiornati: {$totalSaved}. ";
        $summary .= "Team API totali non trovati nel DB durante l'elaborazione: {$totalNotFound}.";
        
        ImportLog::create([
            'original_file_name' => "API Fetch Classifiche {$competitionId} (".implode(', ', $years).")",
            'import_type' => 'standings_api_fetch',
            'status' => $logStatus,
            'details' => $summary,
            'rows_processed' => $totalSaved + $totalNotFound,
            'rows_created' => $totalSaved,
            'rows_updated' => 0, // Semplificazione
            'rows_failed' => $totalNotFound,
            ]);
        
        $this->info($summary);
        Log::info(self::class . ": " . $summary);
        return ($logStatus === 'successo' || $logStatus === 'parziale') ? Command::SUCCESS : Command::FAILURE;
    }
}