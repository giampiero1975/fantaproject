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

class DashboardController extends Controller
{
    protected PlayerStatsApiService $playerStatsApiService;
    
    public function __construct(PlayerStatsApiService $playerStatsApiService)
    {
        $this->playerStatsApiService = $playerStatsApiService;
    }
    
    public function index(): View
    {
        $data = [
            'standingsStatus'         => $this->getStandingsStatus(),
            'activeTeamsStatus'       => $this->getActiveTeamsStatus(), // Questo ora riflette lo stato di teams:set-active-league
            'tiersStatus'             => $this->getTiersStatus(),
            'rosterStatus'            => $this->getRosterStatus(),
            'enrichmentStatus'        => $this->getEnrichmentStatus(),
            'fbrefScrapingStatus'     => $this->getFbrefScrapingStatus(),
            'fbrefProcessingStatus'   => $this->getFbrefProcessingStatus(),
            'otherHistoricalStatsStatus' => $this->getOtherHistoricalStatsStatus(),
            'projectionsStatus'       => $this->getProjectionsStatus(),
        ];
        return view('dashboard', $data);
    }
    
    private function getVisualAttributesForStatus(string $statusString, $additionalData = []): array
    {
        $statusKey = strtolower(str_replace(' ', '-', $statusString));
        $attributes = [
            'cardBorderClass' => 'border-secondary',
            'headerBgClass'   => 'bg-light',
            'iconClass'       => 'fas fa-question-circle text-secondary',
            'badgeClass'      => 'bg-secondary',
            'showAction'      => true,
        ];
        switch ($statusKey) {
            case 'completato':
            case 'aggiornato':
            case 'calcolato':
            case 'importato':
            case 'generate':
            case 'processato':
            case 'processato-completamente':
            case 'dati-presenti':
                $attributes['cardBorderClass'] = 'border-success';
                $attributes['headerBgClass']   = 'bg-success-subtle text-success-emphasis';
                $attributes['iconClass']       = 'fas fa-check-circle text-success';
                $attributes['badgeClass']      = 'bg-success';
                $attributes['showAction']      = false;
                break;
            case 'parzialmente-popolato':
            case 'da-completare':
            case 'parzialmente-calcolato':
            case 'parzialmente-generate':
            case 'parzialmente-importato':
            case 'parzialmente-processato':
            case 'parzialmente-aggiornato':
                $attributes['cardBorderClass'] = 'border-warning';
                $attributes['headerBgClass']   = 'bg-warning-subtle text-warning-emphasis';
                $attributes['iconClass']       = 'fas fa-exclamation-triangle text-warning';
                $attributes['badgeClass']      = 'bg-warning text-dark';
                break;
            case 'non-popolato':
            case 'non-aggiornato':
            case 'non-calcolato':
            case 'non-importato':
            case 'non-avviato':
            case 'non-processato':
            case 'non-generate':
                $attributes['cardBorderClass'] = 'border-danger';
                $attributes['headerBgClass']   = 'bg-danger-subtle text-danger-emphasis';
                $attributes['iconClass']       = 'fas fa-times-circle text-danger';
                $attributes['badgeClass']      = 'bg-danger';
                break;
            case 'errore-ultimo-aggiornamento':
            case 'errore-ultimo-calcolo':
            case 'import-fallito':
            case 'processamento-fallito':
            case 'scraping-fallito':
                $attributes['cardBorderClass'] = 'border-danger';
                $attributes['headerBgClass']   = 'bg-danger-subtle text-danger-emphasis';
                $attributes['iconClass']       = 'fas fa-bomb text-danger';
                $attributes['badgeClass']      = 'bg-danger';
                break;
            case 'non-applicabile':
                $attributes['cardBorderClass'] = 'border-light';
                $attributes['headerBgClass']   = 'bg-light text-muted';
                $attributes['iconClass']       = 'fas fa-minus-circle text-muted';
                $attributes['badgeClass']      = 'bg-light text-dark';
                $attributes['showAction'] = false;
                break;
            case 'aggiornamento-disponibile':
                $attributes['cardBorderClass'] = 'border-info';
                $attributes['headerBgClass']   = 'bg-info-subtle text-info-emphasis';
                $attributes['iconClass']       = 'fas fa-sync-alt text-info';
                $attributes['badgeClass']      = 'bg-info';
                break;
        }
        return $attributes;
    }
    
