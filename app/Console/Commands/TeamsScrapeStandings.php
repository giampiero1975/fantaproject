<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\LeagueStandingsScrapingService;
use App\Models\TeamHistoricalStanding;
use App\Models\Team;
use App\Models\ImportLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class TeamsScrapeStandings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    // MODIFICA 1: Aggiunta opzione --league-code
    protected $signature = 'teams:scrape-standings
                            {url : L\'URL completo della pagina della classifica su FBRef}
                            {--season= : Anno di inizio della stagione (es. 2021 per 2021-22)}
                            {--league= : Nome della lega (es. Serie A, Serie B)}
                            {--league-code= : Codice della lega (es. SA, SB), obbligatorio se si creano team}
                            {--create-missing-teams=false : Crea le squadre nel DB se non vengono trovate}';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Esegue lo scraping di una classifica storica da un URL FBRef e salva i dati nel database, creando opzionalmente i team mancanti.';
    
    protected LeagueStandingsScrapingService $scrapingService;
    
    public function __construct(LeagueStandingsScrapingService $scrapingService)
    {
        parent::__construct();
        $this->scrapingService = $scrapingService;
    }
    
    // MODIFICA 2: Metodo handle() intero e aggiornato
    public function handle(): int
    {
        $url = $this->argument('url');
        $seasonYear = $this->option('season');
        $leagueName = $this->option('league');
        $leagueCode = $this->option('league-code'); // NUOVO: Recupera il league code
        $createMissingTeams = filter_var($this->option('create-missing-teams'), FILTER_VALIDATE_BOOLEAN);
        
        if (empty($url) || empty($seasonYear) || empty($leagueName)) {
            $this->error("URL, stagione (--season) e nome della lega (--league) sono obbligatori.");
            return Command::FAILURE;
        }
        
        // NUOVO: Validazione per il league-code quando si creano i team
        if ($createMissingTeams && empty($leagueCode)) {
            $this->error("L'opzione --league-code è obbligatoria quando --create-missing-teams è impostato a true.");
            return Command::FAILURE;
        }
        
        $seasonDisplay = $seasonYear . '-' . substr($seasonYear + 1, 2);
        $this->info("Avvio scraping classifica per lega {$leagueName} ({$leagueCode}), stagione {$seasonDisplay} da URL: {$url}");
        if ($createMissingTeams) {
            $this->info("Modalità creazione team mancanti: ATTIVA.");
        }
        
        $startTime = microtime(true);
        $recordsSaved = 0;
        $failedRecords = 0;
        $createdTeamCount = 0;
        
        $this->scrapingService->setTargetUrl($url);
        $scrapedData = $this->scrapingService->scrapeStandings();
        
        if (isset($scrapedData['error'])) {
            // ... gestione errore scraping (invariata)
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
            // ... gestione dati vuoti (invariata)
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
            
            $cleanedScrapedTeamName = $this->cleanTeamName($teamNameFromScrape);
            
            $team = Team::where(DB::raw('REPLACE(LOWER(short_name), " ", "")'), 'LIKE', '%' . $cleanedScrapedTeamName . '%')
            ->orWhere(DB::raw('REPLACE(LOWER(name), " ", "")'), 'LIKE', '%' . $cleanedScrapedTeamName . '%')
            ->first();
            
            if (!$team && $createMissingTeams) {
                $this->line("Squadra '{$teamNameFromScrape}' non trovata, la creo...");
                try {
                    // MODIFICATO: Aggiunti i nuovi campi durante la creazione
                    $team = Team::create([
                        'name'                   => $teamNameFromScrape,
                        'short_name'             => $teamNameFromScrape, // Popolato con il nome completo
                        'tla'                    => strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $teamNameFromScrape), 0, 3)), // Crea un TLA di default (es. Frosinone -> FRO)
                        'crest_url'              => null, // Non abbiamo lo stemma da FBRef
                        'serie_a_team'           => (strtoupper($leagueCode) === 'SA'),
                        'tier'                   => null,
                        'fanta_platform_id'      => null, // Non abbiamo questo ID da FBRef
                        'api_football_data_id'   => null, // Non abbiamo questo ID da FBRef
                        'league_code'            => strtoupper($leagueCode), // Popolato dal parametro
                        'season_year'            => $seasonYear, // Popolato dal parametro
                    ]);
                    $this->info("-> Creata squadra '{$team->name}' con ID: {$team->id}");
                    $createdTeamCount++;
                } catch (\Exception $e) {
                    $this->error("Errore durante la creazione della squadra '{$teamNameFromScrape}': " . $e->getMessage());
                    $failedRecords++;
                    continue;
                }
            } elseif (!$team) {
                Log::warning("TeamsScrapeStandings: Squadra '{$teamNameFromScrape}' non trovata nel DB locale. Saltata (creazione disattivata).");
                $failedRecords++;
                continue;
            }
            
            try {
                // Logica di salvataggio della classifica (invariata)
                $parsedPosition = $this->parseInt($rankData['rank'] ?? null);
                $parsedPoints = $this->parseInt($rankData['points'] ?? null);
                $parsedGames = $this->parseInt($rankData['games'] ?? null);
                $parsedWins = $this->parseInt($rankData['wins'] ?? null);
                $parsedDraws = $this->parseInt($rankData['ties'] ?? null);
                $parsedLosses = $this->parseInt($rankData['losses'] ?? null);
                $parsedGoalsFor = $this->parseInt($rankData['goals_for'] ?? null);
                $parsedGoalsAgainst = $this->parseInt($rankData['goals_against'] ?? null);
                $parsedGoalDiff = $this->parseInt($rankData['goal_diff'] ?? null);
                
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
                Log::error("TeamsScrapeStandings: Errore nel salvare classifica storica per {$teamNameFromScrape}: " . $e->getMessage());
                $failedRecords++;
            }
        }
        
        // Riepilogo finale (invariato)
        $duration = microtime(true) - $startTime;
        $summary = "Scraping classifica completato per {$leagueName} {$seasonDisplay} in " . round($duration, 2) . " secondi. ";
        $summary .= "Record salvati: {$recordsSaved}. Team creati: {$createdTeamCount}. Fallimenti/Saltati: {$failedRecords}.";
        
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
     * Pulisce e normalizza un nome di squadra per il confronto,
     * convertendo i caratteri accentati e rimuovendo gli spazi.
     *
     * @param string $name
     * @return string
     */
    private function cleanTeamName(string $name): string
    {
        // Metodo 1 (Preferito): Usa l'estensione PHP intl se disponibile
        if (function_exists('transliterator_transliterate')) {
            // Converte caratteri come 'ü' in 'u', 'é' in 'e', etc.
            $name = transliterator_transliterate('Any-Latin; Latin-ASCII;', $name);
        } else {
            // Metodo 2 (Fallback): Usa una mappa di caratteri comuni
            $accentMap = [
                'á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 'ü'=>'u', 'ñ'=>'n', 'ç'=>'c',
                'ò'=>'o', 'à'=>'a', 'è'=>'e', 'ì'=>'i', 'ù'=>'u', 'š'=>'s', 'ž'=>'z'
                // Aggiungi altri caratteri se necessario
            ];
            $name = strtr($name, $accentMap);
        }
        
        // Rimuovi spazi e converte in minuscolo
        $cleaned = str_replace(' ', '', $name);
        $cleaned = Str::lower($cleaned);
        
        // Rimuovi eventuali caratteri non alfanumerici rimanenti (più sicuro)
        $cleaned = preg_replace('/[^a-z0-9]/', '', $cleaned);
        
        return $cleaned;
    }
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