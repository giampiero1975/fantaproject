<?php

namespace App\Imports;

use App\Models\HistoricalPlayerStat;
use App\Models\Player;
use App\Models\Team;
use Maatwebsite\Excel\Concerns\ToModel;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\WithStartRow;

class PlayerSeasonStatsImport implements ToModel, WithStartRow
{
    private int $seasonStartYear;
    private string $leagueName;
    
    public function __construct(int $seasonStartYear, string $leagueName)
    {
        $this->seasonStartYear = $seasonStartYear;
        $this->leagueName = $leagueName;
        Log::info("Inizio importazione statistiche storiche per la stagione {$this->seasonStartYear} (Lega: {$this->leagueName})");
    }
    
    public function startRow(): int
    {
        return 2;
    }
    
    public function model(array $row)
    {
        $fantaPlatformId = $row[0] ?? null;
        $playerName = $row[3] ?? null;
        $teamName = $row[4] ?? null;
        
        if (!is_numeric($fantaPlatformId) || empty($playerName)) {
            Log::warning('Saltata riga (dati base mancanti o non validi): ' . json_encode($row));
            return null;
        }
        
        // --- NUOVA LOGICA: TROVA, NON CREARE ---
        $player = Player::where('fanta_platform_id', $fantaPlatformId)->first();
        
        // Se il giocatore non esiste nel nostro DB (dal roster), non possiamo importare il suo storico.
        if (!$player) {
            Log::warning("Statistica storica per Fanta ID {$fantaPlatformId} ({$playerName}) saltata: il giocatore non esiste nella tabella 'players'. Caricare prima il roster corretto.");
            return null;
        }
        
        // Troviamo il team di quella stagione specifica, ma senza crearlo
        $team = Team::where('name', $teamName)->first();
        
        // Se il giocatore esiste, procediamo a creare/aggiornare il suo record di statistica storica.
        HistoricalPlayerStat::updateOrCreate(
            [
                'player_fanta_platform_id' => $fantaPlatformId,
                'season_year' => $this->seasonStartYear,
                'league_name' => $this->leagueName,
            ],
            [
                'player_id' => $player->id,
                'team_id' => $team->id ?? null, // Salva l'ID del team se lo troviamo
                'team_name_for_season' => $teamName,
                'role_for_season' => $row[1] ?? null,
                'mantra_role_for_season' => $row[2] ?? null,
                'games_played' => $row[5] ?? 0,
                'avg_rating' => (float) str_replace(',', '.', $row[6] ?? 0),
                'fanta_avg_rating' => (float) str_replace(',', '.', $row[7] ?? 0),
                'goals_scored' => $row[8] ?? 0,
                'goals_conceded' => $row[9] ?? 0,
                'penalties_saved' => $row[12] ?? 0,
                'penalties_missed' => $row[13] ?? 0,
                'assists' => $row[14] ?? 0,
                'yellow_cards' => $row[15] ?? 0,
                'red_cards' => $row[16] ?? 0,
                'own_goals' => $row[17] ?? 0,
                'source' => 'Excel_Fantacalcio_Import',
            ]
            );
        
        // Ritorniamo null perché l'operazione è già stata fatta con updateOrCreate
        return null;
    }
}