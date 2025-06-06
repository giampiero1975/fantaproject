<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\PlayerFbrefStat;
use App\Models\HistoricalPlayerStat;
use App\Models\Player; // Importa il modello Player
use App\Models\Team;   // Importa il modello Team
use App\Models\ImportLog; // Importa il modello ImportLog
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB; // Per transazioni se necessario
use Illuminate\Support\Facades\Config; // Per accedere alla configurazione

class ProcessFbrefStatsToHistorical extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stats:process-fbref-to-historical
                            {--season= : Anno di inizio stagione da processare (es. 2023 per 2023-24). Se omesso, processa tutte le stagioni in player_fbref_stats.}
                            {--player_id= : ID del giocatore specifico (dalla tabella players) da processare.}
                            {--overwrite : Sovrascrive i record esistenti in historical_player_stats per la stessa chiave (player, season, team, league).}';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Processa i dati grezzi da player_fbref_stats e li salva/aggiorna in historical_player_stats.';
    
    /**
     * Fattori di conversione da lega a lega, caricati dalla configurazione.
     * @var array
     */
    protected array $leagueConversionFactors;
    
    public function __construct()
    {
        parent::__construct();
        // Carica i fattori di conversione dalla configurazione
        $this->leagueConversionFactors = Config::get('projection_settings.player_stats_league_conversion_factors', []);
        if (empty($this->leagueConversionFactors)) {
            Log::warning(self::class . ": 'player_stats_league_conversion_factors' non configurati o vuoti in config/projection_settings.php. Le statistiche non verranno convertite.");
        }
    }
    
    public function handle()
    {
        $this->info("Avvio processamento dati FBRef grezzi verso storico elaborato...");
        Log::info(self::class . ": Avvio processamento.");
        
        $startTime = microtime(true);
        $processedRecords = 0;
        $createdRecords = 0;
        $updatedRecords = 0;
        $skippedRecords = 0;
        $errorCount = 0;
        
        $query = PlayerFbrefStat::with(['player', 'team']); // Carica le relazioni per efficienza
        
        if ($this->option('season')) {
            $query->where('season_year', $this->option('season'));
            $this->info("Filtraggio per stagione: " . $this->option('season'));
        }
        if ($this->option('player_id')) {
            $query->where('player_id', $this->option('player_id'));
            $this->info("Filtraggio per player_id: " . $this->option('player_id'));
        }
        
        $fbrefStats = $query->get();
        
        if ($fbrefStats->isEmpty()) {
            $this->warn("Nessun record trovato in player_fbref_stats da processare con i criteri forniti.");
            Log::warning(self::class . ": Nessun record da processare.");
            return Command::SUCCESS;
        }
        
        $this->info("Trovati " . $fbrefStats->count() . " record da FBRef da processare.");
        $bar = $this->output->createProgressBar($fbrefStats->count());
        $bar->start();
        
        foreach ($fbrefStats as $fbrefStat) {
            $bar->advance();
            $processedRecords++;
            
            // Verifica che il giocatore e la squadra associati esistano
            if (!$fbrefStat->player) {
                $this->getOutput()->newLine();
                $this->warn("Saltato record FBRef ID {$fbrefStat->id}: Player associato non trovato (player_id: {$fbrefStat->player_id}).");
                Log::warning(self::class . ": Saltato record FBRef ID {$fbrefStat->id} - Player mancante.", ['fbref_stat_id' => $fbrefStat->id, 'player_id' => $fbrefStat->player_id]);
                $skippedRecords++;
                continue;
            }
            if (!$fbrefStat->team) {
                $this->getOutput()->newLine();
                $this->warn("Saltato record FBRef ID {$fbrefStat->id} per Player {$fbrefStat->player->name}: Team associato non trovato (team_id: {$fbrefStat->team_id}).");
                Log::warning(self::class . ": Saltato record FBRef ID {$fbrefStat->id} - Team mancante.", ['fbref_stat_id' => $fbrefStat->id, 'team_id' => $fbrefStat->team_id]);
                $skippedRecords++;
                continue;
            }
            if (empty($fbrefStat->player->fanta_platform_id)) {
                $this->getOutput()->newLine();
                $this->warn("Saltato record FBRef ID {$fbrefStat->id} per Player {$fbrefStat->player->name}: fanta_platform_id mancante nel record Player.");
                Log::warning(self::class . ": Saltato record FBRef ID {$fbrefStat->id} - fanta_platform_id mancante.", ['player_id' => $fbrefStat->player->id]);
                $skippedRecords++;
                continue;
            }
            
            // Ottieni i fattori di conversione per la lega di origine
            $conversionFactors = $this->leagueConversionFactors[$fbrefStat->league_name] ??
            ($this->leagueConversionFactors['default'] ?? ['goals_scored' => 1.0, 'assists' => 1.0, 'avg_rating' => 1.0]);
            
            // Mappatura e conversione dei campi
            $dataToStore = [
                'team_name_for_season'     => $fbrefStat->team->name,
                'role_for_season'          => $fbrefStat->player->role,
                'games_played'             => $fbrefStat->games_played ?? 0,
                'avg_rating'               => null, // FBRef non fornisce MV standard, quindi lo lasciamo nullo o lo stimiamo con una base poi modulata dalla conversione
                'fanta_avg_rating'         => null, // Verrà calcolata dal ProjectionEngineService
                'goals_scored'             => (int)round(($fbrefStat->goals ?? 0) * ($conversionFactors['goals_scored'] ?? 1.0)),
                'assists'                  => (int)round(($fbrefStat->assists ?? 0) * ($conversionFactors['assists'] ?? 1.0)),
                'yellow_cards'             => $fbrefStat->yellow_cards ?? 0,
                'red_cards'                => $fbrefStat->red_cards ?? 0,
                'own_goals'                => $fbrefStat->misc_own_goals ?? 0,
                'penalties_taken'          => $fbrefStat->penalties_attempted ?? 0,
                'penalties_scored'         => $fbrefStat->penalties_made ?? 0,
                'penalties_missed'         => ($fbrefStat->penalties_attempted ?? 0) - ($fbrefStat->penalties_made ?? 0),
                'goals_conceded'           => ($fbrefStat->player->role === 'P') ? ($fbrefStat->gk_goals_conceded ?? 0) : 0,
                'penalties_saved'          => ($fbrefStat->player->role === 'P') ? ($fbrefStat->gk_penalties_saved ?? 0) : 0,
                // Assicurati che 'mantra_role_for_season' sia popolato se disponibile da fbrefStat o da Player
                'mantra_role_for_season'   => $fbrefStat->player->mantra_role ?? null, // Assumendo che il ruolo Mantra sia nel modello Player o FbrefStat
            ];
            
            // Se la Media Voto non è direttamente disponibile da FBRef, puoi decidere di stimarla o lasciarla NULL.
            // Per ora, la lasciamo NULL come indicato nel piano, ma può essere un punto di miglioramento futuro.
            // Oppure, se si ha una stima della MV per lega (es. da memo per statistiche neopromosse.md), si può applicare qui.
            // Esempio: Se `MedieVotoOriginale` dal CSV avanzato fosse disponibile, e si stimava MV per Serie B.
            // $dataToStore['avg_rating'] = ($fbrefStat->avg_rating_original ?? 6.0) * ($conversionFactors['avg_rating'] ?? 1.0);
            
            try {
                // Chiavi univoche per identificare un record in historical_player_stats
                $uniqueKeys = [
                    'player_fanta_platform_id' => $fbrefStat->player->fanta_platform_id,
                    'season_year'              => $fbrefStat->season_year . '-' . substr($fbrefStat->season_year + 1, 2),
                    'team_id'                  => $fbrefStat->team_id,
                    'league_name'              => $fbrefStat->league_name, // Il nome della lega di origine dei dati FBRef
                ];
                
                $historicalStat = null;
                if ($this->option('overwrite')) {
                    $historicalStat = HistoricalPlayerStat::updateOrCreate($uniqueKeys, $dataToStore);
                } else {
                    $historicalStat = HistoricalPlayerStat::firstOrCreate($uniqueKeys, $dataToStore);
                }
                
                if ($historicalStat->wasRecentlyCreated) {
                    $createdRecords++;
                } elseif ($historicalStat && $this->option('overwrite') && $historicalStat->wasChanged()) {
                    $updatedRecords++;
                }
            } catch (\Exception $e) {
                $this->getOutput()->newLine();
                $this->error("Errore durante il salvataggio dello storico per Player {$fbrefStat->player->name} (FBRef ID {$fbrefStat->id}): " . $e->getMessage());
                Log::error(self::class . ": Errore DB per FBRef ID {$fbrefStat->id} - Player: {$fbrefStat->player->name}. Msg: {$e->getMessage()}", ['data' => $dataToStore]);
                $errorCount++;
                continue;
            }
        }
        
        $bar->finish();
        $duration = microtime(true) - $startTime;
        $summary = "Processamento dati FBRef completato in " . round($duration, 2) . " secondi. " .
            "Record FBRef letti: {$fbrefStats->count()}. " .
            "Record processati per storico: {$processedRecords}. " .
            "Nuovi record storico creati: {$createdRecords}. " .
            "Record storico aggiornati (se overwrite): {$updatedRecords}. " .
            "Record saltati (player/team/fpid mancante): {$skippedRecords}. " .
            "Errori DB: {$errorCount}.";
        
        $this->info("\n" . $summary);
        Log::info(self::class . ": " . $summary);
        
        // Registra l'operazione nel log degli import
        ImportLog::create([
            'original_file_name' => 'Processamento FBRef a Storico' . ($this->option('season') ? ' Stagione ' . $this->option('season') : ' Tutte'),
            'import_type' => 'fbref_processing',
            'status' => ($errorCount === 0 && $skippedRecords === 0) ? 'successo' : (($createdRecords > 0 || $updatedRecords > 0) ? 'parziale' : 'fallito'),
            'details' => $summary,
            'rows_processed' => $processedRecords,
            'rows_created' => $createdRecords,
            'rows_updated' => $updatedRecords,
            'rows_failed' => $errorCount + $skippedRecords,
        ]);
        
        return Command::SUCCESS;
    }
}