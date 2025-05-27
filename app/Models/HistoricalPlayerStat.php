<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HistoricalPlayerStat extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'player_fanta_platform_id',
        'season_year',
        'team_id',
        'team_name_for_season',     // O il nome esatto che hai usato nella migrazione
        'role_for_season',          // Ruolo Classic (P, D, C, A)
        'mantra_role_for_season',   // <-- DEVE ESSERE QUI!
        'games_played',
        'avg_rating',
        'fanta_avg_rating',
        'goals_scored',
        'goals_conceded',
        'penalties_saved',
        'penalties_taken',
        'penalties_scored',         // Se la importi
        'penalties_missed',         // Se la importi
        'assists',
        'assists_from_set_piece',   // Se la importi
        'yellow_cards',
        'red_cards',
        'own_goals',
    ];
    
    public function team()
    {
        return $this->belongsTo(Team::class); // Usa la FK team_id
    }
    
    public function player() // Relazione utile
    {
        return $this->belongsTo(Player::class, 'player_fanta_platform_id', 'fanta_platform_id');
    }
}