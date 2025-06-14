<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Team;
use App\Models\Player;
use Illuminate\Support\Facades\Log;

class PlayersSyncFromActiveTeams extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'players:sync-from-active-teams';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sincronizza i dati dei giocatori (es. nome squadra) con i dati delle squadre attive a cui appartengono.';
    
    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Avvio della sincronizzazione dei giocatori con le squadre attive...');
        
        // --- INIZIO BLOCCO CORRETTO ---
        // Prendi tutte le squadre attualmente attive basandosi sulla colonna 'serie_a_team'.
        $activeTeams = Team::where('serie_a_team', 1)->get();
        // --- FINE BLOCCO CORRETTO ---
        
        if ($activeTeams->isEmpty()) {
            $this->warn('Nessuna squadra attiva trovata (serie_a_team = 1). Nessuna sincronizzazione da eseguire.');
            return Command::SUCCESS;
        }
        
        $this->line('Trovate ' . $activeTeams->count() . ' squadre attive.');
        
        $updatedPlayersCount = 0;
        
        foreach ($activeTeams as $team) {
            $this->line("> Sincronizzazione giocatori per la squadra: {$team->name} (ID: {$team->id})");
            
            // Prendi tutti i giocatori per la squadra corrente
            $players = Player::where('team_id', $team->id)->get();
            
            foreach ($players as $player) {
                $needsSave = false;
                
                // Confronta il team_name del giocatore con lo short_name della squadra.
                // Aggiorna solo se sono diversi.
                if ($player->team_name !== $team->short_name) {
                    // Usa il NOME BREVE (short_name) della squadra.
                    $player->team_name = $team->short_name;
                    $needsSave = true;
                    
                    Log::info("Aggiornamento team_name per il giocatore '{$player->name}' a '{$team->short_name}'.");
                }
                
                
                if ($needsSave) {
                    $player->save();
                    $updatedPlayersCount++;
                }
            }
        }
        
        $this->info("\nSincronizzazione completata.");
        $this->info("{$updatedPlayersCount} giocatori sono stati aggiornati.");
        
        return Command::SUCCESS;
    }
}