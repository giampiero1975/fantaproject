<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Player;
use App\Models\UserLeagueProfile;
use App\Models\PlayerProjectionSeason; // Importa il nuovo modello
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
    protected $signature = 'test:projection
                            {playerId : The fanta_platform_id of the player}
                            {--season= : Anno di inizio della stagione di proiezione da testare (es. 2025 per 2025-26). Se omesso, cercherà l\'ultima proiezione disponibile.}';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testa il ProjectionEngineService per un giocatore specifico, leggendo le proiezioni dalla tabella PlayerProjectionSeason.';
    
    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(FantasyPointCalculatorService $pointCalculator, ProjectionEngineService $projectionEngine) // Iniezione dipendenze
    {
        $playerId = $this->argument('playerId');
        $targetSeasonYear = $this->option('season'); // Anno della stagione di proiezione
        
        $this->info("Avvio test di proiezione per il giocatore con fanta_platform_id: {$playerId}");
        
        // Trova il giocatore (necessario per nome, ruolo, ecc.)
        $player = Player::where('fanta_platform_id', $playerId)->first();
        
        if (!$player) {
            $this->error("Giocatore con fanta_platform_id {$playerId} non trovato nel database.");
            Log::error("TestPlayerProjectionCommand: Giocatore con fanta_platform_id {$playerId} non trovato.");
            return Command::FAILURE;
        }
        
        $teamName = $player->team?->name ?? 'N/A';
        $this->info("Giocatore trovato: {$player->name} (ID DB: {$player->id}, Ruolo: {$player->role}, Squadra: {$teamName})");
        
        // --- LOGICA AGGIORNATA: RECUPERA LA PROIEZIONE DALLA NUOVA TABELLA ---
        $projection = null;
        if ($targetSeasonYear) {
            $projection = PlayerProjectionSeason::where('player_fanta_platform_id', $playerId)
            ->where('season_start_year', $targetSeasonYear)
            ->first();
            if (!$projection) {
                $this->warn("Nessuna proiezione trovata per il giocatore {$player->name} per la stagione {$targetSeasonYear}-" . ($targetSeasonYear + 1) . ".");
            }
        } else {
            // Se nessuna stagione è specificata, cerca l'ultima proiezione disponibile
            $projection = PlayerProjectionSeason::where('player_fanta_platform_id', $playerId)
            ->orderBy('season_start_year', 'desc')
            ->first();
            if ($projection) {
                $this->info("Recuperata l'ultima proiezione disponibile per la stagione {$projection->season_start_year}-" . ($projection->season_start_year + 1) . ".");
                $targetSeasonYear = $projection->season_start_year; // Imposta la stagione per coerenza nei log
            } else {
                $this->warn("Nessuna proiezione trovata per il giocatore {$player->name} in nessuna stagione.");
            }
        }
        
        // Se non trovo una proiezione esistente, la genero per la visualizzazione di test.
        // Ho rimosso il controllo '|| $this->option('force')' che causava l'errore.
        $projectionsArray = [];
        if (!$projection) {
            $this->info("Generazione di una nuova proiezione per il giocatore {$player->name} per la visualizzazione di test...");
            $leagueProfile = UserLeagueProfile::first();
            if (!$leagueProfile) {
                $this->warn("Nessun UserLeagueProfile trovato. Ne creo uno di default per il test.");
                $defaultScoringRules = [
                    'gol_portiere' => 0, 'gol_difensore' => 4, 'gol_centrocampista' => 3.5, 'gol_attaccante' => 3,
                    'rigore_segnato' => 3, 'rigore_sbagliato' => -3, 'rigore_parato' => 3,
                    'autogol' => -2, 'assist_standard' => 1, 'assist_da_fermo' => 1,
                    'ammonizione' => -0.5, 'espulsione' => -1, 'gol_subito_portiere' => -1,
                    'imbattibilita_portiere' => 1, 'clean_sheet_mv_threshold' => 5.5, 'clean_sheet_p' => 1, 'clean_sheet_d' => 0.5
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
                // Assicurati che scoring_rules sia un array
                if (is_string($leagueProfile->scoring_rules)) {
                    $leagueProfile->scoring_rules = json_decode($leagueProfile->scoring_rules, true) ?? [];
                } elseif (is_null($leagueProfile->scoring_rules)) {
                    $leagueProfile->scoring_rules = [];
                }
            }
            
            Log::info("TestPlayerProjectionCommand: Chiamata a generatePlayerProjection per {$player->name}");
            $projectionsArray = $projectionEngine->generatePlayerProjection($player, $leagueProfile);
            
        } elseif ($projection) {
            // Se esiste una proiezione e non è richiesta la rigenerazione, la convertiamo in array per la visualizzazione
            $projectionsArray = $projection->toArray();
            $this->info("Visualizzazione proiezione salvata per la stagione {$projection->season_start_year}-" . ($projection->season_start_year + 1) . ":");
        }
        // --- FINE LOGICA AGGIORNATA ---
        
        if (empty($projectionsArray)) {
            $this->error("Impossibile generare o recuperare proiezioni per {$player->name}.");
            return Command::FAILURE;
        }
        
        $this->line(json_encode($projectionsArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        // Dettagli utili dall'array di proiezioni (usa le chiavi corrette dal modello PlayerProjectionSeason)
        if (isset($projectionsArray['fanta_mv_proj'])) {
            $this->info("FantaMedia Proiettata PER PARTITA: " . $projectionsArray['fanta_mv_proj']);
        }
        if (isset($projectionsArray['total_fanta_points_proj'])) {
            $this->info("Fantapunti totali stagionali proiettati: " . $projectionsArray['total_fanta_points_proj']);
        }
        if (isset($projectionsArray['games_played_proj'])) {
            $this->info("Presenze proiettate: " . $projectionsArray['games_played_proj']);
        }
        
        return Command::SUCCESS;
    }
}