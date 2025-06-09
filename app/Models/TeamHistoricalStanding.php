<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeamHistoricalStanding extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'team_id',
        'season_year',
        'league_name',
        'position',
        'played_games',
        'won',
        'draw',
        'lost',
        'points',
        'goals_for',
        'goals_against',
        'goal_difference',
        // 'avg_points_per_game',
        'data_source',        
    ];
    
    protected $casts = [
        'season_year' => 'integer',
        'position' => 'integer',
        'played_games' => 'integer',
        'won' => 'integer',
        'draw' => 'integer',
        'lost' => 'integer',
        'points' => 'integer',
        'goals_for' => 'integer',
        'goals_against' => 'integer',
        'goal_difference' => 'integer',
    ];
    
    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}