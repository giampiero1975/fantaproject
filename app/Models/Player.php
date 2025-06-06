<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Assicurati sia usato se la tua tabella lo supporta

class Player extends Model
{
    use HasFactory, SoftDeletes; // SoftDeletes era già presente
    
    protected $fillable = [
        'fanta_platform_id',
        'name',
        'team_name',         // Mantienilo se lo usi ancora per il matching iniziale o display
        'team_id',
        'role',
        'initial_quotation', // Corretto da 'initial_value' se il nome colonna è questo
        'current_quotation', // Corretto da 'current_value' se il nome colonna è questo
        'fvm',
        'date_of_birth',        // NUOVO
        'detailed_position',    // NUOVO
        'api_football_data_id', // NUOVO
        'api_football_data_team_id',
        'avg_rating_proj',
        'fanta_mv_proj',
        'games_played_proj',
        'total_fanta_points_proj',
    ];
    
    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'date_of_birth' => 'date', // NUOVO
        // Assicurati che gli altri cast siano corretti per le tue colonne
        'initial_quotation' => 'integer',
        'current_quotation' => 'integer',
        'fvm' => 'integer',
        'avg_rating_proj' => 'float',
        'fanta_mv_proj' => 'float',
        'games_played_proj' => 'integer',
        'total_fanta_points_proj' => 'float',
    ];
    
    /**
     * Get the team that owns the player.
     */
    public function team()
    {
        return $this->belongsTo(Team::class);
    }
    
    /**
     * Get the historical stats for the player.
     */
    public function historicalStats()
    {
        return $this->hasMany(HistoricalPlayerStat::class, 'player_fanta_platform_id', 'fanta_platform_id');
    }
}