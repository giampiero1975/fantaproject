<?php

namespace App\Traits;

use App\Models\HistoricalPlayerStat;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Support\Facades\Log;

trait ImportsHistoricalPlayerData
{
    /**
     * Processa una singola riga dal file di importazione dello storico.
     *
     * @param array $row La riga di dati dal file.
     * @return HistoricalPlayerStat|null
     */
    protected function processHistoricalPlayerRow(array $row): ?HistoricalPlayerStat
    {
        if (!isset($this->season) || !isset($this->leagueName)) {
            Log::error("Il Trait richiede che le proprietà \$season e \$leagueName siano definite.");
            return null;
        }
        
        $fantaPlatformIdRaw = $row[0] ?? null;
        if ($fantaPlatformIdRaw === null || trim((string)$fantaPlatformIdRaw) === '') {
            return null;
        }
        $fantaPlatformId = (int)trim((string)$fantaPlatformIdRaw);
        
        $playerRole = $row[1] ?? 'N/D';
        $playerName = $row[3] ?? null;
        $teamName = $row[4] ?? null;
        
        if (empty($playerName)) {
            return null;
        }
        
        // --- INIZIO LOGICA FINALE E COMPLETA ---
        
        // 1. Cerca il giocatore tramite fanta_platform_id, INCLUDENDO anche quelli cestinati
        $player = Player::withTrashed()->where('fanta_platform_id', $fantaPlatformId)->first();
        
        // 2. Se il giocatore non esiste AFFATTO, lo creiamo
        if (!$player) {
            $team = Team::where('name', $teamName)->orWhere('short_name', $teamName)->first();
            $player = Player::create([
                'fanta_platform_id' => $fantaPlatformId,
                'name'      => $playerName,
                'role'      => $playerRole,
                'team_id'   => $team->id ?? null,
                'team_name' => $team->short_name ?? $teamName
            ]);
            // 3. Se il giocatore ESISTE ma era stato cancellato, lo ripristiniamo
        } elseif ($player->trashed()) {
            $player->restore();
            Log::info("Giocatore ripristinato dal cestino: {$player->name} (ID: {$player->id})");
        }
        // --- FINE LOGICA FINALE E COMPLETA ---
        
        // A questo punto, $player è un record valido e attivo nel DB
        $team = Team::where('name', 'like', $teamName)->orWhere('short_name', 'like', $teamName)->first();
        
        return HistoricalPlayerStat::updateOrCreate(
            [
                'player_id'   => $player->id,
                'season_year' => $this->season,
            ],
            [
                'player_fanta_platform_id' => $fantaPlatformId,
                'team_id' => $team->id ?? null,
                'team_name_for_season' => $teamName,
                'role_for_season' => $playerRole,
                'league_name' => $this->leagueName,
                'mantra_role_for_season' => $row[2] ?? null,
                'games_played' => $row[5] ?? 0,
                'avg_rating' => (float) str_replace(',', '.', $row[6] ?? 0),
                'fanta_avg_rating' => (float) str_replace(',', '.', $row[7] ?? 0),
                'goals_scored' => $row[8] ?? 0,
                'goals_conceded' => $row[9] ?? 0,
                'penalties_taken' => $row[11] ?? 0,
                'penalties_scored' => $row[12] ?? 0,
                'penalties_saved' => $row[10] ?? 0,
                'penalties_missed' => $row[13] ?? 0,
                'assists' => $row[14] ?? 0,
                'yellow_cards' => $row[15] ?? 0,
                'red_cards' => $row[16] ?? 0,
                'own_goals' => $row[17] ?? 0,
            ]
            );
    }
}