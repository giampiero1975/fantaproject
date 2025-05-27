<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'name',
        'short_name',
        'serie_a_team',
        'tier',
        'logo_url',
    ];
    
    /**
     * Definisce la relazione uno-a-molti con i giocatori (un team ha molti giocatori).
     */
    public function players()
    {
        // Se hai una colonna team_id in players
        // return $this->hasMany(Player::class);
        
        // Se la relazione si basa su team_name in players e name in teams (Opzione A)
        return $this->hasMany(Player::class, 'team_name', 'name');
    }
    
    /**
     * Definisce la relazione uno-a-molti con le statistiche storiche dei giocatori.
     * Questo è più complesso se historical_player_stats non ha un team_id diretto.
     * Per ora, potremmo non definire una relazione diretta qui se team_name è in historical_player_stats.
     * Una relazione più corretta passerebbe attraverso Player.
     */
    // public function historicalPlayerStats()
    // {
    //     return $this->hasManyThrough(HistoricalPlayerStat::class, Player::class, 'team_name', 'player_fanta_platform_id', 'name', 'fanta_platform_id');
    // }
}