<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserLeagueProfile extends Model
{
    use HasFactory;
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'participants',
        'budget',
        'goalkeepers_limit',
        'defenders_limit',
        'midfielders_limit',
        'forwards_limit',
        'scoring_rules',
        'weights_config',
        'age_curves_config',
        'regression_config',
    ];
    
    /**
     * The attributes that should be cast.
     * Questo dice a Laravel di convertire automaticamente le colonne JSON in array PHP
     * quando accedi al modello, e viceversa quando salvi.
     *
     * @var array
     */
    protected $casts = [
        'scoring_rules'     => 'array',
        'weights_config'    => 'array',
        'age_curves_config' => 'array',
        'regression_config' => 'array',
    ];
}