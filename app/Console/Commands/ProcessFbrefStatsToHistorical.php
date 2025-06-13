<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\PlayerFbrefStat;
use App\Models\HistoricalPlayerStat;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Support\Facades\Log;

class ProcessFbrefStatsToHistorical extends Command
{
    protected $signature = 'stats:process-fbref-to-historical {--season=}';
    protected $description = 'Processes raw FBRef stats and populates the historical_player_stats table, bypassing all roster checks.';
    
    public function handle()
    {
        $seasonInput = $this->option('season');
        if (!$seasonInput || !is_numeric($seasonInput)) {
            $this->error("Errore: Specificare la stagione con --season=YYYY (es. --season=2021)");
            return 1;
        }
        
        $this->info("Avvio processamento per stagione {$seasonInput} (Modalità Bypass Totale Attiva)");
        
        $fbrefStats = PlayerFbrefStat::where('season_year', $seasonInput)->get();
        
        if ($fbrefStats->isEmpty()) {
            $this->warn("Nessun dato grezzo da FBRef trovato per la stagione {$seasonInput}.");
            return 0;
        }
        
        $this->info("Trovati {$fbrefStats->count()} record da processare.");
        $bar = $this->output->createProgressBar($fbrefStats->count());
        $bar->start();
        
        $processedCount = 0;
        $skippedCount = 0;
        
        foreach ($fbrefStats as $fbrefStat) {
            // Unico controllo: il record grezzo deve avere un player_id associato.
            // Nessun altro controllo su 'fanta_platform_id' o 'api_football_data_id'.
            if (!$fbrefStat->player_id) {
                $skippedCount++;
                $bar->advance();
                continue;
            }
            
            $player = Player::find($fbrefStat->player_id);
            $team = Team::find($fbrefStat->team_id);
            
            // Normalizza il ruolo (es. 'Dif' -> 'D')
            $roleMap = ['Por' => 'P', 'Dif' => 'D', 'Cen' => 'C', 'Att' => 'A'];
            $firstRole = explode(',', $fbrefStat->position_fbref ?? '')[0];
            $normalizedRole = $roleMap[trim($firstRole)] ?? strtoupper(substr(trim($firstRole), 0, 1));
            
            HistoricalPlayerStat::updateOrCreate(
                [
                    'player_id'   => $fbrefStat->player_id,
                    'season_year' => $seasonInput,
                ],
                [
                    'league_name' => $fbrefStat->league_name,
                    'player_fanta_platform_id' => $player->fanta_platform_id ?? null,
                    'team_id' => $team->id ?? null,
                    'team_name_for_season' => $team->name ?? null,
                    'player_name' => $player->name ?? 'N/D',
                    'role_for_season' => $normalizedRole,
                    'games_played' => $fbrefStat->games_played,
                    'goals_scored' => $fbrefStat->goals,
                    'assists' => $fbrefStat->assists,
                    'penalties_scored' => $fbrefStat->penalties_made,
                    'penalties_taken' => $fbrefStat->penalties_attempted,
                    'yellow_cards' => $fbrefStat->yellow_cards,
                    'red_cards' => $fbrefStat->red_cards,
                ]
                );
            
            $processedCount++;
            $bar->advance();
        }
        
        $bar->finish();
        $this->info("\nProcessamento completato. Letti: {$fbrefStats->count()}, Processati: {$processedCount}, Saltati: {$skippedCount}.");
        return 0;
    }
}