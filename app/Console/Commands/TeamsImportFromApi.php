<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TeamDataService;
use App\Services\PlayerStatsApiService;
use App\Models\Team;
use Illuminate\Support\Facades\Log;
use App\Models\ImportLog;
use Illuminate\Support\Carbon;

class TeamsImportFromApi extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'teams:import-from-api
                            {--league-code=SA : Codice della lega da cui importare le squadre (es. SA per Serie A)}
                            {--force-season= : Forza l\'anno della stagione API da cui importare (es. 2025). Se omesso, cercherà l\'ultima disponibile.}';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Importa le squadre della lega specificata dalla più recente stagione disponibile dell\'API Football-Data.org e le salva nel DB.';
    
    protected TeamDataService $teamDataService;
    protected PlayerStatsApiService $playerStatsApiService;
    
    public function __construct(TeamDataService $teamDataService, PlayerStatsApiService $playerStatsApiService)
    {
        parent::__construct();
        $this->teamDataService = $teamDataService;
        $this->playerStatsApiService = $playerStatsApiService;
        Log::info('TeamsImportFromApi Command initializzato con TeamDataService e PlayerStatsApiService.');
    }
    
    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $leagueCode = strtoupper($this->option('league-code'));
        $forceSeason = $this->option('force-season');
        
        $this->info("Avvio comando TeamsImportFromApi per lega {$leagueCode}.");
        
        $startTime = microtime(true);
        $updatedCount = 0;
        $createdCount = 0;
        $failedApiCount = 0;
        $apiSeasonYear = null; // Stagione effettiva recuperata dall'API
        $teamsToImport = [];
        
        // 1. Determina la stagione più recente disponibile dall'API
        if ($forceSeason) {
            $apiSeasonYear = (int)$forceSeason;
            $this->info("Forzando la stagione API a {$apiSeasonYear}.");
            $apiData = $this->playerStatsApiService->getTeamsForCompetitionAndSeason($leagueCode, $apiSeasonYear);
            if ($apiData && isset($apiData['teams']) && !empty($apiData['teams'])) {
                $teamsToImport = $apiData['teams'];
            } else {
                // Se la forzatura fallisce, resetta per fallimento
                $apiSeasonYear = null;
                $teamsToImport = [];
                $this->error("Forzatura stagione a {$forceSeason} fallita: Nessuna squadra trovata o errore API.");
                Log::error("TeamsImportFromApi: Forzatura stagione {$forceSeason} fallita.");
            }
        } else {
            $currentYear = date('Y'); // Questo sarà 2025
            // Prova l'anno corrente, poi l'anno precedente, poi l'anno successivo (o adatta se sai che 2023 funziona sempre)
            $possibleSeasonYears = [$currentYear, $currentYear - 1, $currentYear + 1];
            rsort($possibleSeasonYears); // Ordina dal più recente al meno recente: [2026, 2025, 2024]
            
            foreach ($possibleSeasonYears as $year) {
                $this->line("Tentativo di recuperare squadre per la stagione {$year}...");
                $apiData = $this->playerStatsApiService->getTeamsForCompetitionAndSeason($leagueCode, $year);
                
                // --- DEBUG AGGIUNTO QUI ---
                Log::debug("TeamsImportFromApi Debug: Chiamata API per stagione {$year} status: " . ($apiData ? 'Successo' : 'Fallito') . ", teams count: " . (isset($apiData['teams']) ? count($apiData['teams']) : 'N/A'));
                if ($apiData && isset($apiData['season']['startDate'])) {
                    Log::debug("TeamsImportFromApi Debug: API per {$year} restituita stagione startDate: " . $apiData['season']['startDate']);
                }
                // --- FINE DEBUG ---
                
                if ($apiData && isset($apiData['teams']) && !empty($apiData['teams'])) {
                    if (isset($apiData['season']['startDate'])) {
                        $returnedSeasonYear = (int) Carbon::parse($apiData['season']['startDate'])->format('Y');
                        if ($returnedSeasonYear === $year) {
                            $apiSeasonYear = $year;
                            $teamsToImport = $apiData['teams'];
                            $this->info("Trovata la stagione più recente dall'API: {$apiSeasonYear}.");
                            break;
                        } else {
                            Log::warning("TeamsImportFromApi: Stagione richiesta {$year} non corrisponde alla stagione restituita dall'API ({$returnedSeasonYear}).");
                        }
                    } else {
                        Log::warning("TeamsImportFromApi: Risposta API per stagione {$year} non contiene 'season.startDate'.");
                    }
                }
            }
        }
        
        if (!$apiSeasonYear || empty($teamsToImport)) {
            $errorMessage = "Impossibile determinare l'ultima stagione disponibile dall'API per la lega {$leagueCode} o nessuna squadra trovata. Nessuna operazione eseguita.";
            $this->error($errorMessage);
            Log::error($errorMessage);
            ImportLog::create([
                'import_type' => 'teams_import_api',
                'status' => 'fallito',
                'details' => $errorMessage,
                'original_file_name' => "API Fetch {$leagueCode} (Season/Teams Detection Failed)"
                ]);
            return Command::FAILURE;
        }
        
        $seasonDisplay = $apiSeasonYear . '-' . substr($apiSeasonYear + 1, 2);
        $this->info("Procedo con l'importazione di " . count($teamsToImport) . " squadre per la stagione API {$seasonDisplay}.");
        
        // ... (resto del metodo handle, invariato) ...
        foreach ($teamsToImport as $apiTeam) {
            if (empty($apiTeam['id']) || empty($apiTeam['name'])) {
                $this->warn("Dati squadra API incompleti o corrotti, saltata: " . json_encode($apiTeam));
                $failedApiCount++;
                continue;
            }
            
            $team = $this->teamDataService->updateOrCreateTeamFromApiData($apiTeam, $leagueCode, $apiSeasonYear);
            
            if ($team) {
                if ($team->wasRecentlyCreated) {
                    $createdCount++;
                    $this->line("Creata nuova squadra API '{$apiTeam['name']}' (API ID: {$apiTeam['id']}) -> DB Team '{$team->name}' (DB ID: {$team->id})");
                } else {
                    $updatedCount++;
                    $this->line("Aggiornata squadra API '{$apiTeam['name']}' (API ID: {$apiTeam['id']}) -> DB Team '{$team->name}' (DB ID: {$team->id})");
                }
                
            } else {
                $this->warn("Fallimento nel creare/aggiornare squadra API '{$apiTeam['name']}' (API ID: {$apiTeam['id']}) tramite TeamDataService.");
                $failedApiCount++;
            }
        }
        
        $duration = microtime(true) - $startTime;
        $totalProcessed = $createdCount + $updatedCount;
        $summary = "Comando TeamsImportFromApi completato per lega {$leagueCode}, stagione {$seasonDisplay} in " . round($duration, 2) . " secondi. ";
        $summary .= "Squadre importate/aggiornate con successo nel DB: {$totalProcessed} (di cui nuove: {$createdCount}, aggiornate: {$updatedCount}). ";
        $summary .= "Fallimenti/dati incompleti: {$failedApiCount}.";
        
        $this->info($summary);
        Log::info($summary);
        
        ImportLog::create([
            'import_type' => 'teams_import_api',
            'status' => ($failedApiCount === 0) ? 'successo' : 'parziale',
            'details' => $summary,
            'rows_created' => $createdCount,
            'rows_updated' => $updatedCount,
            'rows_failed' => $failedApiCount,
            'original_file_name' => "API Import {$leagueCode} {$seasonDisplay}"
            ]);
        
        return Command::SUCCESS;
    }
}