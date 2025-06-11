<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Player;
use App\Services\DataEnrichmentService;
use Illuminate\Support\Facades\Log;

class EnrichPlayerDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     * --- AGGIUNTA OPZIONE --all ---
     * @var string
     */
    protected $signature = 'players:enrich-data
                            {--all : Arricchisci tutti i giocatori nel database invece dei soli giocatori di Serie A attivi}
                            {--player_id= : Specifica l\'ID di un singolo giocatore da arricchire}';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Arricchisce i dati dei giocatori (es. ID API, data di nascita) usando un servizio esterno.';
    
    protected DataEnrichmentService $enrichmentService;
    
    public function __construct(DataEnrichmentService $enrichmentService)
    {
        parent::__construct();
        $this->enrichmentService = $enrichmentService;
    }
    
    public function handle()
    {
        $enrichAll = $this->option('all');
        $playerId = $this->option('player_id');
        
        if ($playerId) {
            $player = Player::find($playerId);
            if (!$player) {
                $this->error("Giocatore con ID [{$playerId}] non trovato.");
                return Command::FAILURE;
            }
            $playersToEnrich = collect([$player]);
            $this->info("Arricchimento mirato per il giocatore: {$player->name} (ID: {$playerId})");
        } else {
            $this->info($enrichAll ? "Avvio arricchimento per TUTTI i giocatori..." : "Avvio arricchimento per i soli giocatori delle squadre di Serie A attive...");
            
            $query = Player::query();
            
            // --- NUOVA LOGICA DI FILTRAGGIO ---
            if (!$enrichAll) {
                // Di default, processa solo i giocatori delle squadre di Serie A
                $query->whereHas('team', function ($q) {
                    $q->where('serie_a_team', true);
                });
                    $this->comment("Modalità: Solo giocatori di Serie A. Usa --all per processarli tutti.");
            }
            
            // In ogni caso, processa solo quelli a cui mancano dati
            $query->where(function ($q) {
                $q->whereNull('api_football_data_id')
                ->orWhereNull('date_of_birth');
            });
                
                $playersToEnrich = $query->get();
        }
        
        $total = $playersToEnrich->count();
        if ($total === 0) {
            $this->info("Nessun giocatore da arricchire secondo i criteri selezionati. Lavoro terminato.");
            return Command::SUCCESS;
        }
        
        $this->info("Trovati {$total} giocatori da arricchire. Inizio processo...");
        $progressBar = $this->output->createProgressBar($total);
        $progressBar->start();
        
        $successCount = 0;
        $failCount = 0;
        
        foreach ($playersToEnrich as $player) {
            try {
                if ($this->enrichmentService->enrichPlayerFromApi($player)) {
                    $successCount++;
                } else {
                    $failCount++;
                }
            } catch (\Exception $e) {
                $this->error("Errore critico durante l'arricchimento del giocatore ID {$player->id}: " . $e->getMessage());
                Log::error("Fallimento critico comando enrich-data", ['player_id' => $player->id, 'error' => $e->getMessage()]);
                $failCount++;
            }
            
            // Pausa per rispettare i limiti dell'API
            sleep(config('services.api_football.delay_between_requests', 6));
            $progressBar->advance();
        }
        
        $progressBar->finish();
        $this->info("\nArricchimento completato.");
        $this->info("Successi: {$successCount}, Falliti/Saltati: {$failCount}.");
        
        return Command::SUCCESS;
    }
}