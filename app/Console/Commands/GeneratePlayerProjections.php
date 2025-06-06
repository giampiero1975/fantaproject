<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Player;
use App\Models\UserLeagueProfile;
use App\Services\ProjectionEngineService;
use App\Models\ImportLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str; // <-- AGGIUNTA LA RIGA MANCANTE

class GeneratePlayerProjections extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'players:generate-projections
                            {--player_id= : ID specifico di un giocatore (dal DB) da processare}
                            {--role= : Ruolo specifico da processare (P, D, C, A)}';
    
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
        $this->leagueProfile = UserLeagueProfile::first();
    }
    
    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Carica il profilo lega di default. Assumiamo ID=1 o il primo disponibile.
        $this->leagueProfile = UserLeagueProfile::first();
        
        if (!$this->leagueProfile) {
            $this->error("Profilo Lega non trovato. Crea un profilo lega prima di generare le proiezioni.");
            Log::error(self::class . ": Profilo Lega non trovato. Impossibile procedere.");
            return Command::FAILURE;
        }
        
        $this->info("Avvio Generazione Proiezioni Finali usando il profilo lega: '{$this->leagueProfile->name}'");
        Log::info(self::class . ": Avvio generazione proiezioni.");
        
        $startTime = microtime(true);
        $processedCount = 0;
        $failedCount = 0;
        
        $query = Player::query()
        ->whereNotNull('role') // Processa solo giocatori con un ruolo definito
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
            try {
                // Chiama il "cervello" per ottenere l'array completo di proiezioni
                $projections = $this->projectionEngine->generatePlayerProjection($player, $this->leagueProfile);
                
                if (empty($projections)) {
                    $this->getOutput()->newLine();
                    $this->warn("Il motore di proiezioni non ha restituito dati per {$player->name} (ID: {$player->id}).");
                    Log::warning(self::class . ": Proiezioni vuote per player ID {$player->id}.");
                    $failedCount++;
                    continue;
                }
                
                // Ora usiamo la versione pulita, che si aspetta tutte le chiavi
                $player->update([
                    'avg_rating_proj'       => $projections['avg_rating_proj'],
                    'fanta_mv_proj'         => $projections['fanta_mv_proj'],
                    'games_played_proj'     => $projections['games_played_proj'],
                    'total_fanta_points_proj' => $projections['total_fanta_points_proj'],
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
            "Fallimenti: {$failedCount}.";
        
        $this->info("\n" . $summary);
        Log::info(self::class . ": " . $summary);
        
        ImportLog::create([
            'original_file_name' => 'Generazione Proiezioni Finali',
            'import_type' => 'generate_projections',
            'status' => $failedCount > 0 ? ($processedCount > 0 ? 'parziale' : 'fallito') : 'successo',
            'details' => $summary,
            'rows_processed' => $processedCount + $failedCount,
            'rows_updated' => $processedCount,
            'rows_failed' => $failedCount,
        ]);
        
        return Command::SUCCESS;
    }
}