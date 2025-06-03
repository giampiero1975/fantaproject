<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlayerFbrefStat extends Model
{
    use HasFactory;
    
    // Specifica il nome della tabella se non segue la convenzione di Laravel (plurale del nome del modello)
    protected $table = 'player_fbref_stats';
    
    // Definisce gli attributi che possono essere assegnati in massa (mass-assignable)
    protected $fillable = [
        'player_id',
        'team_id',
        'season_year',
        'league_name',
        'data_source',
        'position_fbref',    // Stringa originale del ruolo da Fbref (es. "Cen,Att")
        'age_string_fbref',  // Stringa dell'età da Fbref (es. "26-163")
        
        // Statistiche di Gioco Base (corrispondono alla migration e agli header JSON)
        'games_played',      // Corrisponde a "PG"
        'games_started',     // Corrisponde a "Tit"
        'minutes_played',    // Corrisponde a "Min"
        'minutes_per_90',    // Corrisponde a "90 min"
        
        // Rendimento Offensivo
        'goals',             // Corrisponde a "Reti" (deve essere DECIMAL/FLOAT nel DB)
        'assists',           // Corrisponde a "Assist" (deve essere DECIMAL/FLOAT nel DB)
        'non_penalty_goals', // Corrisponde a "R - Rig" (deve essere DECIMAL/FLOAT nel DB)
        'penalties_made',    // Corrisponde a "Rigori"
        'penalties_attempted', // Corrisponde a "Rig T"
        'yellow_cards',      // Corrisponde a "Amm."
        'red_cards',         // Corrisponde a "Esp."
        
        // Expected Stats
        'expected_goals',                 // Corrisponde a "xG"
        'non_penalty_expected_goals',     // Corrisponde a "npxG"
        'expected_assisted_goals',        // Corrisponde a "xAG"
        // 'npxg_plus_xag', // Rimosso dal fillable se non presente nella migration
        
        // Progressione
        'progressive_carries',            // Corrisponde a "PrgC"
        'progressive_passes_completed',   // Corrisponde a "PrgP"
        'progressive_passes_received',    // Corrisponde a "PrgR"
        
        // Statistiche Per 90 Minuti (questi campi non sono sempre diretti da Fbref, possono essere calcolati)
        'goals_per_90',
        'assists_per_90',
        'non_penalty_goals_per_90',
        'expected_goals_per_90',
        'expected_assisted_goals_per_90',
        'non_penalty_expected_goals_per_90',
        // 'npxg_plus_xag_per_90', // Rimosso dal fillable se non presente nella migration
        
        // Statistiche Portiere (da tabella 'difesa_porta')
        'gk_games_played',                // Corrisponde a "PG" nella tabella portiere
        'gk_goals_conceded',              // Corrisponde a "Rs" (Reti Subite)
        'gk_shots_on_target_against',     // Corrisponde a "Tiri in porta"
        'gk_saves',                       // Corrisponde a "Parate"
        'gk_save_percentage',             // Corrisponde a "%Parate"
        'gk_clean_sheets',                // Corrisponde a "Porta Inviolata"
        'gk_cs_percentage',               // Corrisponde a "% PI"
        'gk_penalties_faced',             // Corrisponde a "Rig T" nella tabella portiere
        'gk_penalties_conceded_on_attempt', // Corrisponde a "Rig segnati" nella tabella portiere
        'gk_penalties_saved',             // Corrisponde a "Rig parati" nella tabella portiere
        // 'gk_psxg', 'gk_psxg_net', // Rimossi dal fillable se non presenti nella migration
        
        // Statistiche Tiro (da tabella 'tiri')
        'shots_total',                    // Corrisponde a "Tiri"
        'shots_on_target',                // Non direttamente in JSON come totale, può essere null o calcolato
        
        // Statistiche Difensive (da tabella 'difesa')
        'defense_tackles_attempted',      // Corrisponde a "Cntrs"
        'defense_tackles_won',            // Corrisponde a "Contr. vinti"
        'defense_interceptions',          // Corrisponde a "Int"
        'defense_clearances',             // Corrisponde a "Salvat."
        'defense_blocks_general',         // Corrisponde a "Blocchi" (generico)
        'defense_shots_blocked',          // Corrisponde a "Tiri" (in 'difesa' per blocchi tiri)
        'defense_passes_blocked',         // Corrisponde a "Passaggio" (in 'difesa' per blocchi passaggi)
        
        // Statistiche Varie (misc)
        'misc_fouls_committed',           // Corrisponde a "Falli" (in 'difesa' o 'creazione_azioni_da_gol')
        'misc_fouls_drawn',               // Non trovato un corrispettivo diretto nel JSON fornito
        'misc_own_goals',                 // Non trovato un corrispettivo diretto nel JSON fornito
    ];
    
    // Definisce i tipi di cast per gli attributi
    protected $casts = [
        'season_year' => 'integer',
        'games_played' => 'integer',
        'games_started' => 'integer',
        'minutes_played' => 'integer',
        'minutes_per_90' => 'float', // Usiamo float per compatibilità con i decimali
        'goals' => 'float',          // Importante: da integer a float per i decimali di Fbref
        'assists' => 'float',        // Importante: da integer a float per i decimali di Fbref
        'non_penalty_goals' => 'float', // Importante: da integer a float per i decimali di Fbref
        'penalties_made' => 'integer',
        'penalties_attempted' => 'integer',
        'yellow_cards' => 'integer',
        'red_cards' => 'integer',
        'expected_goals' => 'float',
        'non_penalty_expected_goals' => 'float',
        'expected_assisted_goals' => 'float',
        // 'npxg_plus_xag' => 'float', // Rimosso se non in migration
        'progressive_carries' => 'integer',
        'progressive_passes_completed' => 'integer',
        'progressive_passes_received' => 'integer',
        'goals_per_90' => 'float',
        'assists_per_90' => 'float',
        'non_penalty_goals_per_90' => 'float',
        'expected_goals_per_90' => 'float',
        'expected_assisted_goals_per_90' => 'float',
        'non_penalty_expected_goals_per_90' => 'float',
        // 'npxg_plus_xag_per_90' => 'float', // Rimosso se non in migration
        'gk_games_played' => 'integer',
        'gk_goals_conceded' => 'integer',
        'gk_shots_on_target_against' => 'integer',
        'gk_saves' => 'integer',
        'gk_save_percentage' => 'float',
        'gk_clean_sheets' => 'integer',
        'gk_cs_percentage' => 'float',
        'gk_penalties_faced' => 'integer',
        'gk_penalties_conceded_on_attempt' => 'integer',
        'gk_penalties_saved' => 'integer',
        // 'gk_psxg' => 'float', // Rimosso se non in migration
        // 'gk_psxg_net' => 'float', // Rimosso se non in migration
        'shots_total' => 'integer',
        'shots_on_target' => 'integer',
        'defense_tackles_attempted' => 'integer',
        'defense_tackles_won' => 'integer',
        'defense_interceptions' => 'integer',
        'defense_clearances' => 'integer',
        'defense_blocks_general' => 'integer',
        'defense_shots_blocked' => 'integer',
        'defense_passes_blocked' => 'integer',
        'misc_fouls_committed' => 'integer',
        'misc_fouls_drawn' => 'integer',
        'misc_own_goals' => 'integer',
    ];
    
    /**
     * Relazione con il modello Player.
     */
    public function player()
    {
        return $this->belongsTo(Player::class);
    }
    
    /**
     * Relazione con il modello Team.
     */
    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
