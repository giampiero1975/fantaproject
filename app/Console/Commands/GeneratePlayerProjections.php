<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Player;
use App\Models\UserLeagueProfile;
use App\Models\ImportLog;
use App\Models\PlayerProjectionSeason; // <-- Importa il nuovo modello
use App\Services\ProjectionEngineService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GeneratePlayerProjections extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'players:generate-projections
                            {--target_season_year= : Anno di inizio della stagione di proiezione (es. 2025 per 2025-26)}
                            {--player_id= : ID specifico di un giocatore (dal DB) da processare}
                            {--role= : Ruolo specifico da processare (P, D, C, A)}
                            {--force : Forza la rigenerazione delle proiezioni anche se esistenti per la stagione target}';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Genera le proiezioni finali per i giocatori e le salva nella tabella `player_projections_season`.';
    
    protected ProjectionEngineService $projectionEngine;
    protected ?UserLeagueProfile $leagueProfile;
    
    /**
     * Create a new command instance.
     *
     * @param ProjectionEngineService $projectionEngine
     * @return void
     */
    public function __construct(ProjectionEngineService $projectionEngine)
    {
        parent::__construct();
        $this->projectionEngine = $projectionEngine;
        $this->leagueProfile = UserLeagueProfile::first();
    }
    
    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->leagueProfile = UserLeagueProfile::first();
        
        if (!$this->leagueProfile) {
            $this->error("Profilo Lega non trovato. Crea un profilo lega prima di generare le proiezioni.");
            Log::error(self::class . ": Profilo Lega non trovato. Impossibile procedere.");
            return Command::FAILURE;
        }
        
        $targetSeasonYear = (int)$this->option('target_season_year');
        if (empty($targetSeasonYear)) {
            $this->error("L'anno della stagione di proiezione (--target_season_year) è obbligatorio.");
            return Command::FAILURE;
        }
        
        $forceRegeneration = $this->option('force');
        
        $this->info("Avvio Generazione Proiezioni Finali per la stagione {$targetSeasonYear}-" . ($targetSeasonYear + 1) . " usando il profilo lega: '{$this->leagueProfile->name}'");
        if ($forceRegeneration) {
            $this->warn("Modalità FORCED: Tutte le proiezioni esistenti per la stagione {$targetSeasonYear}-" . ($targetSeasonYear + 1) . " verranno sovrascritte.");
        } else {
            $this->info("Modalità standard: Le proiezioni esistenti e valide per la stagione {$targetSeasonYear}-" . ($targetSeasonYear + 1) . " non verranno sovrascritte.");
        }
        Log::info(self::class . ": Avvio generazione proiezioni (Target Season: {$targetSeasonYear}, Force: " . ($forceRegeneration ? 'true' : 'false') . ").");
        
        $startTime = microtime(true);
        $processedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;
        
        $query = Player::query()
        ->whereNotNull('role')
        ->whereIn('role', ['P', 'D', 'C', 'A']);
        
        if ($this->option('player_id')) {
            $query->where('id', $this->option('player_id'));
            $this->info("Filtraggio per giocatore con ID (DB): " . $this->option('player_id'));
        }
        if ($this->option('role')) {
            $query->where('role', strtoupper($this->option('role')));
            $this->info("Filtraggio per ruolo: " . strtoupper($this->option('role')));
        }
        
        $players = $query->get();
        
        if ($players->isEmpty()) {
            $this->warn("Nessun giocatore trovato con i criteri specificati da processare.");
            return Command::SUCCESS;
        }
        
        $this->info("Trovati " . $players->count() . " giocatori da processare.");
        $bar = $this->output->createProgressBar($players->count());
        $bar->start();
        
        foreach ($players as $player) {
            $bar->advance();
            
            // Verifica se esiste già una proiezione per la stagione target
            $existingProjection = PlayerProjectionSeason::where('player_fanta_platform_id', $player->fanta_platform_id)
            ->where('season_start_year', $targetSeasonYear)
            ->first();
            
            if (!$forceRegeneration && $existingProjection)
            {
                $this->getOutput()->newLine();
                $this->info("Saltato {$player->name} (ID: {$player->id}): Proiezione già presente per la stagione {$targetSeasonYear}-" . ($targetSeasonYear + 1) . " e --force non specificato.");
                Log::info(self::class . ": Saltato player ID {$player->id} - Proiezione esistente per stagione {$targetSeasonYear}.");
                $skippedCount++;
                continue;
            }
            
            try {
                $projections = $this->projectionEngine->generatePlayerProjection($player, $this->leagueProfile);
                
                if (empty($projections)) {
                    $this->getOutput()->newLine();
                    $this->warn("Il motore di proiezioni non ha restituito dati per {$player->name} (ID: {$player->id}).");
                    Log::warning(self::class . ": Proiezioni vuote per player ID {$player->id}.");
                    $failedCount++;
                    continue;
                }
                
                // Prepara i dati per la nuova tabella player_projections_season
                $projectionData = [
                    'player_fanta_platform_id' => $player->fanta_platform_id,
                    'season_start_year'      => $targetSeasonYear,
                    'avg_rating_proj'        => $projections['mv_proj_per_game'] ?? null,
                    'fanta_mv_proj'          => $projections['fanta_media_proj_per_game'] ?? null,
                    'games_played_proj'      => $projections['presenze_proj'] ?? null,
                    'total_fanta_points_proj' => $projections['total_fantasy_points_proj'] ?? null,
                    // Aggiungi tutti gli altri campi di proiezione dettagliati qui
                    'goals_scored_proj'      => $projections['seasonal_totals_proj']['goals_scored_proj'] ?? null,
                    'assists_proj'           => $projections['seasonal_totals_proj']['assists_proj'] ?? null,
                    'yellow_cards_proj'      => $projections['seasonal_totals_proj']['yellow_cards_proj'] ?? null,
                    'red_cards_proj'         => $projections['seasonal_totals_proj']['red_cards_proj'] ?? null,
                    'own_goals_proj'         => $projections['seasonal_totals_proj']['own_goals_proj'] ?? null,
                    'penalties_taken_proj'   => $projections['seasonal_totals_proj']['penalties_taken_proj'] ?? null,
                    'penalties_scored_proj'  => $projections['seasonal_totals_proj']['penalties_scored_proj'] ?? null,
                    'penalties_missed_proj'  => $projections['seasonal_totals_proj']['penalties_missed_proj'] ?? null,
                    'goals_conceded_proj'    => $projections['seasonal_totals_proj']['goals_conceded_proj'] ?? null,
                    'penalties_saved_proj'   => $projections['seasonal_totals_proj']['penalties_saved_proj'] ?? null,
                ];
                
                // Salva o aggiorna nella nuova tabella delle proiezioni
                PlayerProjectionSeason::updateOrCreate(
                    ['player_fanta_platform_id' => $player->fanta_platform_id, 'season_start_year' => $targetSeasonYear],
                    $projectionData
                    );
                
                $processedCount++;
                
            } catch (\Exception $e) {
                $this->getOutput()->newLine();
                $this->error("Errore durante la generazione e il salvataggio della proiezione per {$player->name} (ID: {$player->id}): " . $e->getMessage());
                Log::error(self::class . ": Eccezione per player ID {$player->id}. Msg: {$e->getMessage()}", [
                    'trace' => Str::limit($e->getTraceAsString(), 1000)
                ]);
                $failedCount++;
                continue;
            }
        }
        $bar->finish();
        
        $duration = microtime(true) - $startTime;
        $summary = "Generazione proiezioni completata in " . round($duration, 2) . " sec. " .
            "Giocatori processati con successo: {$processedCount}. " .
            "Giocatori saltati: {$skippedCount}. " .
            "Fallimenti: {$failedCount}.";
        
        $this->info("\n" . $summary);
        Log::info(self::class . ": " . $summary);
        
        ImportLog::create([
            'original_file_name' => 'Generazione Proiezioni Finali per Stagione ' . $targetSeasonYear,
            'import_type' => 'generate_projections',
            'status' => $failedCount > 0 ? ($processedCount > 0 || $skippedCount > 0 ? 'parziale' : 'fallito') : 'successo',
            'details' => $summary,
            'rows_processed' => $processedCount + $failedCount + $skippedCount,
            'rows_updated' => $processedCount,
            'rows_failed' => $failedCount,
        ]);
        
        return Command::SUCCESS;
    }
}