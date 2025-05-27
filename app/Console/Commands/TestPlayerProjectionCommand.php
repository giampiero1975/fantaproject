<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Player;
use App\Models\UserLeagueProfile;
use App\Services\FantasyPointCalculatorService;
use App\Services\ProjectionEngineService;
use Illuminate\Support\Facades\Log;

class TestPlayerProjectionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:projection {playerId : The fanta_platform_id of the player}';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testa il ProjectionEngineService per un giocatore specifico';
    
    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(FantasyPointCalculatorService $pointCalculator, ProjectionEngineService $projectionEngine) // Iniezione dipendenze
    {
        $playerId = $this->argument('playerId');
        $this->info("Avvio test di proiezione per il giocatore con fanta_platform_id: {$playerId}");
        
        // Carica il giocatore con la sua squadra (se esiste)
        $player = Player::with('team')->where('fanta_platform_id', $playerId)->first();
        
        if (!$player) {
            $this->error("Giocatore con fanta_platform_id {$playerId} non trovato.");
            return Command::FAILURE;
        }
        
        // Gestione del nome della squadra in modo sicuro
        $teamName = 'N/A';
        if ($player->team) {
            $teamName = $player->team->name;
        }
        
        $this->info("Giocatore trovato: {$player->name} (ID DB: {$player->id}, Ruolo: {$player->role}, Squadra: {$teamName})");
        
        // Recupera o crea un profilo lega di default per il test
        $leagueProfile = UserLeagueProfile::first();
        if (!$leagueProfile) {
            $this->warn("Nessun UserLeagueProfile trovato. Ne creo uno di default per il test.");
            $defaultScoringRules = [
                'gol_p' => 5, 'gol_d' => 4.5, 'gol_c' => 4, 'gol_a' => 3,
                'assist' => 1, 'ammonizione' => -0.5, 'espulsione' => -1,
                'rigore_segnato' => 3, 'rigore_sbagliato' => -3, 'rigore_parato' => 3,
                'autogol' => -2, 'gol_subito_p' => -1, 'clean_sheet_p' => 1, 'clean_sheet_d' => 0.5
                // Aggiungi altre regole base se necessario per il FantasyPointCalculatorService
            ];
            $leagueProfile = UserLeagueProfile::create([
                'league_name' => 'Lega Test Default',
                'total_budget' => 500,
                'num_goalkeepers' => 3, 'num_defenders' => 8, 'num_midfielders' => 8, 'num_attackers' => 6,
                'num_participants' => 10,
                'scoring_rules' => $defaultScoringRules,
            ]);
            $this->info("Profilo lega di default creato con ID: {$leagueProfile->id}");
        } else {
            $this->info("Utilizzo profilo lega esistente con ID: {$leagueProfile->id}");
            // Assicurati che scoring_rules sia un array, anche se viene da DB come JSON castato
            if (is_string($leagueProfile->scoring_rules)) {
                $leagueProfile->scoring_rules = json_decode($leagueProfile->scoring_rules, true) ?? [];
            } elseif (is_null($leagueProfile->scoring_rules)) {
                $leagueProfile->scoring_rules = [];
            }
        }
        
        // Istanze dei servizi tramite iniezione delle dipendenze nel metodo handle
        // $pointCalculator = new FantasyPointCalculatorService(); // Non più necessario se iniettato
        // $projectionEngine = new ProjectionEngineService($pointCalculator); // Non più necessario se iniettato
        
        $this->info("Esecuzione di generatePlayerProjection...");
        Log::info("TestPlayerProjectionCommand: Chiamata a generatePlayerProjection per {$player->name}");
        
        $projections = $projectionEngine->generatePlayerProjection($player, $leagueProfile);
        
        $this->info("Proiezioni generate per {$player->name}:");
        $this->line(json_encode($projections, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        if (isset($projections['fanta_media_proj_per_game'])) { // NUOVA CHIAVE
            $this->info("FantaMedia Proiettata PER PARTITA: " . $projections['fanta_media_proj_per_game']);
            if (isset($projections['total_fantasy_points_proj'])) {
                $this->info("Fantapunti totali stagionali proiettati: " . $projections['total_fantasy_points_proj']);
            }
            if (isset($projections['presenze_proj'])) {
                $this->info("Presenze proiettate: " . $projections['presenze_proj']);
            }
        } else {
            $this->warn("Chiave 'fanta_media_proj_per_game' non presente nell'output delle proiezioni.");
        }
        
        return Command::SUCCESS;
    }
}
