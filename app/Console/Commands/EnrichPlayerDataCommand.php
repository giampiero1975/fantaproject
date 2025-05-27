<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Services\DataEnrichmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EnrichPlayerDataCommand extends Command
{
    protected $signature = 'players:enrich-data {--player_id=all} {--player_name=} {--delay=6}';
    protected $description = 'Enriches player data from Football-Data.org API';
    
    protected DataEnrichmentService $enrichmentService;
    
    public function __construct(DataEnrichmentService $enrichmentService)
    {
        parent::__construct();
        $this->enrichmentService = $enrichmentService;
    }
    
    public function handle()
    {
        $playerId = $this->option('player_id');
        $playerName = $this->option('player_name');
        $delay = (int)$this->option('delay');
        
        $query = Player::query();
        
        if ($playerName) {
            $query->where('name', 'LIKE', "%{$playerName}%");
            $this->info("Attempting to enrich player(s) with name like: {$playerName}");
        } elseif ($playerId !== 'all') {
            $query->where('id', $playerId);
            $this->info("Attempting to enrich player with DB ID: {$playerId}");
        } else {
            $this->info("Attempting to enrich all players missing date_of_birth or api_football_data_id...");
            // Solo quelli che necessitano di arricchimento
            $query->where(function ($q) {
                $q->whereNull('date_of_birth')
                ->orWhereNull('detailed_position')
                ->orWhereNull('api_football_data_id');
            });
        }
        
        $players = $query->get();
        
        if ($players->isEmpty()) {
            $this->warn("No players found to enrich based on your criteria.");
            return 0;
        }
        
        $this->info("Found {$players->count()} player(s) to process.");
        $bar = $this->output->createProgressBar($players->count());
        $bar->start();
        
        foreach ($players as $player) {
            $this->info("\nProcessing player: {$player->name} (DB ID: {$player->id})");
            if ($player->team_name == null && $player->team_id != null) { // Assicura team_name se possibile
                $player->load('team'); // Carica la relazione se non già fatto
                $player->team_name = $player->team?->name;
            }
            
            if(empty($player->team_name)) {
                $this->warn("Skipping {$player->name} due to missing team name, which is needed for API matching.");
                Log::warning("DataEnrichmentCommand: Skipping {$player->name} (ID: {$player->id}) due to missing team name.");
                $bar->advance();
                if ($players->count() > 1 && $delay > 0) {
                    sleep($delay); // Rispetta il rate limit anche se saltiamo
                }
                continue;
            }
            
            $success = $this->enrichmentService->enrichPlayerFromApi($player);
            if ($success) {
                $this->info("Successfully enriched data for: {$player->name}. Age: " . ($player->date_of_birth ? $player->date_of_birth->age : 'N/A'));
            } else {
                $this->warn("Failed to enrich data for: {$player->name}. Check logs.");
            }
            $bar->advance();
            if ($players->count() > 1 && $delay > 0) { // Non dormire se è l'ultimo o delay è 0
                if ($player !== $players->last()) sleep($delay);
            }
        }
        
        $bar->finish();
        $this->info("\nEnrichment process completed.");
        return 0;
    }
}