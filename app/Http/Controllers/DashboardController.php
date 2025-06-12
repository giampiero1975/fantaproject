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
    
    // In app/Http/Controllers/DashboardController.php
    
    private function getStandingsStatus(): array
    {
        // Recupera le impostazioni dal file di configurazione
        $lookbackSeasons = config('team_tiering_settings.lookback_seasons_for_tiering', 3);
        
        // 1. Identifica le squadre di Serie A attive
        $activeSerieATeams = Team::where('serie_a_team', true)->get();
        $activeTeamCount = $activeSerieATeams->count();
        
        if ($activeTeamCount < 20) {
            return $this->getVisualAttributesForStatus(
                'Non Applicabile',
                'Definire prima le 20 squadre di Serie A (Fase 4) per controllare lo storico.'
                );
        }
        
        // 2. Conta quante delle squadre attive hanno abbastanza dati storici
        $teamsWithSufficientHistory = 0;
        foreach ($activeSerieATeams as $team) {
            $historyCount = TeamHistoricalStanding::where('team_id', $team->id)->count();
            if ($historyCount >= $lookbackSeasons) {
                $teamsWithSufficientHistory++;
            }
        }
        
        // 3. Determina lo stato basandosi sul nuovo controllo
        $statusString = 'Non Popolato';
        $details = "Lo storico delle classifiche non è ancora stato popolato.";
        
        if ($teamsWithSufficientHistory >= $activeTeamCount) {
            $statusString = 'Completato';
            $details = "Trovato storico sufficiente (almeno {$lookbackSeasons} stagioni) per tutte le {$activeTeamCount} squadre di Serie A.";
        } elseif ($teamsWithSufficientHistory > 0) {
            $statusString = 'Parziale';
            $details = "Solo {$teamsWithSufficientHistory} su {$activeTeamCount} squadre di Serie A hanno uno storico sufficiente (almeno {$lookbackSeasons} stagioni). Si consiglia di completare il popolamento.";
        }
        
        $visualAttributes = $this->getVisualAttributesForStatus($statusString, $details);
        
        // Mostra sempre l'azione se lo stato non è 'Completato'
        if ($statusString !== 'Completato') {
            $visualAttributes['showAction'] = true;
        }
        
        return $visualAttributes;
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
        // Creiamo una query di base per i soli giocatori di Serie A
        $query = Player::whereHas('team', function ($q) {
            $q->where('serie_a_team', true);
        });
            
            // Usiamo la query per contare il totale dei giocatori di Serie A
            $totalSerieAPlayers = $query->clone()->count();
            
            if ($totalSerieAPlayers === 0) {
                return $this->getVisualAttributesForStatus('Non Applicabile', 'Nessun giocatore trovato per le squadre di Serie A attive.');
            }
            
            // Dalla query dei giocatori di A, contiamo quanti hanno l'ID API mancante
            $missingEnrichment = $query->clone()->whereNull('api_football_data_id')->count();
            
            $statusString = 'Da Completare';
            // Il messaggio ora è molto più preciso
            $details = "{$missingEnrichment} su {$totalSerieAPlayers} giocatori di Serie A necessitano di ID API.";
            
            if ($missingEnrichment === 0) {
                $statusString = 'Completato';
                $details = "Tutti i {$totalSerieAPlayers} giocatori di Serie A sono stati arricchiti con successo.";
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
        // Leggiamo dalla config quante stagioni storiche ci servono come minimo
        $requiredSeasons = config('team_tiering_settings.lookback_seasons_for_tiering', 3);
        
        // Prendiamo solo i giocatori delle squadre di Serie A attive
        $query = Player::whereHas('team', function ($q) {
            $q->where('serie_a_team', true);
        });

        $totalSerieAPlayers = $query->clone()->count();
            
            if ($totalSerieAPlayers === 0) {
                return $this->getVisualAttributesForStatus('Non Applicabile', 'Nessun giocatore trovato per le squadre di Serie A.');
            }
            
            // Contiamo quanti di loro hanno uno storico INSUFFICIENTE
            // Usiamo withCount per contare i record relazionati in modo efficiente
            $incompletePlayersCount = $query->clone()->withCount('historicalStats')
            ->get()
            ->filter(function ($player) use ($requiredSeasons) {
                return $player->historical_stats_count < $requiredSeasons;
            })
            ->count();
            
            $statusString = 'Da Completare';
            $details = "{$incompletePlayersCount} su {$totalSerieAPlayers} giocatori di Serie A hanno uno storico dati insufficiente (meno di {$requiredSeasons} stagioni).";
            
            if ($incompletePlayersCount === 0) {
                $statusString = 'Completato';
                $details = "Tutti i {$totalSerieAPlayers} giocatori di Serie A hanno uno storico dati sufficiente per le proiezioni.";
            }
            
            $attributes = $this->getVisualAttributesForStatus($statusString, $details);
            
            // Mostriamo sempre l'azione se lo stato non è 'Completato'
            if ($statusString !== 'Completato') {
                $attributes['showAction'] = true;
            }
            
            return $attributes;
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
    
    // In app/Http/Controllers/DashboardController.php
    
    public function showHistoricalCoverage()
    {
        // Recupera le impostazioni dal file di configurazione
        $lookbackSeasons = config('team_tiering_settings.lookback_seasons_for_tiering', 3);
        $startYear = date('Y') - 1; // Ultima stagione conclusa
        $requiredSeasons = range($startYear, $startYear - $lookbackSeasons + 1);
        
        // Recupera le 20 squadre di Serie A attive
        $activeTeams = \App\Models\Team::where('serie_a_team', true)->orderBy('name')->get();
        
        $coverageData = [];
        foreach ($activeTeams as $team) {
            // Per ogni squadra, prendi lo storico disponibile
            $historicalData = \App\Models\TeamHistoricalStanding::where('team_id', $team->id)
            ->whereIn('season_year', $requiredSeasons)
            ->pluck('season_year')
            ->all();
            
            $missingSeasons = array_diff($requiredSeasons, $historicalData);
            
            $coverageData[] = [
                'team_name' => $team->name,
                'required_seasons' => $requiredSeasons,
                'available_seasons' => $historicalData,
                'missing_seasons' => array_values($missingSeasons),
                'is_complete' => empty($missingSeasons),
            ];
        }
        
        return view('dashboard.historical_coverage', [
            'coverageData' => $coverageData,
            'requiredSeasons' => $requiredSeasons
        ]);
    }
    
    public function showUploadForm()
    {
        // Recupera gli ultimi log di importazione per questa tipologia
        $logs = ImportLog::where('import_type', 'like', 'historical_stats_%')
        ->orWhere('import_type', 'statistiche_storiche')
        ->latest()
        ->take(10)
        ->get();
        
        return view('uploads.historical_stats', ['logs' => $logs]);
    }
    
    public function handleUpload(Request $request)
    {
        $request->validate([
            'stats_file' => 'required|file|mimes:xlsx,csv',
            'import_type' => 'required|string|in:tutti_historical_stats,player_season_stats',
        ]);
        
        $file = $request->file('stats_file');
        $importType = $request->input('import_type');
        $originalFilename = $file->getClientOriginalName();
        
        $log = ImportLog::create([
            'import_type' => $importType,
            'status' => 'in_corso',
            'original_file_name' => $originalFilename,
            'details' => 'Avvio importazione sincrona.'
        ]);
        
        try {
            $this->info("Avvio importazione Sincrona per il file: " . $originalFilename);
            
            // Determina quale classe di import usare
            $importer = null;
            if ($importType === 'tutti_historical_stats') {
                $importer = new TuttiHistoricalStatsImport();
            } elseif ($importType === 'player_season_stats') {
                $importer = new PlayerSeasonStatsImport();
            }
            
            // Esegui l'importazione direttamente
            Excel::import($importer, $file);
            
            // Se arriva qui, l'import è andato a buon fine
            $log->update(['status' => 'successo', 'details' => 'Importazione completata con successo.']);
            
            return redirect()->back()->with('success', "File '{$originalFilename}' importato con successo!");
            
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            $failures = $e->failures();
            $errorDetails = "Errori di validazione: ";
            foreach ($failures as $failure) {
                $errorDetails .= "Riga {$failure->row()}: {$failure->errors()[0]}. ";
            }
            $log->update(['status' => 'fallito', 'details' => $errorDetails]);
            Log::error("Errore di validazione durante l'importazione del file {$originalFilename}", ['failures' => $failures]);
            return redirect()->back()->with('error', 'Si sono verificati errori di validazione. Controlla i log.');
            
        } catch (\Exception $e) {
            $log->update(['status' => 'fallito', 'details' => $e->getMessage()]);
            Log::error("Errore durante l'importazione Sincrona del file {$originalFilename}: " . $e->getMessage());
            return redirect()->back()->with('error', 'Si è verificato un errore imprevisto. Controlla i log.');
        }
    }
    
    // Aggiungo questo metodo helper per loggare in console se necessario
    private function info($message)
    {
        if (app()->runningInConsole()) {
            $this->info($message);
        }
    }
    
    public function showPlayerHistoryCoverage()
    {
        // Legge dalla configurazione quante stagioni guardare indietro
        $lookbackSeasons = config('team_tiering_settings.lookback_seasons_for_tiering', 3);
        
        // Calcola dinamicamente gli anni delle stagioni richieste
        $lastCompletedSeason = date('m') >= 7 ? (int)date('Y') : (int)date('Y') - 1;
        $requiredYears = range($lastCompletedSeason - $lookbackSeasons + 1, $lastCompletedSeason);
        rsort($requiredYears); // Ordina dall'anno più recente al più vecchio
        
        // Recupera le squadre di Serie A attive
        $activeTeams = Team::where('serie_a_team', true)->orderBy('name')->get();
        
        $coverageData = [];
        foreach ($activeTeams as $team) {
            // Per ogni squadra, controlliamo quali anni sono presenti nel suo storico classifiche
            // Questo popola la tabella come da immagine
            $availableYears = TeamHistoricalStanding::where('team_id', $team->id)
            ->whereIn('season_year', $requiredYears)
            ->pluck('season_year')
            ->all();
            
            $coverageData[] = [
                'team_name' => $team->name,
                'available_seasons' => $availableYears,
                'is_complete' => count(array_diff($requiredYears, $availableYears)) === 0,
            ];
        }
        
        // Importante: usiamo la view 'player_history_coverage' ma le passiamo i dati delle squadre
        return view('dashboard.player_history_coverage', [
            'coverageData' => $coverageData,
            'requiredSeasons' => $requiredYears
        ]);
    }
}