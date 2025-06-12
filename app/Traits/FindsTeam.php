<?php

namespace App\Traits;

use App\Models\Team;
use Illuminate\Support\Collection;

trait FindsTeam
{
    /**
     * La collection di tutte le squadre, caricata una sola volta per efficienza.
     * @var Collection
     */
    protected Collection $allTeams;
    
    /**
     * Carica e memorizza la collection di tutte le squadre.
     */
    protected function preloadTeams(): void
    {
        $this->allTeams = Team::all(['id', 'name', 'short_name']);
    }
    
    /**
     * Cerca un team usando diverse strategie di matching.
     *
     * @param string $nameInStats Il nome della squadra dallo storico.
     * @return int|null L'ID del team trovato, o null.
     */
    protected function findTeamIdByName(string $nameInStats): ?int
    {
        if (empty($this->allTeams)) {
            $this->preloadTeams();
        }
        
        // Strategia 1: Match esatto sul nome breve (short_name)
        $team = $this->allTeams->firstWhere('short_name', $nameInStats);
        if ($team) {
            return $team->id;
        }
        
        // Strategia 2: Match esatto sul nome completo (name)
        $team = $this->allTeams->firstWhere('name', $nameInStats);
        if ($team) {
            return $team->id;
        }
        
        // Strategia 3: Match non sensibile a maiuscole/minuscole (case-insensitive) sul nome breve
        $team = $this->allTeams->first(function ($team) use ($nameInStats) {
            return strcasecmp($team->short_name, $nameInStats) === 0;
        });
            if ($team) {
                return $team->id;
            }
            
            // Strategia 4: Match non sensibile a maiuscole/minuscole sul nome completo
            $team = $this->allTeams->first(function ($team) use ($nameInStats) {
                return strcasecmp($team->name, $nameInStats) === 0;
            });
                if ($team) {
                    return $team->id;
                }
                
                return null;
    }
}