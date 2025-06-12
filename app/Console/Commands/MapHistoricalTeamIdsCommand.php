<?php

namespace App\Console\Commands;

use App\Models\HistoricalPlayerStat;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class MapHistoricalTeamIdsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stats:map-team-ids';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Popola il team_id in historical_player_stats con una ricerca intelligente basata sul nome della squadra.';
    
    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Inizio della mappatura dei team_id per lo storico giocatori...');
        
        // 1. Carichiamo tutte le squadre una sola volta con i campi che ci servono
        $allTeams = Team::all(['id', 'name', 'short_name']);
        $this->info($allTeams->count() . ' squadre di riferimento caricate in memoria.');
        
        $updatedCount = 0;
        $notFoundCount = 0;
        $unmatchedNames = collect(); // Usiamo una collection per gestire i nomi non trovati
        
        // 2. Processiamo solo i record dove team_id è NULL, in blocchi
        $this->info('Analisi dei record in historical_player_stats dove team_id e nullo...');
        HistoricalPlayerStat::whereNull('team_id')->chunkById(200, function (Collection $stats) use (&$updatedCount, &$notFoundCount, &$unmatchedNames, $allTeams) {
            foreach ($stats as $stat) {
                $teamNameInStats = $stat->team_name_for_season;
                
                if (empty($teamNameInStats)) {
                    continue; // Salta se il nome della squadra è vuoto
                }
                
                // 3. Usiamo la nostra nuova funzione di ricerca intelligente
                $foundTeamId = $this->findTeamId($teamNameInStats, $allTeams);
                
                if ($foundTeamId) {
                    $stat->team_id = $foundTeamId;
                    $stat->save();
                    $updatedCount++;
                } else {
                    $notFoundCount++;
                    // Teniamo traccia dei nomi non trovati per mostrarli alla fine
                    $unmatchedNames->push($teamNameInStats);
                }
            }
            $this->output->write('.'); // Feedback visivo
        });
            
            $this->newLine(2);
            $this->info('Mappatura completata.');
            $this->info("Record aggiornati con successo: " . $updatedCount);
            
            if ($notFoundCount > 0) {
                $this->warn("Record non aggiornati per mancanza di corrispondenza: " . $notFoundCount);
                $this->warn("I seguenti nomi di squadra presenti in 'historical_player_stats' non hanno trovato una corrispondenza:");
                foreach ($unmatchedNames->unique()->sort() as $name) {
                    $this->line('- ' . $name);
                }
            }
            
            return Command::SUCCESS;
    }
    
    /**
     * Cerca un team usando diverse strategie di matching.
     *
     * @param string $nameInStats Il nome della squadra dallo storico.
     * @param Collection $allTeams La collection di tutte le squadre.
     * @return int|null L'ID del team trovato, o null.
     */
    private function findTeamId(string $nameInStats, Collection $allTeams): ?int
    {
        // Strategia 1: Match esatto sul nome breve (short_name) - più comune
        $team = $allTeams->firstWhere('short_name', $nameInStats);
        if ($team) {
            return $team->id;
        }
        
        // Strategia 2: Match esatto sul nome completo (name)
        $team = $allTeams->firstWhere('name', $nameInStats);
        if ($team) {
            return $team->id;
        }
        
        // Strategia 3: Match non sensibile a maiuscole/minuscole (case-insensitive) sul nome breve
        $team = $allTeams->first(function ($team) use ($nameInStats) {
            return strcasecmp($team->short_name, $nameInStats) === 0;
        });
            if ($team) {
                return $team->id;
            }
            
            // Strategia 4: Match non sensibile a maiuscole/minuscole sul nome completo
            $team = $allTeams->first(function ($team) use ($nameInStats) {
                return strcasecmp($team->name, $nameInStats) === 0;
            });
                if ($team) {
                    return $team->id;
                }
                
                // Se nessuna strategia ha funzionato, non abbiamo trovato nulla
                return null;
    }
}