    private function getStandingsStatus(): array
    {
        $count = TeamHistoricalStanding::count();
        $latestSeasonInDb = TeamHistoricalStanding::max('season_year');
        $lastImport = ImportLog::whereIn('import_type', ['standings_csv_import', 'standings_api_fetch'])
        ->latest('created_at')
        ->first();
        
        $details = "Trovati {$count} record. ";
        $latestSeasonInDb ? $details .= "Ultima stagione in DB: {$latestSeasonInDb}. " : $details .= "Nessuna classifica storica trovata. ";
        $lastImportDate = 'N/A';
        $statusString = 'Non popolato';
        
        if ($lastImport) {
            $lastImportDate = Carbon::parse($lastImport->created_at)->format('d/m/Y H:i');
            $details .= "Ultimo tentativo import: " . $lastImportDate . " (" . Str::limit($lastImport->original_file_name ?? $lastImport->import_type, 30) . ", Stato: {$lastImport->status}).";
        }
        
        if ($count > 0) {
            $serieACount = TeamHistoricalStanding::where('league_name', 'Serie A')->distinct('season_year')->count('season_year');
            $statusString = ($serieACount >= 4) ? 'Completato' : 'Parzialmente popolato';
            if ($lastImport && $lastImport->status !== 'successo') $statusString = 'Import Fallito';
        } elseif ($lastImport && $lastImport->status !== 'successo') {
            $statusString = 'Import Fallito';
        }
        
        $currentYear = date('Y');
        $targetSeasonForAction = $currentYear;
        if (Carbon::now()->month >= 7) {
            $targetSeasonForAction = $currentYear + 1;
        }
        
        $visualAttributes = $this->getVisualAttributesForStatus($statusString);
        return array_merge([
            'status_title' => $statusString,
            'details' => $details,
            'count' => $count,
            'last_import_date' => $lastImportDate,
            'target_season_year' => $targetSeasonForAction, // <-- Questa riga è già presente
        ], $visualAttributes);
    }
    
