<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlayerProjectionSeason extends Model
{
    use HasFactory;
    
    protected $table = 'player_projections_season'; // Specifica il nome della tabella
    
    protected $fillable = [
        'player_fanta_platform_id',
        'season_start_year',
        'avg_rating_proj',
        'fanta_mv_proj',
        'games_played_proj',
        'total_fanta_points_proj',
        'goals_scored_proj',
        'assists_proj',
        'yellow_cards_proj',
        'red_cards_proj',
        'own_goals_proj',
        'penalties_taken_proj',
        'penalties_scored_proj',
        'penalties_missed_proj',
        'goals_conceded_proj',
        'penalties_saved_proj',
    ];
    
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'season_start_year' => 'integer',
        'avg_rating_proj' => 'float',
        'fanta_mv_proj' => 'float',
        'games_played_proj' => 'integer',
        'total_fanta_points_proj' => 'float',
        'goals_scored_proj' => 'float',
        'assists_proj' => 'float',
        'yellow_cards_proj' => 'float',
        'red_cards_proj' => 'float',
        'own_goals_proj' => 'float',
        'penalties_taken_proj' => 'float',
        'penalties_scored_proj' => 'float',
        'penalties_missed_proj' => 'float',
        'goals_conceded_proj' => 'float',
        'penalties_saved_proj' => 'float',
    ];
    
    // Relazione opzionale con il modello Player se si vuole accedere ai dati del giocatore
    public function player()
    {
        return $this->belongsTo(Player::class, 'player_fanta_platform_id', 'fanta_platform_id');
    }
}