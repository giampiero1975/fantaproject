<?php

namespace App\Http\Controllers;

use App\Models\TeamHistoricalStanding;
use App\Models\Team;
use App\Models\ImportLog;
use App\Models\Player;
use App\Models\PlayerFbrefStat;
use App\Models\HistoricalPlayerStat;
use App\Models\PlayerProjectionSeason;
use App\Services\PlayerStatsApiService;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    protected PlayerStatsApiService $playerStatsApiService;
    
    public function __construct(PlayerStatsApiService $playerStatsApiService)
    {
        $this->playerStatsApiService = $playerStatsApiService;
    }
    
    public function index()
    {
        // Chiama TUTTI i metodi di status e memorizza i risultati in variabili individuali
        $activeTeamsStatus = $this->getActiveTeamsStatus();
        $standingsStatus = $this->getStandingsStatus();
        $rosterStatus = $this->getRosterStatus();
        $auctionTeamsStatus = $this->getAuctionTeamsStatus();
        $tiersStatus = $this->getTiersStatus();
        $playersSyncStatus = $this->getPlayersSyncStatus(); // La nostra nuova fase
        $enrichmentStatus = $this->getEnrichmentStatus();
        $fbrefScrapingStatus = $this->getFbrefScrapingStatus();
        $fbrefProcessingStatus = $this->getFbrefProcessingStatus();
        $otherHistoricalStatsStatus = $this->getOtherHistoricalStatsStatus();
        $projectionsStatus = $this->getProjectionsStatus();
        
        // Passa TUTTE le variabili alla vista usando compact(), come si aspetta la tua vista
        return view('dashboard', compact(
            'activeTeamsStatus',
            'standingsStatus',
            'rosterStatus',
            'auctionTeamsStatus',
            'tiersStatus',
            'playersSyncStatus',
            'enrichmentStatus',
            'fbrefScrapingStatus',
            'fbrefProcessingStatus',
            'otherHistoricalStatsStatus',
            'projectionsStatus'
            ));
    }
    
    // --- METODI PRIVATI PER GLI STATI ---
    
    // NOTA: getVisualAttributesForStatus, getActiveTeamsStatus, getStandingsStatus, etc.
    // rimangono esattamente come nell'ultima versione che ti ho dato,
    // perché sono corretti e non causano errori.
    // Li includo qui per darti il file completo.
    
    private function getVisualAttributesForStatus(string $statusString, $details = ''): array
    {
        $statusKey = strtolower(str_replace([' ', '_'], '-', $statusString));
        $attributes = [
            'status_title' => $statusString,
            'details' => $details,
            'cardBorderClass' => 'border-secondary',
            'headerBgClass'   => 'bg-light',
            'iconClass'       => 'fas fa-question-circle text-secondary',
            'badgeClass'      => 'bg-secondary',
            'showAction'      => true,
        ];
        
        $successStates = ['completato', 'aggiornato', 'calcolato', 'importato', 'generate', 'processato', 'processato-completamente', 'dati-presenti'];
        $warningStates = ['parziale', 'parzialmente-popolato', 'da-completare', 'parzialmente-calcolato', 'parzialmente-generate', 'parzialmente-importato', 'parzialmente-processato', 'parzialmente-aggiornato', 'aggiornamento-disponibile'];
        $dangerStates = ['non-definito', 'non-popolato', 'non-aggiornato', 'non-calcolato', 'non-importato', 'non-avviato', 'non-iniziato', 'non-processato', 'non-generate', 'errore-ultimo-aggiornamento', 'errore-ultimo-calcolo', 'import-fallito', 'processamento-fallito', 'scraping-fallito'];
        
        if (in_array($statusKey, $successStates)) {
            $attributes['cardBorderClass'] = 'border-success';
            $attributes['headerBgClass']   = 'bg-success-subtle text-success-emphasis';
            $attributes['iconClass']       = 'fas fa-check-circle text-success';
            $attributes['badgeClass']      = 'bg-success';
            $attributes['showAction']      = false;
        } elseif (in_array($statusKey, $warningStates)) {
            $attributes['cardBorderClass'] = 'border-warning';
            $attributes['headerBgClass']   = 'bg-warning-subtle text-warning-emphasis';
            $attributes['iconClass']       = 'fas fa-exclamation-triangle text-warning';
            $attributes['badgeClass']      = 'bg-warning text-dark';
        } elseif (in_array($statusKey, $dangerStates)) {
            $attributes['cardBorderClass'] = 'border-danger';
            $attributes['headerBgClass']   = 'bg-danger-subtle text-danger-emphasis';
            $attributes['iconClass']       = 'fas fa-times-circle text-danger';
            $attributes['badgeClass']      = 'bg-danger';
        } elseif ($statusKey === 'non-applicabile') {
            $attributes['cardBorderClass'] = 'border-light';
            $attributes['headerBgClass']   = 'bg-light text-muted';
            $attributes['iconClass']       = 'fas fa-minus-circle text-muted';
            $attributes['badgeClass']      = 'bg-light text-dark';
            $attributes['showAction']      = false;
        }
        
        return $attributes;
    }
    
    private function getActiveTeamsStatus(): array
    {
        $count = Team::whereNotNull('api_football_data_id')->count();
        $statusString = $count > 0 ? 'Dati Presenti' : 'Non Popolato';
        $details = $count > 0 ? "Trovati {$count} team con ID API nel DB." : "Nessun team con ID API trovato.";
        return $this->getVisualAttributesForStatus($statusString, $details);
    }
    
    private function getStandingsStatus(): array
    {
        $count = TeamHistoricalStanding::count();
        $statusString = $count > 0 ? 'Dati Presenti' : 'Non Popolato';
        $details = "Trovati {$count} record di classifiche storiche.";
        return $this->getVisualAttributesForStatus($statusString, $details);
    }
    
    private function getRosterStatus(): array
    {
        $count = Player::count();
        $statusString = $count > 0 ? 'Importato' : 'Non Importato';
        $details = "Trovati {$count} giocatori nel database.";
        return $this->getVisualAttributesForStatus($statusString, $details);
    }
    
    private function getAuctionTeamsStatus(): array
    {
        $nextAuctionSeasonStartYear = date('Y');
        $seasonDisplay = $nextAuctionSeasonStartYear . '-' . substr($nextAuctionSeasonStartYear + 1, -2);
        
        $activeTeamsCount = Team::where('serie_a_team', true)
        ->where('season_year', $nextAuctionSeasonStartYear)
        ->count();
        
        $details = "Le squadre per la Serie A {$seasonDisplay} non sono ancora state impostate.";
        $statusString = 'Non Definito';
        
        if ($activeTeamsCount >= 20) {
            $statusString = 'Completato';
            $details = "Sono state definite {$activeTeamsCount} squadre per la Serie A {$seasonDisplay}.";
        } elseif ($activeTeamsCount > 0) {
            $statusString = 'Parziale';
            $details = "Sono state definite solo {$activeTeamsCount} su 20 squadre per la Serie A {$seasonDisplay}.";
        }
        
        return $this->getVisualAttributesForStatus($statusString, $details);
    }
    
    private function getTiersStatus(): array
    {
        $activeTeamsCount = Team::where('serie_a_team', true)->count();
        if ($activeTeamsCount === 0) {
            return $this->getVisualAttributesForStatus('Non Applicabile', 'Nessuna squadra attiva definita. Completa la Fase 4.');
        }
        
        $calculatedTiersCount = Team::where('serie_a_team', true)->whereNotNull('tier')->count();
        
        $statusString = 'Non Calcolato';
        $details = 'I tier di forza per le squadre attive non sono ancora stati calcolati.';
        
        if ($calculatedTiersCount >= $activeTeamsCount) {
            $statusString = 'Calcolato';
            $details = "Tier calcolati per tutte le {$activeTeamsCount} squadre attive.";
        } elseif ($calculatedTiersCount > 0) {
            $statusString = 'Parziale';
            $details = "Tier calcolati solo per {$calculatedTiersCount} su {$activeTeamsCount} squadre attive.";
        }
        
        return $this->getVisualAttributesForStatus($statusString, $details);
    }
    
    private function getPlayersSyncStatus(): array
    {
        $activeTeamsPlayerCount = Player::whereHas('team', function ($query) {
            $query->where('serie_a_team', true);
        })->count();
        
        $details = "Nessun giocatore risulta associato alle squadre di Serie A attive. Esegui la sincronizzazione.";
        $statusString = 'Non Iniziato';
        
        if ($activeTeamsPlayerCount > 250) {
            $statusString = 'Completato';
            $details = "Trovati {$activeTeamsPlayerCount} giocatori per le squadre di Serie A.";
        } elseif ($activeTeamsPlayerCount > 0) {
            $statusString = 'Parziale';
            $details = "Trovati solo {$activeTeamsPlayerCount} giocatori per le squadre di Serie A. Esegui il comando per completare.";
        }
        
        return $this->getVisualAttributesForStatus($statusString, $details);
    }
    
    private function getEnrichmentStatus(): array
    {
        $totalPlayers = Player::count();
        if ($totalPlayers === 0) {
            return $this->getVisualAttributesForStatus('Non Applicabile', 'Nessun giocatore nel database.');
        }
        
        $missingEnrichment = Player::whereNull('api_football_data_id')->count();
        
        $statusString = 'Da Completare';
        $details = "{$missingEnrichment}/{$totalPlayers} giocatori necessitano di ID API.";
        
        if ($missingEnrichment === 0) {
            $statusString = 'Completato';
            $details = 'Tutti i giocatori sono arricchiti con ID API.';
        }
        
        return $this->getVisualAttributesForStatus($statusString, $details);
    }
    
    private function getFbrefScrapingStatus(): array
    {
        $count = PlayerFbrefStat::count();
        $statusString = $count > 0 ? 'Dati Presenti' : 'Non Iniziato';
        $details = "Trovati {$count} record di statistiche grezze da FBRef.";
        return $this->getVisualAttributesForStatus($statusString, $details);
    }
    
    private function getFbrefProcessingStatus(): array
    {
        $rawCount = PlayerFbrefStat::count();
        if ($rawCount === 0) {
            return $this->getVisualAttributesForStatus('Non Applicabile', 'Nessun dato FBRef grezzo da processare.');
        }
        
        $statusString = 'Non Processato';
        $details = "Trovati {$rawCount} record FBRef grezzi pronti per essere elaborati.";
        
        return $this->getVisualAttributesForStatus($statusString, $details);
    }
    
    private function getOtherHistoricalStatsStatus(): array
    {
        $count = HistoricalPlayerStat::count();
        $statusString = $count > 0 ? 'Dati Presenti' : 'Non Importato';
        $details = "Trovati {$count} record storici totali nel database.";
        
        return $this->getVisualAttributesForStatus($statusString, $details);
    }
    
    private function getProjectionsStatus(): array
    {
        $year = $this->getCurrentSeasonYear();
        $count = PlayerProjectionSeason::where('season_start_year', $year)->count();
        $playerCount = Player::count();
        
        if ($playerCount === 0) {
            return $this->getVisualAttributesForStatus('Non Applicabile', 'Nessun giocatore nel DB per generare proiezioni.');
        }
        
        $statusString = 'Non Generate';
        $details = "Nessuna proiezione generata per la stagione {$year}-" . substr($year + 1, -2) . ".";
        
        if ($count >= $playerCount && $playerCount > 0) {
            $statusString = 'Generate';
            $details = "Proiezioni generate per {$count} giocatori.";
        } elseif ($count > 0) {
            $statusString = 'Parzialmente Generate';
            $details = "Proiezioni generate per {$count} su {$playerCount} giocatori.";
        }
        
        return $this->getVisualAttributesForStatus($statusString, $details);
    }
    
    private function getCurrentSeasonYear(): int
    {
        return date('m') >= 7 ? (int)date('Y') : (int)date('Y') - 1;
    }
}