    private function getActiveTeamsStatus(): array
    {
        $leagueCode = 'SA';
        $currentYear = date('Y');
        
        $apiProvidedSeasonYear = null;
        $apiProvidedSeasonDisplay = 'N/A';
        
        foreach ([$currentYear, $currentYear - 1] as $yearToTry) {
            $apiTeamsData = $this->playerStatsApiService->getTeamsForCompetitionAndSeason($leagueCode, $yearToTry);
            if ($apiTeamsData && isset($apiTeamsData['season']['startDate']) && isset($apiTeamsData['teams']) && !empty($apiTeamsData['teams'])) {
                $apiProvidedSeasonYear = (int) Carbon::parse($apiTeamsData['season']['startDate'])->format('Y');
                $apiProvidedSeasonDisplay = $apiProvidedSeasonYear . '-' . substr($apiProvidedSeasonYear + 1, 2);
                break;
            }
        }
        
        $dbLatestMappedSeasonYear = Team::whereNotNull('api_football_data_id')->max('season_year');
        $dbMappedTeamsCount = 0;
        $dbLatestMappedSeasonDisplay = 'N/A';
        if ($dbLatestMappedSeasonYear) {
            $dbMappedTeamsCount = Team::whereNotNull('api_football_data_id')
            ->where('season_year', $dbLatestMappedSeasonYear)
            ->count();
            $dbLatestMappedSeasonDisplay = $dbLatestMappedSeasonYear . '-' . substr($dbLatestMappedSeasonYear + 1, 2);
        }
        
        $lastRun = ImportLog::where('import_type', 'set_active_teams_sa')
        ->latest('created_at')
        ->first();
        
        $details = '';
        $statusString = 'Non aggiornato';
        $lastRunDate = 'N/A';
        $actionSeasonYear = null;
        
        if (!$dbLatestMappedSeasonYear && !$apiProvidedSeasonYear) {
            $statusString = 'Non applicabile';
            $details = 'Nessun team mappato nel DB e nessuna stagione API disponibile.';
        } elseif (!$dbLatestMappedSeasonYear && $apiProvidedSeasonYear) {
            $statusString = 'Non popolato';
            $actionSeasonYear = $apiProvidedSeasonYear;
            $details = "Stagione API {$apiProvidedSeasonDisplay} disponibile per l'importazione. Clicca per Popolare.";
        } elseif ($dbLatestMappedSeasonYear < $apiProvidedSeasonYear) {
            $statusString = 'Aggiornamento Disponibile';
            $actionSeasonYear = $apiProvidedSeasonYear;
            $details = "Nuova stagione API ({$apiProvidedSeasonDisplay}) disponibile. Attualmente in DB: {$dbLatestMappedSeasonDisplay}. Clicca per Aggiornare.";
        } elseif ($dbLatestMappedSeasonYear === $apiProvidedSeasonYear) {
            if ($dbMappedTeamsCount >= 20) {
                $statusString = 'Completato';
                $actionSeasonYear = $apiProvidedSeasonYear;
                $details = "Team API mappati per la stagione {$apiProvidedSeasonDisplay}.";
            } else {
                $statusString = 'Parzialmente aggiornato';
                $actionSeasonYear = $apiProvidedSeasonYear;
                $details = "Trovati {$dbMappedTeamsCount}/20 team con ID API per la stagione {$apiProvidedSeasonDisplay}. Clicca per Completare.";
            }
        } else {
            $statusString = 'Completato';
            $actionSeasonYear = $dbLatestMappedSeasonYear;
            $details = "Team API mappati per la stagione {$dbLatestMappedSeasonDisplay}. (Potrebbe non essere la stagione più recente API).";
        }
        
        if ($lastRun) {
            $lastRunDate = Carbon::parse($lastRun->created_at)->format('d/m/Y H:i');
            $statusLastRun = $lastRun->status === 'successo' ? 'successo' : 'fallito';
            $details .= " Ultimo avvio: {$lastRunDate} (Stato: {$statusLastRun}).";
            if ($lastRun->status !== 'successo' && $statusString !== 'Completato' && $statusString !== 'Aggiornamento Disponibile' && $statusString !== 'Non popolato' && $statusString !== 'Parzialmente aggiornato') {
                $statusString = 'Import Fallito';
            }
        }
        
        $visualAttributes = $this->getVisualAttributesForStatus($statusString);
        if ($actionSeasonYear && $statusString !== 'Completato' && $statusString !== 'Non applicabile' && $statusString !== 'Import Fallito') {
            $visualAttributes['showAction'] = true;
            $visualAttributes['target_season_year'] = $actionSeasonYear; // <-- Questa riga è già presente
        } else {
            $visualAttributes['showAction'] = false;
        }
        
        return array_merge([
            'status_title' => $statusString,
            'details' => $details,
            'last_run_date' => $lastRunDate
        ], $visualAttributes);
    }
    
    private function getTiersStatus(): array
    {
        $activeSerieATeams = Team::where('serie_a_team', true)->get();
        $totalActiveTeams = $activeSerieATeams->count();
        $teamsWithTier = $activeSerieATeams->whereNotNull('tier')->where('tier', '>', 0)->count();
        $lastRun = ImportLog::where('import_type', 'update_team_tiers')
        ->latest('created_at')
        ->first();
        $details = '';
        $statusString = 'Non calcolato';
        $lastRunDate = 'N/A';
        
        if ($totalActiveTeams === 0) {
            $statusString = 'Non applicabile';
            $details = "Nessuna squadra attiva definita per calcolare i tier.";
        } elseif ($teamsWithTier === $totalActiveTeams && $totalActiveTeams > 0) {
            $statusString = 'Calcolato';
            $details = "Tier calcolati per tutte le {$totalActiveTeams} squadre attive.";
        } elseif ($teamsWithTier > 0) {
            $statusString = 'Parzialmente calcolato';
            $details = "Tier calcolati per {$teamsWithTier}/{$totalActiveTeams} squadre attive.";
        } else {
            $details = "Nessun tier calcolato per le {$totalActiveTeams} squadre attive.";
        }
        
        if ($lastRun) {
            $lastRunDate = Carbon::parse($lastRun->created_at)->format('d/m/Y H:i');
            $statusLastRun = $lastRun->status === 'successo' ? 'successo' : 'fallito';
            $details .= " Ultimo calcolo: {$lastRunDate} (Stato: {$statusLastRun}).";
            if ($lastRun->status !== 'successo' && $statusString !== 'Calcolato' && $statusString !== 'Non applicabile') {
                $statusString = 'Errore Ultimo Calcolo';
            }
        }
        
        $currentYear = date('Y');
        $targetSeasonForAction = $currentYear;
        if (Carbon::now()->month >= 7) {
            $targetSeasonForAction = $currentYear + 1;
        }
        
        $visualAttributes = $this->getVisualAttributesForStatus($statusString);
        return array_merge([
            'status_title' => $statusString,
            'details' => $details,
            'last_run_date' => $lastRunDate,
            'target_season_year' => $targetSeasonForAction, // <-- Questa riga è già presente
        ], $visualAttributes);
    }
    
