<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\PlayerFbrefStat;
use App\Models\HistoricalPlayerStat;
use App\Models\Player;
use App\Models\Team;
use App\Models\ImportLog;
use App\Services\FantasyPointCalculatorService; // <-- Importa il servizio
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

class ProcessFbrefStatsToHistorical extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stats:process-fbref-to-historical
                            {--season= : Anno di inizio stagione da processare (es. 2023 per 2023-24). Se omesso, processa tutte le stagioni in player_fbref_stats.}
                            {--player_id= : ID del giocatore specifico (dalla tabella players) da processare.}
                            {--overwrite : Sovrascrive i record esistenti in historical_player_stats per la stessa chiave (player, season, team, league).}';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Processa i dati grezzi da player_fbref_stats e li salva/aggiorna in historical_player_stats.';
    
    protected array $leagueConversionFactors;
    protected array $defaultStatsPerRole; // Per stimare MV
    protected FantasyPointCalculatorService $fantasyPointCalculator; // Inietta il servizio
    
    public function __construct(FantasyPointCalculatorService $fantasyPointCalculator) // Inietta nel costruttore
    {
        parent::__construct();
        $this->leagueConversionFactors = Config::get('projection_settings.player_stats_league_conversion_factors', []);
        $this->defaultStatsPerRole = Config::get('projection_settings.default_stats_per_role', []);
        $this->fantasyPointCalculator = $fantasyPointCalculator;
        
        if (empty($this->leagueConversionFactors)) {
            Log::warning(self::class . ": 'player_stats_league_conversion_factors' non configurati o vuoti in config/projection_settings.php. Le statistiche non verranno convertite.");
        }
        if (empty($this->defaultStatsPerRole)) {
            Log::warning(self::class . ": 'default_stats_per_role' non configurati o vuoti in config/projection_settings.php. La stima della MV sarà meno precisa.");
        }
    }
    
    public function handle()
    {
        $this->info("Avvio processamento dati FBRef grezzi verso storico elaborato...");
        Log::info(self::class . ": Avvio processamento.");
        
        $startTime = microtime(true);
        $processedRecords = 0;
        $createdRecords = 0;
        $updatedRecords = 0;
        $skippedRecords = 0;
        $errorCount = 0;
        
        $query = PlayerFbrefStat::with(['player', 'team']);
        
        if ($this->option('season')) {
            $query->where('season_year', $this->option('season'));
            $this->info("Filtraggio per stagione: " . $this->option('season'));
        }
        if ($this->option('player_id')) {
            $query->where('player_id', $this->option('player_id'));
            $this->info("Filtraggio per player_id: " . $this->option('player_id'));
        }
        
        $fbrefStats = $query->get();
        
        if ($fbrefStats->isEmpty()) {
            $this->warn("Nessun record trovato in player_fbref_stats da processare con i criteri forniti.");
            Log::warning(self::class . ": Nessun record da processare.");
            return Command::SUCCESS;
        }
        
        $this->info("Trovati " . $fbrefStats->count() . " record da FBRef da processare.");
        $bar = $this->output->createProgressBar($fbrefStats->count());
        $bar->start();
        
        foreach ($fbrefStats as $fbrefStat) {
            $bar->advance();
            $processedRecords++;
            
            if (!$fbrefStat->player) {
                $this->getOutput()->newLine();
                $this->warn("Saltato record FBRef ID {$fbrefStat->id}: Player associato non trovato (player_id: {$fbrefStat->player_id}).");
                Log::warning(self::class . ": Saltato record FBRef ID {$fbrefStat->id} - Player mancante.", ['fbref_stat_id' => $fbrefStat->id, 'player_id' => $fbrefStat->player_id]);
                $skippedRecords++;
                continue;
            }
            if (!$fbrefStat->team) {
                $this->getOutput()->newLine();
                $this->warn("Saltato record FBRef ID {$fbrefStat->id} per Player {$fbrefStat->player->name}: Team associato non trovato (team_id: {$fbrefStat->team_id}).");
                Log::warning(self::class . ": Saltato record FBRef ID {$fbrefStat->id} - Team mancante.", ['fbref_stat_id' => $fbrefStat->id, 'team_id' => $fbrefStat->team_id]);
                $skippedRecords++;
                continue;
            }
            if (empty($fbrefStat->player->fanta_platform_id)) {
                $this->getOutput()->newLine();
                $this->warn("Saltato record FBRef ID {$fbrefStat->id} per Player {$fbrefStat->player->name}: fanta_platform_id mancante nel record Player.");
                Log::warning(self::class . ": Saltato record FBRef ID {$fbrefStat->id} - fanta_platform_id mancante.", ['player_id' => $fbrefStat->player->id]);
                $skippedRecords++;
                continue;
            }
            
            $conversionFactors = $this->leagueConversionFactors[$fbrefStat->league_name] ??
            ($this->leagueConversionFactors['default'] ?? []);
            
            // --- INIZIO LOGICA STIMA MV/FM ---
            $roleKey = strtoupper($fbrefStat->player->role ?? 'C');
            $defaultRoleStats = $this->defaultStatsPerRole[$roleKey] ?? $this->defaultStatsPerRole['C'] ?? [];
            
            $estimatedAvgRating = (float)($defaultRoleStats['mv'] ?? 6.0); // MV base per ruolo
            $estimatedFantaAvgRating = 0.0;
            
            // Applica il fattore di conversione lega alla MV stimata
            $estimatedAvgRating *= ($conversionFactors['avg_rating'] ?? 1.0);
            
            // Prepara le statistiche "per partita" per calcolare la FantaMedia storica
            // Usa i valori grezzi da FBRef, ma convertiti per la lega
            $statsForFantaCalc = [
                'mv'              => $estimatedAvgRating, // Usa la MV stimata
                'gol_fatti'       => (float)($fbrefStat->goals ?? 0) * ($conversionFactors['goals_scored'] ?? 1.0),
                'assist'          => (float)($fbrefStat->assists ?? 0) * ($conversionFactors['assists'] ?? 1.0),
                'ammonizioni'     => (float)($fbrefStat->yellow_cards ?? 0),
                'espulsioni'      => (float)($fbrefStat->red_cards ?? 0),
                'autogol'         => (float)($fbrefStat->misc_own_goals ?? 0),
                'rigori_segnati'  => (float)($fbrefStat->penalties_made ?? 0),
                'rigori_sbagliati' => (float)(($fbrefStat->penalties_attempted ?? 0) - ($fbrefStat->penalties_made ?? 0)),
                'rigori_parati'   => ($fbrefStat->player->role === 'P') ? (float)($fbrefStat->gk_penalties_saved ?? 0) : 0.0,
                'gol_subiti'      => ($fbrefStat->player->role === 'P') ? (float)($fbrefStat->gk_goals_conceded ?? 0) : 0.0,
                // Per i difensori, se gol_subiti è 0, potremmo stimarlo qui basandoci sulla media lega/tier della squadra di quella stagione,
                // ma per ora, lasciamo che il ProjectionEngineService lo stimi se è necessario per la proiezione finale.
            ];
            
            // Per calcolare la FantaMedia storica per il record historical_player_stat, abbiamo bisogno delle regole di scoring.
            // Poiché non abbiamo un UserLeagueProfile qui, useremo delle regole di scoring di default o una media approssimativa.
            // Oppure potremmo usare una logica più sofisticata che considera "MV storica" come (total_fanta_points / games_played).
            // Per semplicità, inizialmente potremmo stimarla come MV * 1.1 o simili.
            // Il FantasyPointCalculatorService richiede scoringRules. Potremmo usare un set di regole base.
            // Per ora, useremo una stima semplificata basata sulla MV stimata, come richiesto implicitamente.
            $estimatedFantaAvgRating = $this->estimateFantaAvgRating($statsForFantaCalc, $fbrefStat->player->role);
            
            // --- FINE LOGICA STIMA MV/FM ---
            
            $dataToStore = [
                'team_name_for_season'     => $fbrefStat->team->name,
                'role_for_season'          => $fbrefStat->player->role,
                'games_played'             => $fbrefStat->games_played ?? 0,
                'avg_rating'               => round($estimatedAvgRating, 2), // Ora popoliamo questo!
                'fanta_avg_rating'         => round($estimatedFantaAvgRating, 2), // Ora popoliamo questo!
                'goals_scored'             => (float)($statsForFantaCalc['gol_fatti'] ?? 0),
                'assists'                  => (float)($statsForFantaCalc['assist'] ?? 0),
                'yellow_cards'             => (float)($statsForFantaCalc['ammonizioni'] ?? 0),
                'red_cards'                => (float)($statsForFantaCalc['espulsioni'] ?? 0),
                'own_goals'                => (float)($statsForFantaCalc['autogol'] ?? 0),
                'penalties_taken'          => (float)($fbrefStat->penalties_attempted ?? 0), // Use original taken
                'penalties_scored'         => (float)($statsForFantaCalc['rigori_segnati'] ?? 0),
                'penalties_missed'         => (float)($statsForFantaCalc['rigori_sbagliati'] ?? 0),
                'goals_conceded'           => (float)($statsForFantaCalc['gol_subiti'] ?? 0),
                'penalties_saved'          => (float)($statsForFantaCalc['rigori_parati'] ?? 0),
                'mantra_role_for_season'   => $fbrefStat->player->mantra_role ?? null,
            ];
            
            try {
                $uniqueKeys = [
                    'player_fanta_platform_id' => $fbrefStat->player->fanta_platform_id,
                    'season_year'              => $fbrefStat->season_year . '-' . substr($fbrefStat->season_year + 1, 2),
                    'team_id'                  => $fbrefStat->team_id,
                    'league_name'              => $fbrefStat->league_name,
                ];
                
                $historicalStat = null;
                if ($this->option('overwrite')) {
                    $historicalStat = HistoricalPlayerStat::updateOrCreate($uniqueKeys, $dataToStore);
                } else {
                    $historicalStat = HistoricalPlayerStat::firstOrCreate($uniqueKeys, $dataToStore);
                }
                
                if ($historicalStat->wasRecentlyCreated) {
                    $createdRecords++;
                } elseif ($historicalStat && $this->option('overwrite') && $historicalStat->wasChanged()) {
                    $updatedRecords++;
                }
            } catch (\Exception $e) {
                $this->getOutput()->newLine();
                $this->error("Errore durante il salvataggio dello storico per Player {$fbrefStat->player->name} (FBRef ID {$fbrefStat->id}): " . $e->getMessage());
                Log::error(self::class . ": Errore DB per FBRef ID {$fbrefStat->id} - Player: {$fbrefStat->player->name}. Msg: {$e->getMessage()}", ['data' => $dataToStore]);
                $errorCount++;
                continue;
            }
        }
        
        $bar->finish();
        $duration = microtime(true) - $startTime;
        $summary = "Processamento dati FBRef completato in " . round($duration, 2) . " secondi. " .
            "Record FBRef letti: {$fbrefStats->count()}. " .
            "Record processati per storico: {$processedRecords}. " .
            "Nuovi record storico creati: {$createdRecords}. " .
            "Record storico aggiornati (se overwrite): {$updatedRecords}. " .
            "Record saltati (player/team/fpid mancante): {$skippedRecords}. " .
            "Errori DB: {$errorCount}.";
        
        $this->info("\n" . $summary);
        Log::info(self::class . ": " . $summary);
        
        ImportLog::create([
            'original_file_name' => 'Processamento FBRef a Storico' . ($this->option('season') ? ' Stagione ' . $this->option('season') : ' Tutte'),
            'import_type' => 'fbref_processing',
            'status' => ($errorCount === 0 && $skippedRecords === 0) ? 'successo' : (($createdRecords > 0 || $updatedRecords > 0) ? 'parziale' : 'fallito'),
            'details' => $summary,
            'rows_processed' => $processedRecords,
            'rows_created' => $createdRecords,
            'rows_updated' => $updatedRecords,
            'rows_failed' => $errorCount + $skippedRecords,
        ]);
        
        return Command::SUCCESS;
    }
    
    /**
     * Stima la FantaMedia (FM) per una stagione storica basandosi sulle statistiche e sul ruolo.
     * Questo è un calcolo semplificato per lo storico, non le proiezioni future complete.
     *
     * @param array $stats Statistiche per partita (già convertite).
     * @param string $playerRole Ruolo del giocatore.
     * @return float FantaMedia stimata.
     */
    private function estimateFantaAvgRating(array $stats, string $playerRole): float
    {
        $fantaPoints = $stats['mv']; // Partiamo dalla MV stimata
        
        // Aggiungiamo i bonus/malus più comuni, come in una regola base di fantacalcio.
        // Questi valori sono un'approssimazione e possono essere calibrati.
        $fantaPoints += ($stats['gol_fatti'] ?? 0) * 3; // Gol (standard)
        $fantaPoints += ($stats['assist'] ?? 0) * 1;  // Assist (standard)
        $fantaPoints += ($stats['ammonizioni'] ?? 0) * -0.5; // Ammonizione
        $fantaPoints += ($stats['espulsioni'] ?? 0) * -1; // Espulsione
        $fantaPoints += ($stats['autogol'] ?? 0) * -2; // Autogol
        $fantaPoints += ($stats['rigori_segnati'] ?? 0) * 3; // Rigore segnato
        $fantaPoints += ($stats['rigori_sbagliati'] ?? 0) * -3; // Rigore sbagliato
        
        if (strtoupper($playerRole) === 'P') {
            $fantaPoints += ($stats['rigori_parati'] ?? 0) * 3; // Rigore parato (solo portieri)
            $fantaPoints += ($stats['gol_subiti'] ?? 0) * -1; // Gol subito (solo portieri)
            // Clean sheet bonus per portieri, stimato come una probabilità base
            $fantaPoints += ($stats['mv'] >= 6.0 && ($stats['gol_subiti'] ?? 0) == 0) ? 1.0 : 0.0; // Bonus clean sheet se MV>=6 e 0 gol subiti
        } elseif (strtoupper($playerRole) === 'D') {
            // Clean sheet bonus per difensori, stimato come una probabilità base
            // Per i difensori, assumiamo che il CS sia legato alla squadra.
            // Se FBRef non ci dà "clean sheets" per difensori, e "gol_subiti" è 0,
            // possiamo dare un piccolo bonus fisso o basarlo sulla media gol subiti della squadra.
            // Per ora, una stima semplificata:
            // if ($stats['mv'] >= 6.0 && ($stats['gol_subiti'] ?? 0) == 0) { // Questo non è accurato per difensori senza gol_subiti
            //     $fantaPoints += 0.5;
            // }
            // Meglio non stimare il Clean Sheet per i difensori qui, dato che FBRef non traccia gol subiti individuali.
            // Lasciamo che la logica del ProjectionEngineService (che usa il tier della squadra) lo gestisca.
        }
        
        return max(4.0, round($fantaPoints, 2)); // Limita la FantaMedia minima per evitare valori troppo bassi
    }
}