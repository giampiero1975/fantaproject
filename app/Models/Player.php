<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Player extends Model
{
    use HasFactory;
    use SoftDeletes;
    
    protected $fillable = [
        'fanta_platform_id',
        'name',
        'team_name', // **CORREZIONE: Assicurati che team_name sia qui**
        'team_id',
        'role',
        'initial_quotation',
        'current_quotation',
        'fvm',
    ];
    
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'players';
    
    /**
     * Get the team that owns the player.
     */
    public function team()
    {
        // Questa relazione userà la colonna 'team_id' per default
        // se la foreign key in 'players' è 'team_id'
        return $this->belongsTo(Team::class);
    }
    
    /**
     * Get the historical stats for the player.
     */
    public function historicalStats()
    {
        // Assumendo che 'fanta_platform_id' sia la chiave primaria in 'players' (o una chiave univoca usata per la relazione)
        // e 'player_fanta_platform_id' sia la foreign key in 'historical_player_stats'
        return $this->hasMany(HistoricalPlayerStat::class, 'player_fanta_platform_id', 'fanta_platform_id');
    }
}
