<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlayerFbrefStat extends Model
{
    use HasFactory;
    
    protected $table = 'player_fbref_stats';
    
    protected $fillable = [
        'player_id', 'team_id', 'season_year', 'league_name', 'data_source',
        'position_fbref', 'age_string_fbref',
        'games_played', 'games_started', 'minutes_played', 'minutes_per_90',
        'goals_total_fbref', 'assists_total_fbref', 'non_penalty_goals', 'penalties_made', 'penalties_attempted',
        'yellow_cards_fbref', 'red_cards_fbref',
        'expected_goals', 'non_penalty_expected_goals', 'expected_assisted_goals', 'npxg_plus_xag',
        'progressive_carries', 'progressive_passes_completed', 'progressive_passes_received',
        'goals_per_90', 'assists_per_90', 'non_penalty_goals_per_90',
        'expected_goals_per_90', 'expected_assisted_goals_per_90',
        'non_penalty_expected_goals_per_90', 'npxg_plus_xag_per_90',
        'gk_games_played', 'gk_goals_conceded', 'gk_shots_on_target_against', 'gk_saves',
        'gk_save_percentage', 'gk_clean_sheets', 'gk_cs_percentage',
        'gk_penalties_faced', 'gk_penalties_conceded_on_attempt', 'gk_penalties_saved',
        'gk_psxg', 'gk_psxg_net', // Assumendo che tu li abbia aggiunti nella migration
        'shots_total', 'shots_on_target',
        'defense_tackles_attempted', 'defense_tackles_won', 'defense_interceptions',
        'defense_clearances', 'defense_blocks_general', 'defense_shots_blocked', 'defense_passes_blocked',
        'misc_fouls_committed', 'misc_fouls_drawn', 'misc_own_goals',
        // Aggiungi qui tutte le altre colonne che hai definito nella migration e che vuoi siano mass-assignable
    ];
    
    protected $casts = [
        'minutes_per_90' => 'decimal:2',
        'expected_goals' => 'decimal:2',
        'non_penalty_expected_goals' => 'decimal:2',
        'expected_assisted_goals' => 'decimal:2',
        'npxg_plus_xag' => 'decimal:2',
        'goals_per_90' => 'decimal:4',
        'assists_per_90' => 'decimal:4',
        'non_penalty_goals_per_90' => 'decimal:4',
        'expected_goals_per_90' => 'decimal:4',
        'expected_assisted_goals_per_90' => 'decimal:4',
        'non_penalty_expected_goals_per_90' => 'decimal:4',
        'npxg_plus_xag_per_90' => 'decimal:4',
        'gk_save_percentage' => 'decimal:4',
        'gk_cs_percentage' => 'decimal:4',
        'gk_psxg' => 'decimal:2',
        'gk_psxg_net' => 'decimal:2',
        // Aggiungi cast per altri decimali se necessario
    ];
    
    public function player()
    {
        return $this->belongsTo(Player::class);
    }
    
    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}