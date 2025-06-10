<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Player extends Model
{
    use HasFactory, SoftDeletes;
    
    protected $fillable = [
        'fanta_platform_id',
        'api_football_data_id',
        'name',
        'team_name',
        'role',
        'initial_quotation',
        'current_quotation',
        'fvm',
        'date_of_birth',
        'detailed_position',
        'team_id',
    ];
    
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date_of_birth' => 'date',
    ];
    
    public function team()
    {
        return $this->belongsTo(Team::class);
    }
    
    public function historicalStats()
    {
        return $this->hasMany(HistoricalPlayerStat::class, 'player_fanta_platform_id', 'fanta_platform_id');
    }
    
    public function fbrefStats()
    {
        return $this->hasMany(PlayerFbrefStat::class);
    }
}