    private function getRosterStatus(): array
    {
        $lastImport = ImportLog::where('import_type', 'roster_quotazioni')->latest()->first();
        $playerCount = Player::count();
        $statusString = 'Non importato';
        $details = 'Nessun roster importato.';
        $lastImportDate = 'N/A';
        
        if ($playerCount > 0) {
            $statusString = 'Importato';
            $details = "Trovati {$playerCount} giocatori. ";
        }
        
        if ($lastImport) {
            $lastImportDate = Carbon::parse($lastImport->created_at)->format('d/m/Y H:i');
            $details .= "Ultimo import: " . Str::limit($lastImport->original_file_name, 30) . " del {$lastImportDate}. ";
            if ($lastImport->status === 'successo') {
                $statusString = 'Importato';
                $details .= "Completato con successo (righe job: {$lastImport->rows_processed}).";
            } else {
                $statusString = 'Import Fallito';
                $details .= "Fallito: " . Str::limit($lastImport->details, 70);
            }
        }
        $visualAttributes = $this->getVisualAttributesForStatus($statusString);
        return array_merge([
            'status_title' => $statusString,
            'details' => $details,
            'last_import_date' => $lastImportDate,
            'player_count' => $playerCount,
            'target_season_year' => date('Y'), // Assumendo l'anno corrente per il roster // <-- Aggiunto!
        ], $visualAttributes);
    }
    
    private function getEnrichmentStatus(): array
    {
        $totalPlayers = Player::count();
        $missingEnrichment = 0;
        if ($totalPlayers > 0) {
            $missingEnrichment = Player::whereNull('date_of_birth')
            ->orWhereNull('detailed_position')
            ->orWhereNull('api_football_data_id')
            ->count();
        }
        $lastRun = ImportLog::where('import_type', 'player_enrichment')
        ->latest('created_at')
        ->first();
        $statusString = 'Non applicabile';
        $details = 'Nessun giocatore nel database.';
        $lastRunDate = 'N/A';
        
        if ($totalPlayers > 0) {
            if ($missingEnrichment === 0) {
                $statusString = 'Completato';
                $details = 'Tutti i giocatori sono arricchiti.';
            } else {
                $statusString = 'Da completare';
                $details = "{$missingEnrichment}/{$totalPlayers} giocatori necessitano di arricchimento.";
            }
        }
        if ($lastRun) {
            $lastRunDate = Carbon::parse($lastRun->created_at)->format('d/m/Y H:i');
            $statusLastRun = $lastRun->status === 'successo' ? 'successo' : 'fallito';
            $details .= " Ultimo tentativo: {$lastRunDate} (Stato: {$statusLastRun}).";
            if ($lastRun->status !== 'successo' && $statusString === 'Da completare') {
            }
        }
        $visualAttributes = $this->getVisualAttributesForStatus($statusString);
        if ($statusString === 'Da completare' && $lastRun && $lastRun->status !== 'successo') {
            $visualAttributes['cardBorderClass'] = 'border-danger';
            $visualAttributes['headerBgClass'] = 'bg-danger-subtle text-danger-emphasis';
            $visualAttributes['iconClass'] = 'fas fa-user-times text-danger';
            $visualAttributes['badgeClass'] = 'bg-danger';
        }
        
        $visualAttributes['showAction'] = ($totalPlayers > 0 && $missingEnrichment > 0);
        if ($statusString === 'Non applicabile') $visualAttributes['showAction'] = false;
        
        return array_merge([
            'status_title' => $statusString,
            'missing_count' => $missingEnrichment,
            'details' => $details,
            'last_run_date' => $lastRunDate,
            'target_season_year' => date('Y'), // Assumendo l'anno corrente // <-- Aggiunto!
        ], $visualAttributes);
    }
    
