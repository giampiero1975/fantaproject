<?php

namespace App\Traits;

use App\Models\Team;
use Illuminate\Support\Facades\Log;

trait FindsTeam
{
    private array $teamNameIdMap = [];
    private array $teamShortNameIdMap = [];
    
    /**
     * Carica le mappe dei nomi dei team per ricerche veloci su corrispondenze esatte.
     */
    public function preloadTeams()
    {
        $teams = Team::all(['id', 'name', 'short_name']);
        $this->teamNameIdMap = $teams->pluck('id', 'name')->toArray();
        $this->teamShortNameIdMap = $teams->pluck('id', 'short_name')->toArray();
    }
    
    /**
     * Trova l'ID di un team con una logica a più livelli:
     * 1. Corrispondenza esatta (veloce, da mappa precaricata).
     * 2. Corrispondenza "Contains" (super flessibile, da DB).
     *
     * @param string|null $name Il nome del team dal file di import.
     * @return int|null
     */
    public function findTeamIdByName(?string $name): ?int
    {
        if ($name === null || trim($name) === '') {
            return null;
        }
        
        $trimmedName = trim($name);
        
        // Livello 1: Ricerca per corrispondenza esatta (veloce)
        if (isset($this->teamNameIdMap[$trimmedName])) {
            return $this->teamNameIdMap[$trimmedName];
        }
        if (isset($this->teamShortNameIdMap[$trimmedName])) {
            return $this->teamShortNameIdMap[$trimmedName];
        }
        
        // --- Livello 2: Ricerca "CONTAINS" (la più flessibile) ---
        // Questo troverà "AC Pisa 1909" quando il file riporta "Pisa".
        $team = Team::where('name', 'LIKE', '%' . $trimmedName . '%')
        ->orWhere('short_name', 'LIKE', '%' . $trimmedName . '%')
        ->first();
        
        if ($team) {
            return $team->id;
        }
        
        return null;
    }
}