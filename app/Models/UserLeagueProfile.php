<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserLeagueProfile extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'user_id',
        'league_name',
        'total_budget',
        'num_goalkeepers',
        'num_defenders',
        'num_midfielders',
        'num_attackers',
        'num_participants',
        'scoring_rules',
    ];
    
    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'scoring_rules' => 'array', // Se decidi di salvare le regole come JSON
        'total_budget' => 'integer',
        'num_goalkeepers' => 'integer',
        'num_defenders' => 'integer',
        'num_midfielders' => 'integer',
        'num_attackers' => 'integer',
        'num_participants' => 'integer',
    ];
    
    /**
     * Get the user that owns the league profile.
     * (Opzionale, se user_id è implementato)
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    /**
     * Helper per ottenere il numero totale di giocatori nella rosa.
     */
    public function getTotalPlayersInRosterAttribute(): int
    {
        return $this->num_goalkeepers + $this->num_defenders + $this->num_midfielders + $this->num_attackers;
    }
}
