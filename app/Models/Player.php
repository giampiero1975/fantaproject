<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // <-- 1. IMPORTA IL TRAIT QUI

class Player extends Model
{
    use HasFactory;
    use SoftDeletes; // <-- 2. USA IL TRAIT QUI DENTRO LA CLASSE
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'fanta_platform_id',
        'name',
        'team_name',
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
    
    // Laravel aggiungerà automaticamente 'deleted_at' all'array $dates
    // del modello quando usi il trait SoftDeletes,
    // così che venga trattato come un oggetto Carbon (data/ora).
}