    private function getFbrefScrapingStatus(): array
    {
        $scrapedRecordsCount = PlayerFbrefStat::count();
        $uniqueTeamSeasons = $scrapedRecordsCount > 0 ? PlayerFbrefStat::selectRaw('COUNT(DISTINCT CONCAT(team_id, "-", season_year)) as count')->first()->count : 0;
        $lastScrape = ImportLog::where('import_type', 'fbref_scrape')
        ->latest('created_at')
        ->first();
        $statusString = 'Non avviato';
        $details = 'Nessun dato Fbref raschiato.';
        $lastScrapeDate = 'N/A';
        
        if ($scrapedRecordsCount > 0) {
            $statusString = 'Parzialmente popolato';
            $details = "Trovati {$scrapedRecordsCount} record Fbref per {$uniqueTeamSeasons} squadre/stagioni.";
        }
        if ($lastScrape) {
            $lastScrapeDate = Carbon::parse($lastScrape->created_at)->format('d/m/Y H:i');
            $logDetails = $lastScrape->details ? Str::limit($lastScrape->details, 50) : ($lastScrape->original_file_name ? Str::limit($lastScrape->original_file_name, 50) : 'N/D');
            $details .= " Ultima operazione: {$lastScrapeDate} (Stato: {$lastScrape->status} - {$logDetails}).";
            if($lastScrape->status !== 'successo' && $statusString === 'Parzialmente popolato') $statusString = 'Scraping Fallito';
            if($statusString === 'Non avviato' && $lastScrape->status !== 'successo') $statusString = 'Scraping Fallito';
        }
        
        $visualAttributes = $this->getVisualAttributesForStatus($statusString);
        return array_merge([
            'status_title' => $statusString,
            'scraped_count' => $scrapedRecordsCount,
            'details' => $details,
            'last_scrape_date' => $lastScrapeDate,
            'target_season_year' => date('Y'), // Assumendo l'anno corrente // <-- Aggiunto!
        ], $visualAttributes);
    }
    
    private function getFbrefProcessingStatus(): array
    {
        $lastRun = ImportLog::where('import_type', 'fbref_processing')
        ->latest('created_at')
        ->first();
        $statusString = 'Non processato';
        $details = 'Nessun dato Fbref processato e salvato nello storico.';
        $lastRunDate = 'N/A';
        $processedCountFromLog = 0;
        
        if ($lastRun) {
            $lastRunDate = Carbon::parse($lastRun->created_at)->format('d/m/Y H:i');
            if ($lastRun->status === 'successo') {
                $statusString = 'Processato';
                $processedCountFromLog = $lastRun->rows_created ?? ($this->parseCountFromDetails($lastRun->details) ?? 0);
                $details = "Ultima elaborazione FBRef del {$lastRunDate} ha prodotto {$processedCountFromLog} record storici.";
                if ($lastRun->details && $lastRun->details !== "Avvio importazione.") {
                    $details .= " Dettagli job: " . Str::limit($lastRun->details, 100);
                }
            } else {
                $statusString = 'Processamento Fallito';
                $details = "Ultima elaborazione FBRef del {$lastRunDate} fallita: " . Str::limit($lastRun->details, 100);
            }
        }
        $visualAttributes = $this->getVisualAttributesForStatus($statusString);
        // Se non ci sono dati FBRef da processare, l'azione non è necessaria
        if (PlayerFbrefStat::count() === 0) {
            $statusString = 'Non applicabile';
            $details = 'Nessun dato FBRef grezzo da processare.';
            $visualAttributes = $this->getVisualAttributesForStatus($statusString);
        }
        
        return array_merge([
            'status_title' => $statusString,
            'processed_count' => $processedCountFromLog,
            'details' => $details,
            'last_run_date' => $lastRunDate,
            'target_season_year' => date('Y'), // Assumendo l'anno corrente // <-- Aggiunto!
        ], $visualAttributes);
    }
    
