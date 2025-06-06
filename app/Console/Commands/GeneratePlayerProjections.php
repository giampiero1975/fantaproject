<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Player;
use App\Models\UserLeagueProfile;
use App\Services\ProjectionEngineService;
use App\Models\ImportLog;
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
                            {--player_id= : ID specifico di un giocatore (dal DB) da processare}
                            {--role= : Ruolo specifico da processare (P, D, C, A)}
                            {--force : Forza la rigenerazione delle proiezioni anche se esistenti}';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Genera le proiezioni finali per i giocatori e le salva nella tabella `players`.';
    
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
        // Carica il profilo lega di default qui.
        // È cruciale che questo carichi un'istanza di modello e non solo dati raw.
        $this->leagueProfile = UserLeagueProfile::first();
    }
    
    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Ricontrolla il profilo lega qui per sicurezza,
        // ma dovrebbe già essere stato caricato nel costruttore.
        // Se si preferisce, si può anche ricaricarlo:
        // $this->leagueProfile = UserLeagueProfile::first();
        
        if (!$this->leagueProfile) {
            $this->error("Profilo Lega non trovato. Crea un profilo lega prima di generare le proiezioni.");
            Log::error(self::class . ": Profilo Lega non trovato. Impossibile procedere.");
            return Command::FAILURE;
        }
        
        $forceRegeneration = $this->option('force');
        
        $this->info("Avvio Generazione Proiezioni Finali usando il profilo lega: '{$this->leagueProfile->name}'");
        if ($forceRegeneration) {
            $this->warn("Modalità FORCED: Tutte le proiezioni esistenti verranno sovrascritte.");
        } else {
            $this->info("Modalità standard: Le proiezioni esistenti e valide non verranno sovrascritte.");
        }
        Log::info(self::class . ": Avvio generazione proiezioni (Force: " . ($forceRegeneration ? 'true' : 'false') . ").");
        
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
            
            if (!$forceRegeneration &&
                $player->avg_rating_proj !== null &&
                $player->fanta_mv_proj !== null &&
                $player->games_played_proj !== null &&
                $player->total_fanta_points_proj !== null)
            {
                $this->getOutput()->newLine();
                $this->info("Saltato {$player->name} (ID: {$player->id}): Proiezioni già presenti e --force non specificato.");
                Log::info(self::class . ": Saltato player ID {$player->id} - Proiezioni esistenti.");
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
                
                // Aggiorna solo i campi di proiezione specifici
                $player->update([
                    'avg_rating_proj'       => $projections['mv_proj_per_game'] ?? null, // Aggiunto ?? null
                    'fanta_mv_proj'         => $projections['fanta_media_proj_per_game'] ?? null, // Aggiunto ?? null
                    'games_played_proj'     => $projections['presenze_proj'] ?? null, // Aggiunto ?? null
                    'total_fanta_points_proj' => $projections['total_fantasy_points_proj'] ?? null, // Aggiunto ?? null
                ]);
                $processedCount++;
                
            } catch (\Exception $e) {
                $this->getOutput()->newLine();
                $this->error("Errore durante la generazione della proiezione per {$player->name} (ID: {$player->id}): " . $e->getMessage());
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
            'original_file_name' => 'Generazione Proiezioni Finali',
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