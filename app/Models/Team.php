<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use HasFactory;
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',                   // Nome ufficiale della squadra
        'short_name',             // Nome breve
        'tla',                    // Acronimo di tre lettere (es. JUV)
        'crest_url',              // URL dello stemma (allineato con i dati API di football-data.org)
        'serie_a_team',           // Flag per indicare se è una squadra di Serie A attiva
        'tier',                   // Tier della squadra per le proiezioni
        'fanta_platform_id',      // ID usato dalla piattaforma Fantacalcio (es. IDGazzetta)
        'api_football_data_id',   // ID usato da football-data.org
        'league_code',            // Codice della lega attuale della squadra (es. SA, SB) da football-data.org
        'season_year',
        
    ];
    
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'serie_a_team' => 'boolean',
        'tier' => 'integer',
        'fanta_platform_id' => 'integer',
        'api_football_data_id' => 'integer',
        'season_year',
    ];
    
    /**
     * Definisce la relazione uno-a-molti con i giocatori (un team ha molti giocatori).
     * Questa relazione si basa sul fatto che la tabella 'players' abbia una colonna 'team_id'
     * che è una foreign key verso l'id di questa tabella 'teams'.
     * Se usi 'team_name' come chiave, la relazione è diversa e meno standard.
     * Per ora, presumiamo un 'team_id' in 'players'.
     */
    public function players()
    {
        // Presumendo che 'players.team_id' sia la foreign key
        return $this->hasMany(Player::class);
        
        // Se la relazione è ancora basata su team_name (sconsigliato per performance e integrità):
        // return $this->hasMany(Player::class, 'team_name', 'name');
    }
    
    /**
     * Definisce la relazione uno-a-molti con le classifiche storiche della squadra.
     */
    public function historicalStandings()
    {
        return $this->hasMany(TeamHistoricalStanding::class);
    }
    
    /**
     * Definisce la relazione uno-a-molti con le statistiche FbRef dei giocatori di questa squadra.
     * Nota: PlayerFbrefStat ha un team_id.
     */
    public function playerFbrefStats()
    {
        return $this->hasMany(PlayerFbrefStat::class);
    }
}