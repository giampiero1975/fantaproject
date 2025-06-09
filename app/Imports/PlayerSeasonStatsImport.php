<?php

namespace App\Imports;

use App\Models\HistoricalPlayerStat;
use App\Models\Player;
use App\Models\Team; // Assicurati che questo sia importato
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\Log;

class PlayerSeasonStatsImport implements ToCollection, WithHeadingRow
{
    private $seasonStartYear;
    private $leagueName; // Nuovo: per passare il nome della lega
    
    public function __construct(int $seasonStartYear, string $leagueName = 'Serie A') // Modificato: aggiunta leagueName
    {
        $this->seasonStartYear = $seasonStartYear;
        $this->leagueName = $leagueName; // Inizializza il nome della lega
    }
    
    public function collection(Collection $rows)
    {
        Log::info("Inizio importazione statistiche storiche per la stagione {$this->seasonStartYear}-" . ($this->seasonStartYear + 1) . " (Lega: {$this->leagueName})");
        
        foreach ($rows as $row) {
            // Salta le righe con dati mancanti cruciali (es. nome giocatore o ID)
            if (empty($row['id']) && empty($row['nome'])) { // 'id' si riferisce a fanta_platform_id nel CSV
                Log::warning("Saltata riga a causa di ID o Nome Giocatore mancante: " . json_encode($row->toArray()));
                continue;
            }
            
            $fantaPlatformId = $row['id'] ?? null;
            $playerName = $row['nome'] ?? null;
            $teamName = $row['squadra'] ?? null; // Assicurati che il CSV abbia la colonna 'squadra'
            
            if (!$fantaPlatformId && $playerName) {
                // Tenta di trovare il giocatore per nome se ID non presente, ma è meno affidabile
                $player = Player::where('name', $playerName)->first();
                if ($player) {
                    $fantaPlatformId = $player->fanta_platform_id;
                }
            }
            
            if (!$fantaPlatformId) {
                Log::warning("Saltata riga: Impossibile identificare il giocatore. ID Piattaforma o Nome mancante/non trovato per: " . ($playerName ?? 'N/A') . " - " . ($teamName ?? 'N/A'));
                continue;
            }
            
            // Trova il team_id basandosi sul nome della squadra
            $team = Team::where('name', $teamName)->first();
            $teamId = $team->id ?? null;
            
            if (!$teamId) {
                Log::warning("Saltata riga per ID Piattaforma {$fantaPlatformId} ({$playerName}): Squadra '{$teamName}' non trovata nel database.");
                continue;
            }
            
            // Prepara i dati da salvare in historical_player_stats
            $seasonYearFormatted = $this->seasonStartYear . '-' . substr($this->seasonStartYear + 1, 2);
            
            // Per i valori che potrebbero essere stringhe con virgola come separatore decimale, usare str_replace
            // Oppure assicurarsi che il CSV sia già con il punto come separatore decimale.
            // Assumiamo che i voti siano già numeri, altrimenti servirà una funzione per pulirli.
            $avgRating = floatval(str_replace(',', '.', $row['mv'] ?? 0.0));
            $fantaAvgRating = floatval(str_replace(',', '.', $row['fm'] ?? 0.0));
            
            // Qui stiamo importando dati che sono già "storici" e presumibilmente aggregati.
            // Per questi dati, goals_scored, assists ecc. sono i totali della stagione dal CSV.
            $goalsScored = floatval(str_replace(',', '.', $row['gol_fatti'] ?? 0));
            $assists = floatval(str_replace(',', '.', $row['assist'] ?? 0));
            $yellowCards = floatval(str_replace(',', '.', $row['ammonizioni'] ?? 0));
            $redCards = floatval(str_replace(',', '.', $row['espulsioni'] ?? 0));
            $ownGoals = floatval(str_replace(',', '.', $row['autogol'] ?? 0));
            $penaltiesTaken = floatval(str_replace(',', '.', $row['rigori_calciati'] ?? 0)); // Assicurati colonna CSV
            $penaltiesScored = floatval(str_replace(',', '.', $row['rigori_segnati'] ?? 0)); // Assicurati colonna CSV
            $penaltiesMissed = $penaltiesTaken - $penaltiesScored; // Calcola se non è nel CSV
            $penaltiesSaved = floatval(str_replace(',', '.', $row['rigori_parati'] ?? 0)); // Assicurati colonna CSV
            $goalsConceded = floatval(str_replace(',', '.', $row['gol_subiti'] ?? 0)); // Assicurati colonna CSV
            
            // Per il ruolo, usiamo quello dal CSV se esiste, altrimenti dal modello Player
            $roleForSeason = $row['ruolo'] ?? $player->role ?? null;
            // Mantra role non è tipicamente nel CSV storico, lo lasciamo a null
            $mantraRoleForSeason = null;
            
            HistoricalPlayerStat::updateOrCreate(
                [
                    'player_fanta_platform_id' => $fantaPlatformId,
                    'season_year' => $seasonYearFormatted,
                    'team_id' => $teamId,
                    'league_name' => $this->leagueName, // <-- Popolato con "Serie A" o la lega specificata
                ],
                [
                    'team_name_for_season' => $teamName,
                    'role_for_season' => $roleForSeason,
                    'mantra_role_for_season' => $mantraRoleForSeason,
                    'games_played' => $row['presenze'] ?? 0,
                    'avg_rating' => $avgRating,
                    'fanta_avg_rating' => $fantaAvgRating,
                    'goals_scored' => $goalsScored,
                    'goals_conceded' => $goalsConceded,
                    'penalties_saved' => $penaltiesSaved,
                    'penalties_taken' => $penaltiesTaken,
                    'penalties_scored' => $penaltiesScored,
                    'penalties_missed' => $penaltiesMissed,
                    'assists' => $assists,
                    'yellow_cards' => $yellowCards,
                    'red_cards' => $redCards,
                    'own_goals' => $ownGoals,
                    // 'assists_from_set_piece' => $row['assist_da_fermo'] ?? null, // Se il CSV ha questa colonna
                ]
                );
            Log::info("Importata/Aggiornata statistica storica per {$playerName} (ID: {$fantaPlatformId}) per la stagione {$seasonYearFormatted}.");
        }
    }
}