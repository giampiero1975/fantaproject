<?php

namespace App\Http\Controllers;

use App\Models\TeamHistoricalStanding;
use App\Models\Team;
use App\Models\ImportLog;
use App\Models\Player;
use App\Models\PlayerFbrefStat;
use App\Models\HistoricalPlayerStat;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    public function index(): View
    {
        $data = [
            'standingsStatus'         => $this->getStandingsStatus(),
            'activeTeamsStatus'       => $this->getActiveTeamsStatus(),
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
            'cardBorderClass' => 'border-secondary', // Default Bootstrap border
            'headerBgClass'   => 'bg-light',        // Default Bootstrap card header
            'iconClass'       => 'fas fa-question-circle text-secondary',
            'badgeClass'      => 'bg-secondary',
            'showAction'      => true, // Di default mostra l'azione
        ];
        
        switch ($statusKey) {
            case 'completato':
            case 'aggiornato':
            case 'calcolato':
            case 'importato':
            case 'generate': // Per proiezioni
            case 'processato': // Per fbref processing (se tutto ok)
            case 'processato-completamente':
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
            case 'scraping-fallito': // Aggiunto per fbref scraping
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
                $attributes['showAction']      = false;
                break;
        }
        return $attributes;
    }
    
    private function getStandingsStatus(): array
    {
        $count = TeamHistoricalStanding::count();
        $latestSeasonInDb = TeamHistoricalStanding::max('season_year');
        $lastImport = ImportLog::whereIn('import_type', ['standings_csv_import', 'standings_api_fetch'])
        ->latest('created_at') // Prende l'ultimo tentativo, anche fallito
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
        
        
        $visualAttributes = $this->getVisualAttributesForStatus($statusString);
        return array_merge([
            'status_title' => $statusString,
            'details' => $details,
            'count' => $count,
            'last_import_date' => $lastImportDate
        ], $visualAttributes);
    }
    
    private function getActiveTeamsStatus(): array
    {
        $activeSACount = Team::where('serie_a_team', true)->count();
        $lastRun = ImportLog::where('import_type', 'set_active_teams_serie_a')
        ->latest('created_at')
        ->first();
        $details = '';
        $statusString = 'Non aggiornato';
        $lastRunDate = 'N/A';
        
        if ($activeSACount === 20) {
            $statusString = 'Aggiornato';
            $details = '20 squadre Serie A attive definite.';
        } else {
            $details = "Trovate {$activeSACount}/20 squadre Serie A attive.";
        }
        
        if ($lastRun) {
            $lastRunDate = Carbon::parse($lastRun->created_at)->format('d/m/Y H:i');
            $statusLastRun = $lastRun->status === 'successo' ? 'successo' : 'fallito';
            $details .= " Ultimo avvio: {$lastRunDate} (Stato: {$statusLastRun}).";
            if ($lastRun->status !== 'successo' && $statusString !== 'Aggiornato') {
                $statusString = 'Errore Ultimo Aggiornamento';
            }
        }
        
        $visualAttributes = $this->getVisualAttributesForStatus($statusString);
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
        $visualAttributes = $this->getVisualAttributesForStatus($statusString);
        return array_merge([
            'status_title' => $statusString,
            'details' => $details,
            'last_run_date' => $lastRunDate
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
            $statusString = 'Importato'; // Base status if players exist
            $details = "Trovati {$playerCount} giocatori. ";
        }
        
        if ($lastImport) {
            $lastImportDate = Carbon::parse($lastImport->created_at)->format('d/m/Y H:i');
            $details .= "Ultimo import: " . Str::limit($lastImport->original_file_name, 30) . " del {$lastImportDate}. ";
            if ($lastImport->status === 'successo') {
                // StatusString rimane 'Importato' se playerCount > 0, altrimenti diventa 'Importato'
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
            'player_count' => $playerCount
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
                // Potremmo voler mantenere "Da completare" ma segnalare l'errore
            }
        }
        $visualAttributes = $this->getVisualAttributesForStatus($statusString);
        if ($statusString === 'Da completare' && $lastRun && $lastRun->status !== 'successo') {
            $visualAttributes['cardBorderClass'] = 'border-danger'; // Sovrascrive se l'ultimo run è fallito
            $visualAttributes['headerBgClass'] = 'bg-danger-subtle text-danger-emphasis';
            $visualAttributes['iconClass'] = 'fas fa-user-times text-danger';
            $visualAttributes['badgeClass'] = 'bg-danger';
        }
        
        // Logica specifica per showAction per enrichment
        $visualAttributes['showAction'] = ($totalPlayers > 0 && $missingEnrichment > 0);
        if ($statusString === 'Non applicabile') $visualAttributes['showAction'] = false;
        
        
        return array_merge([
            'status_title' => $statusString,
            'missing_count' => $missingEnrichment,
            'details' => $details,
            'last_run_date' => $lastRunDate
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
            'last_scrape_date' => $lastScrapeDate
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
            $statusString = 'Non applicabile'; // O 'Non necessario'
            $details = 'Nessun dato FBRef grezzo da processare.';
            $visualAttributes = $this->getVisualAttributesForStatus($statusString);
        }
        
        return array_merge([
            'status_title' => $statusString,
            'details' => $details,
            'processed_count' => $processedCountFromLog, // Questo è il conteggio del job, non dalla tabella historical_stats
            'last_run_date' => $lastRunDate
        ], $visualAttributes);
    }
    
    private function getOtherHistoricalStatsStatus(): array
    {
        $lastImport = ImportLog::where('import_type', 'statistiche_storiche')->latest()->first();
        $historicalPlayerStatCount = HistoricalPlayerStat::count(); // Conteggio totale
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
            'historical_count' => $historicalPlayerStatCount
        ], $visualAttributes);
    }
    
    private function getProjectionsStatus(): array
    {
        $totalPlayers = Player::count();
        $playersWithProjections = Player::whereNotNull('fanta_mv_proj')->count();
        $lastRun = ImportLog::where('import_type', 'generate_projections')
        ->latest('created_at')
        ->first();
        $statusString = 'Non generate';
        $details = 'Nessuna proiezione finale generata.';
        $lastRunDate = 'N/A';
        
        if ($totalPlayers === 0 && $playersWithProjections === 0) {
            $statusString = 'Non applicabile';
            $details = 'Nessun giocatore nel DB per generare proiezioni.';
        } elseif ($totalPlayers > 0 && $playersWithProjections === $totalPlayers) {
            $statusString = 'Generate';
            $details = 'Proiezioni generate per tutti i giocatori.';
        } elseif ($playersWithProjections > 0) {
            $statusString = 'Parzialmente generate';
            $details = "Proiezioni generate per {$playersWithProjections}/{$totalPlayers} giocatori.";
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
        // Impedisci azione se non ci sono giocatori o se le statistiche storiche (passo 8) non sono popolate
        if ($totalPlayers === 0 || HistoricalPlayerStat::count() === 0) {
            $visualAttributes['showAction'] = false;
            if ($totalPlayers === 0) { $statusString = 'Non applicabile'; $details = 'Nessun giocatore nel DB.';}
            elseif (HistoricalPlayerStat::count() === 0) { $statusString = 'Non applicabile'; $details = 'Statistiche storiche non popolate. Impossibile generare proiezioni.';}
            $visualAttributes = $this->getVisualAttributesForStatus($statusString); // Ricalcola attributi per 'Non applicabile'
        }
        
        
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