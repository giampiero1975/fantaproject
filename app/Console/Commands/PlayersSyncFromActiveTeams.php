<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Player;
use App\Models\Team;
use App\Services\PlayerStatsApiService;
use Illuminate\Support\Facades\Log;

class PlayersSyncFromActiveTeams extends Command
{
    protected $signature = 'players:sync-from-active-teams {--season= : Specifica un anno di stagione da usare per tutte le squadre (es. 2024)}';
    protected $description = 'Sincronizza i giocatori delle squadre di Serie A attive in modo efficiente, aggiornando solo i dati modificati e usando una logica di matching avanzata.';
    protected PlayerStatsApiService $playerStatsApiService;
    
    public function __construct(PlayerStatsApiService $playerStatsApiService)
    {
        parent::__construct();
        $this->playerStatsApiService = $playerStatsApiService;
    }
    
    public function handle()
    {
        $this->info("Avvio sincronizzazione FINALE delle rose per le squadre di Serie A...");
        $forcedSeason = $this->option('season');
        $activeTeams = Team::where('serie_a_team', true)->whereNotNull('api_football_data_id')->get();
        
        if ($activeTeams->isEmpty()) {
            $this->warn("Nessuna squadra di Serie A attiva trovata.");
            return Command::FAILURE;
        }
        
        $this->info("Trovate {$activeTeams->count()} squadre da processare.");
        $createdCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;
        
        foreach ($activeTeams as $team) {
            $seasonToFetch = $forcedSeason ?? $team->season_year;
            $this->comment("Processo la rosa di: {$team->name} (Stagione {$seasonToFetch})");
            
            $playersFromApi = $this->playerStatsApiService->getPlayersForTeamAndSeason($team->api_football_data_id, $seasonToFetch);
            
            if (empty($playersFromApi['players'])) {
                $this->warn("   -> Nessun giocatore trovato dall'API per {$team->name}. Salto.");
                continue;
            }
            
            foreach ($playersFromApi['players'] as $apiPlayer) {
                if (empty($apiPlayer['id']) || empty($apiPlayer['name'])) {
                    $failedCount++;
                    continue;
                }
                
                try {
                    $apiPlayerId = $apiPlayer['id'];
                    $apiPlayerName = $apiPlayer['name'];
                    
                    $playerData = [
                        'name' => $apiPlayerName,
                        'role' => $this->mapPositionToRole($apiPlayer['position']),
                        'team_id' => $team->id,
                        'team_name' => $team->short_name,
                        'date_of_birth' => isset($apiPlayer['dateOfBirth']) ? \Carbon\Carbon::parse($apiPlayer['dateOfBirth'])->format('Y-m-d') : null,
                        'nationality' => $apiPlayer['nationality'] ?? null,
                        'detailed_position' => $apiPlayer['position'] ?? null,
                    ];
                    
                    $player = Player::where('api_football_data_id', $apiPlayerId)->first();
                    
                    if ($player) {
                        $player->fill($playerData);
                        if ($player->isDirty()) {
                            $this->line("   -> Trovato via API ID: {$apiPlayerName}. Dati diversi, aggiorno.");
                            $player->save();
                            $updatedCount++;
                        } else {
                            $this->line("   -> Trovato via API ID: {$apiPlayerName}. Dati identici, salto.");
                            $skippedCount++;
                        }
                    } else {
                        // Non trovato via ID, cerca per NOME ESATTO (priorità alta)
                        $player = Player::where('name', $apiPlayerName)->whereNull('api_football_data_id')->first();
                        
                        if (!$player) {
                            // Se fallisce, prova con il COGNOME (per "Reina" vs "Pepe Reina")
                            $nameParts = explode(' ', $apiPlayerName);
                            $lastName = end($nameParts);
                            $player = Player::where('name', 'LIKE', '%' . $lastName . '%')->whereNull('api_football_data_id')->first();
                        }
                        
                        if ($player) {
                            $this->line("   -> Match per NOME/COGNOME trovato: '{$player->getOriginal('name')}'. Collego ID API e aggiorno...");
                            $playerData['api_football_data_id'] = $apiPlayerId;
                            $player->fill($playerData);
                            
                            if ($player->isDirty()) {
                                $player->save();
                                $updatedCount++;
                            } else {
                                $this->line("      -> Dati identici, nessun aggiornamento necessario.");
                                $skippedCount++;
                            }
                        } else {
                            $this->line("   -> Giocatore non trovato. Creo: {$apiPlayerName}.");
                            $playerData['api_football_data_id'] = $apiPlayerId;
                            Player::create($playerData);
                            $createdCount++;
                        }
                    }
                } catch (\Exception $e) {
                    $this->error("   -> Errore sync per giocatore API ID {$apiPlayer['id']}: " . $e->getMessage());
                    Log::error("Fallimento sync giocatore", ['player_data' => $apiPlayer, 'error' => $e->getMessage()]);
                    $failedCount++;
                }
            }
            $this->info("   -> Pausa di 7 secondi...");
            sleep(7);
        }
        
        $this->info("Sincronizzazione DEFINITIVA completata. Creati: {$createdCount}, Aggiornati: {$updatedCount}, Saltati (dati uguali): {$skippedCount}, Falliti: {$failedCount}.");
        return Command::SUCCESS;
    }
    
    private function mapPositionToRole(?string $position): ?string
    {
        if ($position === null) return null;
        $normalizedPosition = strtolower(str_replace(' ', '-', $position));
        if (in_array($normalizedPosition, ['goalkeeper'])) return 'P';
        if (in_array($normalizedPosition, ['defender', 'defence', 'right-back', 'left-back', 'centre-back'])) return 'D';
        if (in_array($normalizedPosition, ['midfielder', 'midfield', 'defensive-midfield', 'central-midfield', 'attacking-midfield', 'right-midfield', 'left-midfield'])) return 'C';
        if (in_array($normalizedPosition, ['forward', 'offence', 'centre-forward', 'second-striker', 'winger', 'right-winger', 'left-winger'])) return 'A';
        return null;
    }
}