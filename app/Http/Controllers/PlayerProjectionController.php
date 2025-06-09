<?php

namespace App\Http\Controllers;

use App\Models\PlayerProjectionSeason;
use App\Models\Player; // Per accedere ai dati anagrafici del giocatore
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlayerProjectionController extends Controller
{
    /**
     * Mostra la vista con le proiezioni dei giocatori.
     * Implementa filtri e paginazione.
     */
    public function index(Request $request): View
    {
        $query = PlayerProjectionSeason::query();
        
        // Filtri di esempio:
        // Filtra per stagione
        $season = $request->input('season');
        if ($season) {
            $query->where('season_start_year', $season);
        } else {
            // Se nessuna stagione è specificata, usa l'ultima disponibile o una di default
            $latestSeason = PlayerProjectionSeason::max('season_start_year');
            $season = $latestSeason ?: (date('Y') + 1); // Default all'anno prossimo se non ci sono proiezioni
            $query->where('season_start_year', $season);
        }
        
        // Filtra per ruolo
        $role = $request->input('role');
        if ($role) {
            // Necessario join con la tabella players per filtrare per ruolo
            $query->whereHas('player', function ($q) use ($role) {
                $q->where('role', $role);
            });
        }
        
        // Filtra per nome giocatore
        $playerName = $request->input('player_name');
        if ($playerName) {
            $query->whereHas('player', function ($q) use ($playerName) {
                $q->where('name', 'LIKE', '%' . $playerName . '%');
            });
        }
        
        // Ordina per FantaMedia proiettata di default, decrescente
        $query->orderBy('fanta_mv_proj', 'desc');
        
        // Paginazione
        $projections = $query->paginate(20); // 20 proiezioni per pagina
        
        // Carica la relazione 'player' per ogni proiezione per poter accedere a nome, team, ecc.
        $projections->load('player.team');
        
        // Passa i filtri attivi alla vista per mantenere lo stato del form
        $activeFilters = $request->only(['season', 'role', 'player_name']);
        
        // Recupera tutte le stagioni disponibili per il filtro dropdown
        $availableSeasons = PlayerProjectionSeason::distinct('season_start_year')->pluck('season_start_year');
        $availableRoles = ['P', 'D', 'C', 'A']; // Puoi recuperare dinamicamente da players se preferisci
        
        return view('projections.index', compact('projections', 'activeFilters', 'availableSeasons', 'availableRoles'));
    }
}