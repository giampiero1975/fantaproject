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
    protected $description = 'Processes raw FBRef stats into the main historical stats table.';
    
    public function handle()
    {
        $seasonInput = $this->option('season');
        if (!$seasonInput || !is_numeric($seasonInput)) {
            $this->error("Errore: Specificare la stagione con --season=YYYY");
            return 1;
        }
        
        $this->info("Avvio processamento per stagione {$seasonInput}...");
        
        $fbrefStats = PlayerFbrefStat::where('season_year', $seasonInput)->get();
        
        if ($fbrefStats->isEmpty()) {
            $this->warn("Nessun dato grezzo da FBRef trovato per la stagione {$seasonInput}.");
            return 0;
        }
        
        $bar = $this->output->createProgressBar($fbrefStats->count());
        $bar->start();
        
        foreach ($fbrefStats as $fbrefStat) {
            $player = Player::find($fbrefStat->player_id);
            if (!$player) {
                $bar->advance();
                continue;
            }
            
            $team = Team::find($fbrefStat->team_id);
            $roleData = json_decode($fbrefStat->data, true);
            $roleString = $roleData['Ruolo'] ?? 'X';
            
            $roleMap = ['Por' => 'P', 'Dif' => 'D', 'Cen' => 'C', 'Att' => 'A'];
            $firstRole = trim(explode(',', $roleString)[0]);
            $normalizedRole = $roleMap[$firstRole] ?? 'X';
            
            HistoricalPlayerStat::updateOrCreate(
                [
                    'player_id'   => $player->id,
                    'season_year' => $seasonInput,
                ],
                [
                    'league_name'              => $fbrefStat->league_name,
                    'player_fanta_platform_id' => $player->fanta_platform_id,
                    'team_id'                  => $team->id ?? null,
                    'team_name_for_season'     => $team->short_name ?? $team->name ?? 'N/D', // MODIFICA QUI
                    'player_name'              => $player->name,
                    'role_for_season'          => $normalizedRole,
                    'games_played'             => (int) ($roleData['PG'] ?? 0),
                    'goals_scored'             => (int) ($roleData['Reti'] ?? 0),
                    'assists'                  => (int) ($roleData['Assist'] ?? 0),
                ]
                );
            
            $bar->advance();
        }
        
        $bar->finish();
        $this->info("\nProcessamento completato.");
        return 0;
    }
}