<?php

namespace App\Imports;

use App\Models\HistoricalPlayerStat;
use App\Models\Player;
use App\Traits\FindsTeam; // <-- 1. IMPORTIAMO IL TRAIT
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class TuttiHistoricalStatsImport implements ToModel, WithHeadingRow, WithChunkReading
{
    use FindsTeam; // <-- 2. USIAMO IL TRAIT
    
    protected $season;
    protected $leagueName;
    
    public function __construct(int $season, string $leagueName)
    {
        $this->season = $season;
        $this->leagueName = $leagueName;
        $this->preloadTeams(); // <-- 3. CARICHIAMO LE SQUADRE UNA SOLA VOLTA ALL'INIZIO
    }
    
    public function model(array $row)
    {
        // === INIZIO BLOCCO DI DEBUG (Versione per il Log File) ===
        // Usiamo una variabile statica per eseguire il debug solo sulla prima riga valida
        static $debugDone = false;
        if (!$debugDone && isset($row['id']) && is_numeric($row['id'])) {
            $id_from_excel = $row['id'];
            
            // Scriviamo nel file di log
            \Illuminate\Support\Facades\Log::info("--- INIZIO DEBUG PRIMA RIGA ---");
            \Illuminate\Support\Facades\Log::info("Cerco il giocatore con Fanta ID dal file Excel: " . $id_from_excel);
            \Illuminate\Support\Facades\Log::info("Tipo di dato dell'ID dal file Excel: " . gettype($id_from_excel));
            
            $player_in_db = \App\Models\Player::where('fanta_platform_id', $id_from_excel)->first();
            
            if ($player_in_db) {
                \Illuminate\Support\Facades\Log::info("RISULTATO: TROVATO! L'ID nel database è: " . $player_in_db->fanta_platform_id . " (Tipo: " . gettype($player_in_db->fanta_platform_id) . ")");
            } else {
                \Illuminate\Support\Facades\Log::info("RISULTATO: NON TROVATO nel DB usando l'ID dall'Excel.");
                
                $any_player = \App\Models\Player::inRandomOrder()->first();
                if ($any_player) {
                    \Illuminate\Support\Facades\Log::info("INFO DI CONFRONTO: un ID a caso nel DB è: " . $any_player->fanta_platform_id . " (Tipo: " . gettype($any_player->fanta_platform_id) . ")");
                }
            }
            \Illuminate\Support\Facades\Log::info("--- FINE DEBUG ---");
            $debugDone = true; // Impostiamo a true per non ripetere il debug
        }
        // === FINE BLOCCO DI DEBUG ===
        
        $player = Player::where('fanta_platform_id', $row['id'])->first();
        
        if ($player) {
            // 4. USIAMO LA RICERCA INTELLIGENTE PER TROVARE IL TEAM ID
            $teamNameFromRow = $row['squadra'] ?? null;
            $teamId = null;
            if ($teamNameFromRow) {
                $teamId = $this->findTeamIdByName($teamNameFromRow);
            }
            
            return new HistoricalPlayerStat([
                'player_id'             => $player->id,
                'team_id'               => $teamId, // <-- 5. IL CAMPO VIENE POPOLATO AUTOMATICAMENTE
                'season_year'           => $this->season, // Assumendo che il tuo campo si chiami season_year
                'league_name'           => $this->leagueName,
                'team_name_for_season'  => $teamNameFromRow,
                
                // ... tutti gli altri campi delle statistiche che già mappi...
                'games_played' => $row['pg'] ?? 0,
                'goals_scored' => $row['gf'] ?? 0,
                // etc...
            ]);
        }
        
        return null;
    }
    
    public function chunkSize(): int
    {
        return 100;
    }
}