    private function getOtherHistoricalStatsStatus(): array
    {
        $lastImport = ImportLog::where('import_type', 'statistiche_storiche')->latest()->first();
        $historicalPlayerStatCount = HistoricalPlayerStat::count();
        $statusString = 'Non importato';
        $details = 'Nessuna statistica storica (da upload XLSX) importata.';
        $lastImportDate = 'N/A';
        
        if ($lastImport) {
            $lastImportDate = Carbon::parse($lastImport->created_at)->format('d/m/Y H:i');
            if ($lastImport->status === 'successo') {
                $statusString = 'Importato';
                $details = "Trovati {$historicalPlayerStatCount} record storici totali. Ultimo import XLSX ({$lastImport->original_file_name}) del {$lastImportDate} completato (processati dal job: {$lastImport->rows_processed}).";
            } else {
                $statusString = 'Import Fallito';
                $details = "Trovati {$historicalPlayerStatCount} record storici totali. Ultimo import XLSX ({$lastImport->original_file_name}) del {$lastImportDate} fallito: " . Str::limit($lastImport->details, 100);
            }
        } elseif ($historicalPlayerStatCount > 0) {
            $statusString = 'Dati Presenti';
            $details = "Trovati {$historicalPlayerStatCount} record storici totali (origine ultimo import XLSX non tracciata).";
        }
        
        $visualAttributes = $this->getVisualAttributesForStatus($statusString);
        return array_merge([
            'status_title' => $statusString,
            'details' => $details,
            'last_import_date' => $lastImportDate,
            'historical_count' => $historicalPlayerStatCount,
            'target_season_year' => date('Y') - 1, // Assumendo l'anno precedente per storico // <-- Aggiunto!
        ], $visualAttributes);
    }
    
    private function getProjectionsStatus(): array
    {
        // Ottieni l'anno della stagione d'asta attuale/futura.
        $currentDate = Carbon::now();
        $auctionSeasonStartYear = $currentDate->year;
        if ($currentDate->month >= 7) {
            $auctionSeasonStartYear = $currentDate->year + 1;
        }
        
        $auctionSeasonDisplay = $auctionSeasonStartYear . '-' . substr($auctionSeasonStartYear + 1, 2);
        
        $playersWithProjections = PlayerProjectionSeason::where('season_start_year', $auctionSeasonStartYear)->count();
        
        $totalPlayers = Player::count();
        $lastRun = ImportLog::where('import_type', 'generate_projections')
        ->latest('created_at')
        ->first();
        $statusString = 'Non generate';
        $details = 'Nessuna proiezione finale generata per la stagione ' . $auctionSeasonDisplay . '.';
        $lastRunDate = 'N/A';
        
        if ($totalPlayers === 0 && $playersWithProjections === 0) {
            $statusString = 'Non applicabile';
            $details = 'Nessun giocatore nel DB per generare proiezioni.';
        } elseif ($playersWithProjections > 0) {
            if ($playersWithProjections === $totalPlayers) {
                $statusString = 'Generate';
                $details = "Proiezioni generate per tutti i {$totalPlayers} giocatori per la stagione {$auctionSeasonDisplay}.";
            } else {
                $statusString = 'Parzialmente generate';
                $details = "Proiezioni generate per {$playersWithProjections}/{$totalPlayers} giocatori per la stagione {$auctionSeasonDisplay}.";
            }
        }
        
        if ($lastRun) {
            $lastRunDate = Carbon::parse($lastRun->created_at)->format('d/m/Y H:i');
            $statusLastRun = $lastRun->status === 'successo' ? 'successo' : 'fallito';
            $details .= " Ultima generazione: {$lastRunDate} (Stato: {$statusLastRun}).";
            if ($lastRun->status !== 'successo' && $statusString !== 'Generate' && $statusString !== 'Non applicabile') {
                $statusString = 'Errore Ultima Generazione';
            }
        }
        
        $visualAttributes = $this->getVisualAttributesForStatus($statusString);
        if ($totalPlayers === 0 || HistoricalPlayerStat::count() === 0) {
            $visualAttributes['showAction'] = false;
            if ($totalPlayers === 0) { $statusString = 'Non applicabile'; $details = 'Nessun giocatore nel DB.';}
            elseif (HistoricalPlayerStat::count() === 0) { $statusString = 'Non applicabile'; $details = 'Statistiche storiche non popolate. Impossibile generare proiezioni.';}
            $visualAttributes = $this->getVisualAttributesForStatus($statusString);
        }
        
        $visualAttributes['target_season_year'] = $auctionSeasonStartYear; // <-- Questa riga è già presente
        
        return array_merge([
            'status_title' => $statusString,
            'details' => $details,
            'last_run_date' => $lastRunDate
        ], $visualAttributes);
    }
    
    private function parseCountFromDetails(?string $details): ?int
    {
        if ($details && preg_match('/(?:processati|prodotti|record)\s*:?\s*(\d+)/i', $details, $matches)) {
            return (int) $matches[1];
        }
        return null;
    }
}