<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\LeagueStandingsScrapingService;
use App\Models\TeamHistoricalStanding;
use App\Models\Team;
use App\Models\ImportLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB; // Per DB::raw

class TeamsScrapeStandings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'teams:scrape-standings
                            {url : L\'URL completo della pagina della classifica su FBRef (es. https://fbref.com/it/comps/11/10728/2021-2022-Serie-B-Stats)}
                            {--season= : Anno di inizio della stagione (es. 2021 per 2021-22)}
                            {--league= : Nome della lega (es. Serie A, Serie B)}';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Esegue lo scraping di una classifica storica da un URL FBRef e salva i dati nel database.';
    
    protected LeagueStandingsScrapingService $scrapingService;
    
    public function __construct(LeagueStandingsScrapingService $scrapingService)
    {
        parent::__construct();
        $this->scrapingService = $scrapingService;
    }
    
    public function handle(): int
    {
        $url = $this->argument('url');
        $seasonYear = $this->option('season');
        $leagueName = $this->option('league');
        
        if (empty($url) || empty($seasonYear) || empty($leagueName)) {
            $this->error("URL, stagione (--season) e nome della lega (--league) sono obbligatori.");
            return Command::FAILURE;
        }
        
        $seasonDisplay = $seasonYear . '-' . substr($seasonYear + 1, 2);
        $this->info("Avvio scraping classifica per lega {$leagueName}, stagione {$seasonDisplay} da URL: {$url}");
        
        $startTime = microtime(true);
        $recordsSaved = 0;
        $failedRecords = 0;
        
        $this->scrapingService->setTargetUrl($url);
        $scrapedData = $this->scrapingService->scrapeStandings();
        
        if (isset($scrapedData['error'])) {
            $errorMessage = "Errore durante lo scraping della classifica: " . $scrapedData['error'];
            $this->error($errorMessage);
            Log::error("TeamsScrapeStandings: " . $errorMessage);
            ImportLog::create([
                'import_type' => 'standings_fbref_scrape',
                'status' => 'fallito',
                'details' => $errorMessage,
                'original_file_name' => "FBRef Standings {$leagueName} {$seasonDisplay}"
                ]);
            return Command::FAILURE;
        }
        
        $standings = $scrapedData['standings'] ?? [];
        if (empty($standings)) {
            $this->warn("Nessun dato di classifica trovato per lega {$leagueName}, stagione {$seasonDisplay} da URL: {$url}.");
            ImportLog::create([
                'import_type' => 'standings_fbref_scrape',
                'status' => 'parziale',
                'details' => "Nessun dato di classifica trovato.",
                'original_file_name' => "FBRef Standings {$leagueName} {$seasonDisplay}"
                ]);
            return Command::SUCCESS;
        }
        
        foreach ($standings as $rankData) {
            $teamNameFromScrape = $rankData['team'] ?? null;
            if (!$teamNameFromScrape) {
                Log::warning("TeamsScrapeStandings: Dati classifica incompleti (nome squadra 'team' mancante), saltato: " . json_encode($rankData));
                $failedRecords++;
                continue;
            }
            
            $team = null;
            $searchStrategy = '';
            
            // Normalizza il nome della squadra raschiata usando la funzione pulita
            $cleanedScrapedTeamName = $this->cleanTeamName($teamNameFromScrape);
            
            // Ricerca la squadra nel DB usando short_name pulito o name pulito con LIKE
            $team = Team::where(DB::raw('REPLACE(LOWER(short_name), " ", "")'), 'LIKE', '%' . $cleanedScrapedTeamName . '%')
            ->orWhere(DB::raw('REPLACE(LOWER(name), " ", "")'), 'LIKE', '%' . $cleanedScrapedTeamName . '%')
            ->first();
            
            if ($team) {
                $searchStrategy = 'Cleaned ShortName/FullName LIKE Match';
            }
            
            Log::debug("TeamsScrapeStandings: Ricerca team '{$teamNameFromScrape}' (pulito: {$cleanedScrapedTeamName}) in DB. Strategia: {$searchStrategy}. Risultato finale: " . ($team ? $team->name : 'Nessuno'));
            
            if (!$team) {
                Log::warning("TeamsScrapeStandings: Squadra '{$teamNameFromScrape}' non trovata nel DB locale per salvare classifica storica (dopo tutti i tentativi di ricerca). Saltata.");
                $failedRecords++;
                continue;
            }
            
            try {
                // Parsing dei valori numerici (resta invariato, era già corretto)
                $parsedPosition = $this->parseInt($rankData['rank'] ?? null);
                $parsedPoints = $this->parseInt($rankData['points'] ?? null);
                $parsedGames = $this->parseInt($rankData['games'] ?? null);
                $parsedWins = $this->parseInt($rankData['wins'] ?? null);
                $parsedDraws = $this->parseInt($rankData['ties'] ?? null);
                $parsedLosses = $this->parseInt($rankData['losses'] ?? null);
                $parsedGoalsFor = $this->parseInt($rankData['goals_for'] ?? null);
                $parsedGoalsAgainst = $this->parseInt($rankData['goals_against'] ?? null);
                $parsedGoalDiff = $this->parseInt($rankData['goal_diff'] ?? null);
                
                // Rimosse le righe di debug per i valori raw e convertiti
                
                TeamHistoricalStanding::updateOrCreate(
                    [
                        'team_id' => $team->id,
                        'season_year' => $seasonYear,
                        'league_name' => $leagueName,
                    ],
                    [
                        'position' => $parsedPosition,
                        'played_games' => $parsedGames,
                        'won' => $parsedWins,
                        'draw' => $parsedDraws,
                        'lost' => $parsedLosses,
                        'points' => $parsedPoints,
                        'goals_for' => $parsedGoalsFor,
                        'goals_against' => $parsedGoalsAgainst,
                        'goal_difference' => $parsedGoalDiff,
                        'data_source' => 'fbref_scrape',
                    ]
                    );
                $recordsSaved++;
            } catch (\Exception $e) {
                Log::error("TeamsScrapeStandings: Errore nel salvare classifica storica per {$teamNameFromScrape} (Stagione: {$seasonYear}, Lega: {$leagueName}): " . $e->getMessage());
                $failedRecords++;
            }
        }
        
        $duration = microtime(true) - $startTime;
        $summary = "Scraping classifica completato per {$leagueName} {$seasonDisplay} in " . round($duration, 2) . " secondi. ";
        $summary .= "Record salvati: {$recordsSaved}. Fallimenti: {$failedRecords}.";
        
        $this->info($summary);
        Log::info($summary);
        
        ImportLog::create([
            'import_type' => 'standings_fbref_scrape',
            'status' => ($failedRecords === 0) ? 'successo' : 'parziale',
            'details' => $summary,
            'rows_processed' => count($standings),
            'rows_created' => $recordsSaved,
            'rows_failed' => $failedRecords,
            'original_file_name' => "FBRef Standings {$leagueName} {$seasonDisplay}"
            ]);
        
        return Command::SUCCESS;
    }
    
    /**
     * Pulisce e normalizza un nome di squadra per il confronto, rendendolo minuscolo e senza spazi.
     * Deve contenere solo lettere da a-z e numeri.
     *
     * @param string $name
     * @return string
     */
    private function cleanTeamName(string $name): string
    {
        // Rimuovi caratteri non alfanumerici (tranne spazi) e poi tutti gli spazi
        $cleaned = preg_replace('/[^a-zA-Z0-9\s]/', '', $name);
        $cleaned = str_replace(' ', '', $cleaned);
        $cleaned = Str::lower($cleaned);
        return $cleaned;
    }
    
    /**
     * Helper function to parse string to integer.
     * Rimosso i log di debug interni.
     *
     * @param string|null $value
     * @param string $fieldName Nome del campo per il debug (non più usato internamente).
     * @return int|null
     */
    private function parseInt(?string $value, string $fieldName = ''): ?int
    {
        if (is_null($value) || trim($value) === '') {
            return null;
        }
        
        $value = str_replace(['.', ','], '', $value);
        $value = preg_replace('/[^0-9-]/', '', $value);
        
        if (!is_numeric($value) || $value === '') {
            return null;
        }
        
        return (int) $value;
    }
}