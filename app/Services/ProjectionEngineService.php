<?php

namespace App\Services;

use App\Models\Player;
use App\Models\UserLeagueProfile;
use App\Models\HistoricalPlayerStat;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Collection;

class ProjectionEngineService
{
    protected array $leagueConversionFactors;
    protected array $ageCurvesConfig;
    protected array $regressionMeans;
    protected array $teamTierMultipliersOffensive;
    protected array $teamTierMultipliersDefensive;
    protected array $teamTierPresenceFactors;
    protected array $projectionSettings;
    
    protected FantasyPointCalculatorService $fantasyPointCalculator;
    
    public function __construct(FantasyPointCalculatorService $fantasyPointCalculator)
    {
        $this->fantasyPointCalculator = $fantasyPointCalculator;
        
        // Carica TUTTE le configurazioni dai file
        $this->projectionSettings = Config::get('projection_settings');
        $this->ageCurvesConfig = Config::get('player_age_curves.dati_ruoli');
        
        // Carica i singoli array di configurazione per chiarezza e accessibilità
        $this->leagueConversionFactors = $this->projectionSettings['player_stats_league_conversion_factors'] ?? [];
        $this->regressionMeans = $this->projectionSettings['regression_means'] ?? [];
        $this->teamTierMultipliersOffensive = $this->projectionSettings['team_tier_multipliers_offensive'] ?? [];
        $this->teamTierMultipliersDefensive = $this->projectionSettings['team_tier_multipliers_defensive'] ?? [];
        $this->teamTierPresenceFactors = $this->projectionSettings['team_tier_presence_factor'] ?? [];
        
        // Verifica che le configurazioni siano state caricate correttamente
        if (empty($this->projectionSettings)) {
            Log::error(self::class . ": Errore: File di configurazione 'projection_settings.php' non trovato o vuoto.");
        }
        if (empty($this->ageCurvesConfig)) {
            Log::error(self::class . ": Errore: File di configurazione 'player_age_curves.php' non trovato o vuoto.");
        }
        
        Log::info("ProjectionEngineService initializzato con configurazioni da file.");
    }
    
    /**
     * Genera le proiezioni complete per un giocatore.
     *
     * @param Player $player Il modello Player per cui generare le proiezioni.
     * @param UserLeagueProfile $leagueProfile Il profilo lega dell'utente con le regole di punteggio.
     * @param int|null $numberOfSeasonsToConsider Numero di stagioni storiche da considerare (prende da config se null).
     * @param array $seasonWeights Pesi per ogni stagione (calcolati di default se vuoti).
     * @return array Le proiezioni calcolate.
     */
    public function generatePlayerProjection(Player $player, UserLeagueProfile $leagueProfile, int $numberOfSeasonsToConsider = null, array $seasonWeights = []): array
    {
        Log::info("ProjectionEngineService: Inizio proiezioni per giocatore ID {$player->fanta_platform_id} ({$player->name})");
        
        // Utilizza i lookback_seasons dalla configurazione, con un fallback
        $effectiveNumberOfSeasons = $numberOfSeasonsToConsider ?? ($this->projectionSettings['lookback_seasons'] ?? 4);
        
        $historicalStatsQuery = HistoricalPlayerStat::where('player_fanta_platform_id', $player->fanta_platform_id)
        ->orderBy('season_year', 'desc');
        
        // Per l'analisi dei rigori, usa il lookback specifico per i rigoristi (tutte le stagioni disponibili per ora)
        // La logica può essere affinata per considerare solo le ultime X stagioni rilevanti per i rigori
        $allHistoricalStatsForPenaltyAnalysis = $historicalStatsQuery->get();
        
        // Per le medie generali, usa il lookback generale
        $historicalStatsForAverages = $historicalStatsQuery->take($effectiveNumberOfSeasons)->get();
        
        // Calcola i pesi delle stagioni
        if (empty($seasonWeights) || count($seasonWeights) !== $historicalStatsForAverages->count()) {
            $seasonWeights = $this->calculateDefaultSeasonWeights($historicalStatsForAverages->count());
        }
        
        $weightedStatsPerGame = $this->calculateWeightedAverageStats($historicalStatsForAverages, $seasonWeights);
        
        // Caso: Nessuno storico valido trovato o giocatore con 0 partite nello storico
        if ($historicalStatsForAverages->isEmpty() || ($weightedStatsPerGame['avg_games_played'] ?? 0) == 0) {
            Log::warning("ProjectionEngineService: Nessuna statistica storica o giochi giocati zero per ID {$player->fanta_platform_id}. Uso default.");
            
            $age = $player->date_of_birth ? Carbon::parse($player->date_of_birth)->age : ($this->projectionSettings['default_player_age'] ?? 26);
            
            $defaultStatsPerGame = $this->getDefaultStatsPerGameForRole($player->role, $player->team?->tier, $age);
            
            // Calcolo contributo medio CS anche per i default
            $avgCleanSheetContributionDefault = 0;
            // Usa la soglia dinamica dalla configurazione
            $mv_threshold_for_cs = (float)(($leagueProfile->scoring_rules ?? [])['clean_sheet_mv_threshold'] ?? 6.0);
            if (strtoupper($player->role ?? '') === 'P' || strtoupper($player->role ?? '') === 'D') {
                if (($defaultStatsPerGame['mv'] ?? 0) >= $mv_threshold_for_cs) { // Usa la soglia configurabile
                    $probCS = $defaultStatsPerGame['clean_sheet'] ?? 0.0;
                    $csBonusRuleKey = (strtoupper($player->role) === 'P') ? 'clean_sheet_p' : 'clean_sheet_d';
                    $csBonusValue = (float)(($leagueProfile->scoring_rules ?? [])[$csBonusRuleKey] ??
                        (($leagueProfile->scoring_rules ?? [])[ (strtoupper($player->role) === 'P' ? 'bonus_imbattibilita_portiere':'bonus_imbattibilita_difensore') ] ??
                            (strtoupper($player->role) === 'P' ? 1.0 : 0.5) ));
                    $avgCleanSheetContributionDefault = $probCS * $csBonusValue;
                }
            }
            
            $statsForFmCalcDefault = $defaultStatsPerGame;
            unset($statsForFmCalcDefault['clean_sheet']); // Rimuovi clean_sheet per evitare doppio conteggio se FantasyPointCalculator la gestisce internamente
            
            // ***** INIZIO MODIFICA QUI PER LA DECODIFICA FORZATA *****
            $decodedScoringRules = is_string($leagueProfile->scoring_rules) ? json_decode($leagueProfile->scoring_rules, true) : ($leagueProfile->scoring_rules ?? []);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("ProjectionEngineService: Errore nella decodifica JSON di scoring_rules per default stats. JSON Error: " . json_last_error_msg());
                $decodedScoringRules = []; // Fallback a un array vuoto
            }
            // ***** FINE MODIFICA QUI PER LA DECODIFICA FORZATA *****
            
            $fantaMediaProjectedPerGame_base = $this->fantasyPointCalculator->calculateFantasyPoints(
                $statsForFmCalcDefault,
                $decodedScoringRules, // Usa l'array decodificato
                $player->role
                );
            $fantaMediaProjectedPerGame = $fantaMediaProjectedPerGame_base + $avgCleanSheetContributionDefault;
            
            $defaultPresences = $this->estimateDefaultPresences($player->role, $player->team?->tier, $age);
            
            // Ritorna le proiezioni con i valori di fallback
            return [
                'stats_per_game_for_fm_calc' => $defaultStatsPerGame,
                'mv_proj_per_game' => round($defaultStatsPerGame['mv'] ?? ($this->projectionSettings['fallback_mv_if_no_history'] ?? 6.0), 2),
                'fanta_media_proj_per_game' => round($fantaMediaProjectedPerGame, 2),
                'presenze_proj' => round($defaultPresences),
                'total_fanta_points_proj' => round($fantaMediaProjectedPerGame * $defaultPresences, 2),
                'seasonal_totals_proj' => collect($defaultStatsPerGame)->mapWithKeys(function ($value, $key) use ($defaultPresences) {
                if ($key === 'mv' || $key === 'clean_sheet') { // For MV and clean_sheet, don't multiply by games
                    return [$key . '_proj' => $value];
                }
                return [$key . '_proj' => round($value * $defaultPresences, 2)];
                })->all(),
                ];
        }
        
        Log::debug("ProjectionEngineService: Statistiche medie ponderate PER PARTITA: " . json_encode($weightedStatsPerGame));
        
        $adjustmentResult = $this->applyAdjustmentsAndEstimatePresences($weightedStatsPerGame, $player, $leagueProfile, $allHistoricalStatsForPenaltyAnalysis);
        $adjustedStatsPerGame = $adjustmentResult['adjusted_stats_per_game'];
        $presenzeAttese = $adjustmentResult['presenze_attese'];
        
        Log::debug("ProjectionEngineService: Statistiche PER PARTITA aggiustate: " . json_encode($adjustedStatsPerGame));
        Log::debug("ProjectionEngineService: Presenze attese stimate: " . $presenzeAttese);
        
        // Prepara $statsForFmCalculation senza 'clean_sheet' se gestito separatamente
        $statsForFmCalculation = [
            'mv' => $adjustedStatsPerGame['avg_rating'] ?? 6.0,
            'gol_fatti' => $adjustedStatsPerGame['goals_scored'] ?? 0,
            'assist' => $adjustedStatsPerGame['assists'] ?? 0,
            'ammonizioni' => $adjustedStatsPerGame['yellow_cards'] ?? 0,
            'espulsioni' => $adjustedStatsPerGame['red_cards'] ?? 0,
            'autogol' => $adjustedStatsPerGame['own_goals'] ?? 0,
            'rigori_segnati' => $adjustedStatsPerGame['penalties_scored'] ?? 0,
            'rigori_sbagliati' => $adjustedStatsPerGame['penalties_missed'] ?? 0,
            'rigori_parati' => $adjustedStatsPerGame['penalties_saved'] ?? 0,
            'gol_subiti' => $adjustedStatsPerGame['goals_conceded'] ?? 0,
            // Non includere 'clean_sheet' qui, sarà gestito dopo
        ];
        
        // ***** INIZIO MODIFICA QUI PER LA DECODIFICA FORZATA *****
        $decodedScoringRules = is_string($leagueProfile->scoring_rules) ? json_decode($leagueProfile->scoring_rules, true) : ($leagueProfile->scoring_rules ?? []);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("ProjectionEngineService: Errore nella decodifica JSON di scoring_rules per stats ponderate. JSON Error: " . json_last_error_msg());
            $decodedScoringRules = []; // Fallback a un array vuoto
        }
        // ***** FINE MODIFICA QUI PER LA DECODIFICA FORZATA *****
        
        $fantaMediaProjectedPerGame_base = $this->fantasyPointCalculator->calculateFantasyPoints(
            $statsForFmCalculation,
            $decodedScoringRules, // Usa l'array decodificato
            $player->role
            );
        
        // Calcola il contributo medio del clean sheet
        $avgCleanSheetContribution = 0;
        $mv_threshold_for_cs = (float)(($decodedScoringRules)['clean_sheet_mv_threshold'] ?? 6.0); // Usa l'array decodificato
        if (strtoupper($player->role ?? '') === 'P' || strtoupper($player->role ?? '') === 'D') {
            if (($adjustedStatsPerGame['avg_rating'] ?? 0) >= $mv_threshold_for_cs) {
                $probCS = $adjustedStatsPerGame['clean_sheet_per_game_proj'] ?? 0.0;
                $csBonusRuleKey = (strtoupper($player->role) === 'P') ? 'clean_sheet_p' : 'clean_sheet_d';
                $csBonusValue = (float)(($decodedScoringRules)[$csBonusRuleKey] ??
                    (($decodedScoringRules)[ (strtoupper($player->role) === 'P' ? 'bonus_imbattibilita_portiere':'bonus_imbattibilita_difensore') ] ??
                        (strtoupper($player->role) === 'P' ? 1.0 : 0.5) ));
                $avgCleanSheetContribution = $probCS * $csBonusValue;
            }
        }
        
        $fantaMediaProjectedPerGame = $fantaMediaProjectedPerGame_base + $avgCleanSheetContribution;
        Log::info("ProjectionEngineService: FM base: {$fantaMediaProjectedPerGame_base}, Contributo medio CS: {$avgCleanSheetContribution}, FM Finale/partita per {$player->name}: {$fantaMediaProjectedPerGame}");
        
        Log::info("ProjectionEngineService: FantaMedia proiettata PER PARTITA per {$player->name}: {$fantaMediaProjectedPerGame}");
        
        $totalFantasyPointsProjected = $fantaMediaProjectedPerGame * $presenzeAttese;
        Log::info("ProjectionEngineService: Fantapunti totali stagionali proiettati per {$player->name}: {$totalFantasyPointsProjected}");
        
        $projectedSeasonalTotals = [];
        foreach ($adjustedStatsPerGame as $key => $valuePerGame) {
            if ($key === 'avg_rating') {
                $projectedSeasonalTotals['mv_proj_per_game'] = round($valuePerGame, 2);
            } elseif ($key === 'clean_sheet_per_game_proj') {
                $projectedSeasonalTotals[$key] = round($valuePerGame, 2);
            } else {
                if (!in_array($key, ['avg_rating', 'avg_games_played', 'penalties_taken_by_player_rate', 'penalty_conversion_rate_player'])) {
                    $projectedSeasonalTotals[$key . '_proj'] = round($valuePerGame * $presenzeAttese, 2);
                }
            }
        }
        if (!isset($projectedSeasonalTotals['mv_proj_per_game']) && isset($adjustedStatsPerGame['avg_rating'])) {
            $projectedSeasonalTotals['mv_proj_per_game'] = round($adjustedStatsPerGame['avg_rating'], 2);
        }
        
        $finalStatsForFmCalc = $statsForFmCalculation;
        $finalStatsForFmCalc['avg_cs_bonus_calc'] = $avgCleanSheetContribution;
        
        return [
            'stats_per_game_for_fm_calc' => $finalStatsForFmCalc,
            'mv_proj_per_game' => round($adjustedStatsPerGame['avg_rating'] ?? ($this->projectionSettings['fallback_mv_if_no_history'] ?? 6.0), 2),
            'fanta_media_proj_per_game' => round($fantaMediaProjectedPerGame, 2),
            'presenze_proj' => round($presenzeAttese),
            'total_fanta_points_proj' => round($totalFantasyPointsProjected, 2),
            'seasonal_totals_proj' => $projectedSeasonalTotals,
        ];
    }
    
    /**
     * Calcola i pesi di default per le stagioni storiche.
     * Es: per 3 stagioni [0.5, 0.3, 0.2] o [3/6, 2/6, 1/6].
     * @param int $numberOfSeasons Il numero di stagioni da pesare.
     * @return array Array di pesi.
     */
    private function calculateDefaultSeasonWeights(int $numberOfSeasons): array
    {
        if ($numberOfSeasons === 0) return [];
        if ($numberOfSeasons === 1) return [1.0];
        
        $weights = [];
        $totalWeightParts = 0;
        
        // Se la configurazione ha 'season_decay_factor', usalo per un decadimento esponenziale
        $decayFactor = $this->projectionSettings['season_decay_factor'] ?? 1.0;
        if ($decayFactor < 1.0) {
            $currentWeight = 1.0;
            for ($i = 0; $i < $numberOfSeasons; $i++) {
                $weights[$i] = $currentWeight;
                $totalWeightParts += $currentWeight;
                $currentWeight *= $decayFactor;
            }
        } else {
            // Altrimenti, usa un decadimento lineare (N, N-1, ..., 1)
            for ($i = $numberOfSeasons; $i >= 1; $i--) {
                $weights[] = $i;
                $totalWeightParts += $i;
            }
            // Inverti l'array per avere il peso maggiore sulla stagione più recente
            $weights = array_reverse($weights);
        }
        
        if ($totalWeightParts === 0) return array_fill(0, $numberOfSeasons, 1 / $numberOfSeasons); // Previene divisione per zero
        
        // Normalizza i pesi in modo che la loro somma sia 1
        return array_map(fn($weight) => $weight / $totalWeightParts, $weights);
    }
    
    /**
     * Calcola le medie ponderate delle statistiche storiche del giocatore.
     * Applica i fattori di conversione per lega.
     *
     * @param Collection $historicalStats Collezione di HistoricalPlayerStat.
     * @param array $seasonWeights Pesi per ogni stagione.
     * @return array Medie ponderate per partita.
     */
    private function calculateWeightedAverageStats(Collection $historicalStats, array $seasonWeights): array
    {
        $weightedAverages = [
            'avg_rating' => 0.0, 'goals_scored' => 0.0, 'assists' => 0.0,
            'yellow_cards' => 0.0, 'red_cards' => 0.0, 'own_goals' => 0.0,
            'penalties_scored' => 0.0, 'penalties_missed' => 0.0,
            'penalties_saved' => 0.0, 'goals_conceded' => 0.0,
            'avg_games_played' => 0.0,
            'penalties_taken' => 0.0,
        ];
        $totalWeightSumForPerGameStats = 0;
        $totalWeightSumForGamesPlayed = 0;
        
        foreach ($historicalStats as $index => $stats) {
            $weight = $seasonWeights[$index] ?? (1 / $historicalStats->count());
            $games = $stats->games_played > 0 ? $stats->games_played : 1;
            
            // Ottieni i fattori di conversione per la lega di origine del record storico
            $conversionFactors = $this->leagueConversionFactors[$stats->league_name] ??
            ($this->leagueConversionFactors['default'] ?? []);
            
            // Applica i fattori di conversione prima di sommare le statistiche
            if ($stats->games_played > 0) {
                // Le statistiche che hanno fattori di conversione specifici
                $weightedAverages['avg_rating'] += (($stats->avg_rating ?? 6.0) * ($conversionFactors['avg_rating'] ?? 1.0)) * $weight;
                $weightedAverages['goals_scored'] += (($stats->goals_scored / $games) * ($conversionFactors['goals_scored'] ?? 1.0)) * $weight;
                $weightedAverages['assists'] += (($stats->assists / $games) * ($conversionFactors['assists'] ?? 1.0)) * $weight;
                
                // Per le altre statistiche, se non specificato un fattore, si usa 1.0
                $weightedAverages['yellow_cards'] += ($stats->yellow_cards / $games) * $weight;
                $weightedAverages['red_cards'] += ($stats->red_cards / $games) * $weight;
                $weightedAverages['own_goals'] += ($stats->own_goals / $games) * $weight;
                $weightedAverages['penalties_scored'] += ($stats->penalties_scored / $games) * $weight;
                $weightedAverages['penalties_missed'] += (($stats->penalties_taken - $stats->penalties_scored) / $games) * $weight;
                $weightedAverages['penalties_saved'] += ($stats->penalties_saved / $games) * $weight;
                $weightedAverages['goals_conceded'] += ($stats->goals_conceded / $games) * $weight;
                $weightedAverages['penalties_taken'] += ($stats->penalties_taken / $games) * $weight;
                $totalWeightSumForPerGameStats += $weight;
            }
            $weightedAverages['avg_games_played'] += $stats->games_played * $weight;
            $totalWeightSumForGamesPlayed += $weight;
        }
        
        // Finalizza le medie dividendo per la somma totale dei pesi
        if ($totalWeightSumForPerGameStats > 0) {
            foreach (array_keys($weightedAverages) as $key) {
                if ($key !== 'avg_games_played' && isset($weightedAverages[$key])) {
                    $weightedAverages[$key] = $weightedAverages[$key] / $totalWeightSumForPerGameStats;
                }
            }
        }
        if ($totalWeightSumForGamesPlayed > 0 && abs($totalWeightSumForGamesPlayed - 1.0) > 1e-9) {
            // Se i pesi non sommano a 1 (può succedere se alcune stagioni hanno 0 partite o per il decadimento), normalizza avg_games_played
            $weightedAverages['avg_games_played'] = $weightedAverages['avg_games_played'] / $totalWeightSumForGamesPlayed;
        }
        return $weightedAverages;
    }
    
    /**
     * Applica aggiustamenti basati su età, tier squadra e gestisce la logica dei rigoristi.
     *
     * @param array $weightedStatsPerGame Statistiche medie ponderate per partita.
     * @param Player $player Il modello Player.
     * @param UserLeagueProfile $leagueProfile Il profilo lega.
     * @param Collection $historicalStatsForPenaltyAnalysis Storico completo per analisi rigoristi.
     * @return array Array con statistiche aggiustate e presenze stimate.
     */
    private function applyAdjustmentsAndEstimatePresences(array $weightedStatsPerGame, Player $player, UserLeagueProfile $leagueProfile, Collection $historicalStatsForPenaltyAnalysis): array
    {
        $adjustedStatsPerGame = $weightedStatsPerGame;
        $ageModifier = 1.0;
        
        $ageCurvesConfig = $this->ageCurvesConfig; // Usa la proprietà di configurazione caricata
        $ageModifierParams = Config::get('player_age_curves.age_modifier_params'); // Carica dal file di config
        
        if ($player->date_of_birth && $ageCurvesConfig && $ageModifierParams) {
            $age = $player->date_of_birth->age;
            Log::debug("ProjectionEngineService: Giocatore {$player->name}, Età: {$age}");
            
            // Determina la chiave del ruolo per la configurazione delle curve di età
            $roleKey = strtoupper($player->role ?? 'C');
            // Gestione dei ruoli specifici (es. D_CENTRALE, D_ESTERNO) se il ruolo del player è solo 'D'
            // Per ora, useremo solo il ruolo principale (P,D,C,A) se non ci sono match esatti.
            $configForRole = $ageCurvesConfig[$roleKey] ?? $ageCurvesConfig['C'] ?? null;
            
            if ($configForRole && isset($configForRole['fasi_carriera'])) {
                $fasi = $configForRole['fasi_carriera'];
                $peakStart = $fasi['picco_inizio'] ?? 25;
                $peakEnd = $fasi['picco_fine'] ?? 30;
                $growthFactor = $configForRole['growth_factor'] ?? 0.020;
                $declineFactor = $configForRole['decline_factor'] ?? 0.030;
                $youngCap = $configForRole['young_cap'] ?? 1.20;
                $oldCap = $configForRole['old_cap'] ?? 0.70;
                
                if ($age < $peakStart) {
                    $ageModifier = min($youngCap, 1.0 + (($peakStart - $age) * $growthFactor));
                } elseif ($age > $peakEnd) {
                    $ageModifier = max($oldCap, 1.0 - (($age - $peakEnd) * $declineFactor));
                }
                Log::debug("ProjectionEngineService: Modificatore Età per {$player->name} ({$roleKey}): {$ageModifier} (Config: P_Start:{$peakStart}, P_End:{$peakEnd}, GF:{$growthFactor}, DF:{$declineFactor})");
                
                if (isset($adjustedStatsPerGame['avg_rating'])) {
                    $mvEffect = $ageModifierParams['mv_effect_ratio'] ?? 0.5;
                    $adjustedStatsPerGame['avg_rating'] *= (1 + ($ageModifier - 1) * $mvEffect);
                }
                foreach (['goals_scored', 'assists'] as $key) {
                    if (isset($adjustedStatsPerGame[$key])) $adjustedStatsPerGame[$key] *= $ageModifier;
                }
            } else {
                Log::warning("ProjectionEngineService: Configurazione curva età non trovata o incompleta per ruolo {$roleKey}. Nessun age modifier applicato.");
            }
        }
        
        $teamTier = $player->team?->tier ?? ($this->projectionSettings['default_team_tier'] ?? 3);
        Log::debug("[DEBUG TIER] Player: {$player->name}, Team Name from DB: {$player->team?->name}, Team Tier from DB: {$teamTier}");
        
        $offensiveTierFactors = $this->teamTierMultipliersOffensive;
        $defensiveTierFactors = $this->teamTierMultipliersDefensive;
        
        $tierMultiplierOffensive = $offensiveTierFactors[$teamTier] ?? 1.0;
        $tierMultiplierDefensive = $defensiveTierFactors[$teamTier] ?? 1.0;
        Log::debug("[DEBUG TIER] Tier Multiplier Offensivo CALCOLATO per stats generali: {$tierMultiplierOffensive} (basato su teamTier: {$teamTier})");
        
        // Applica i moltiplicatori di tier alle statistiche
        if (isset($adjustedStatsPerGame['goals_scored'])) $adjustedStatsPerGame['goals_scored'] *= $tierMultiplierOffensive;
        if (isset($adjustedStatsPerGame['assists'])) $adjustedStatsPerGame['assists'] *= $tierMultiplierOffensive;
        if (isset($adjustedStatsPerGame['penalties_taken'])) $adjustedStatsPerGame['penalties_taken'] *= $tierMultiplierOffensive;
        if (isset($adjustedStatsPerGame['penalties_scored'])) $adjustedStatsPerGame['penalties_scored'] *= $tierMultiplierOffensive;
        
        if (strtoupper($player->role ?? '') === 'P' || strtoupper($player->role ?? '') === 'D') {
            if (isset($adjustedStatsPerGame['goals_conceded'])) {
                // Se è un difensore (non portiere) E goals_conceded è 0 dallo storico (es. non tracciato per non-GK)
                if (strtoupper($player->role ?? '') === 'D' && $adjustedStatsPerGame['goals_conceded'] == 0) {
                    $leagueAvgGoalsConcededPerGame = ($this->projectionSettings['league_average_goals_conceded_per_game'] ?? 1.3); // Get from config
                    $adjustedStatsPerGame['goals_conceded'] = $leagueAvgGoalsConcededPerGame * $tierMultiplierDefensive;
                    Log::debug("ProjectionEngineService: Difensore {$player->name} (role D), goals_conceded era 0, stimato a: {$adjustedStatsPerGame['goals_conceded']}");
                } else {
                    // For Goalkeepers or Defenders with non-zero goals_conceded, apply tier multiplier
                    $adjustedStatsPerGame['goals_conceded'] *= $tierMultiplierDefensive;
                }
            } else {
                // Se goals_conceded non è nemmeno presente in adjustedStatsPerGame (e.g., missing from historical data)
                if (strtoupper($player->role ?? '') === 'D') {
                    $leagueAvgGoalsConcededPerGame = ($this->projectionSettings['league_average_goals_conceded_per_game'] ?? 1.3); // Get from config
                    $adjustedStatsPerGame['goals_conceded'] = $leagueAvgGoalsConcededPerGame * $tierMultiplierDefensive;
                    Log::debug("ProjectionEngineService: Difensore {$player->name} (role D), goals_conceded non presente, stimato a: {$adjustedStatsPerGame['goals_conceded']}");
                }
            }
        }
        
        // Logica Rigoristi
        $totalPenaltiesTakenInLookback = 0;
        $totalPenaltiesScoredInLookback = 0;
        
        if ($historicalStatsForPenaltyAnalysis->isNotEmpty()) {
            foreach ($historicalStatsForPenaltyAnalysis as $statSeason) {
                $totalPenaltiesTakenInLookback += $statSeason->penalties_taken;
                $totalPenaltiesScoredInLookback += $statSeason->penalties_scored;
            }
        }
        
        $isLikelyPenaltyTaker = ($totalPenaltiesTakenInLookback >= ($this->projectionSettings['min_penalties_taken_threshold'] ?? 3));
        
        // Non usare basePenaltiesTakenPerGame o basePenaltiesScoredPerGame da adjustedStatsPerGame
        // Quelli sono le medie storiche del giocatore prima di questa sezione dei rigori
        // Vogliamo proiettare i rigori da zero se il giocatore è un rigorista designato
        // Le statistiche di rigore in $adjustedStatsPerGame verranno sovrascritte.
        
        if ($isLikelyPenaltyTaker) {
            Log::info("ProjectionEngineService: Giocatore {$player->name} identificato come probabile rigorista (Storico: {$totalPenaltiesTakenInLookback} calciati).");
            
            $expectedPenaltiesForHisTeamPerGame = ($this->projectionSettings['league_avg_penalties_awarded'] ?? 0.20) * $tierMultiplierOffensive;
            Log::debug("ProjectionEngineService: Rigorista {$player->name} - Rigori attesi per la sua squadra/partita: {$expectedPenaltiesForHisTeamPerGame} (Media Lega: " . ($this->projectionSettings['league_avg_penalties_awarded'] ?? 'N/D') . ", TierMultiOff: {$tierMultiplierOffensive})");
            
            $projectedPenaltiesTakenByPlayerThisSeason = $expectedPenaltiesForHisTeamPerGame * ($this->projectionSettings['penalty_taker_share'] ?? 0.85);
            $projectedPenaltiesTakenByPlayerThisSeason *= $ageModifier; // Applica modificatore età
            Log::debug("ProjectionEngineService: Rigorista {$player->name} - Proiezione Rigori Calciati da lui/partita: {$projectedPenaltiesTakenByPlayerThisSeason} (Quota: " . ($this->projectionSettings['penalty_taker_share'] ?? 'N/D') . ", AgeMod: {$ageModifier})");
            
            // Tasso di conversione dei rigori del giocatore (storico)
            $penaltyConversionRatePlayerHist = ($this->projectionSettings['default_penalty_conversion_rate'] ?? 0.75);
            if ($totalPenaltiesTakenInLookback >= ($this->projectionSettings['min_penalties_taken_for_reliable_conversion_rate'] ?? 5)) {
                if ($totalPenaltiesTakenInLookback > 0) {
                    $penaltyConversionRatePlayerHist = $totalPenaltiesScoredInLookback / $totalPenaltiesTakenInLookback;
                }
            } else if ($totalPenaltiesTakenInLookback > 0) {
                // Meno rigori, media tra storico e default per stabilizzare
                $penaltyConversionRatePlayerHist = ($totalPenaltiesScoredInLookback / $totalPenaltiesTakenInLookback + ($this->projectionSettings['default_penalty_conversion_rate'] ?? 0.75)) / 2;
            }
            
            // Applica l'effetto età al tasso di conversione dei rigori
            $ageModifierParams = Config::get('player_age_curves.age_modifier_params');
            $conversionAgeEffect = 1 + (($ageModifier - 1) * ($ageModifierParams['penalty_conversion_effect_ratio'] ?? 0.2));
            $finalPlayerConversionRate = $penaltyConversionRatePlayerHist * $conversionAgeEffect;
            $finalPlayerConversionRate = max(0.50, min(0.95, $finalPlayerConversionRate)); // Cappa il tasso di conversione in un range realistico
            Log::debug("ProjectionEngineService: Rigorista {$player->name} - Tasso Conversione Finale: {$finalPlayerConversionRate} (Storico Pesato: {$penaltyConversionRatePlayerHist}, AgeConvEffect: {$conversionAgeEffect})");
            
            // Calcola rigori segnati e sbagliati proiettati
            $projectedPenaltiesScoredByPlayer = $projectedPenaltiesTakenByPlayerThisSeason * $finalPlayerConversionRate;
            $projectedPenaltiesMissedByPlayer = $projectedPenaltiesTakenByPlayerThisSeason * (1 - $finalPlayerConversionRate);
            
            // La variazione netta dei gol segnati include solo i rigori che il giocatore segna IN PIU' rispetto alla sua media NON RIGORISTA
            // Se prima era 0, sarà projectedPenaltiesScoredByPlayer. Se ne faceva già, solo la differenza.
            $netChangeInScoredPenalties = $projectedPenaltiesScoredByPlayer - ($weightedStatsPerGame['penalties_scored'] ?? 0);
            
            $adjustedStatsPerGame['penalties_taken'] = $projectedPenaltiesTakenByPlayerThisSeason;
            $adjustedStatsPerGame['penalties_scored'] = $projectedPenaltiesScoredByPlayer;
            $adjustedStatsPerGame['penalties_missed'] = $projectedPenaltiesMissedByPlayer;
            
            // Aggiungi o sottrai la netta variazione di gol da rigore al totale dei gol segnati
            $adjustedStatsPerGame['goals_scored'] = ($adjustedStatsPerGame['goals_scored'] ?? 0) + $netChangeInScoredPenalties;
            Log::debug("ProjectionEngineService: Rigorista {$player->name} - Variazione Netta Gol da Rigore: {$netChangeInScoredPenalties}. Gol Totali Aggiornati: {$adjustedStatsPerGame['goals_scored']}");
            
        } else {
            // Se non è un rigorista designato, i rigori calciati/segnati/sbagliati si basano sulle medie storiche individuali modulate dall'età e tier
            $adjustedStatsPerGame['penalties_missed'] = ($adjustedStatsPerGame['penalties_taken'] ?? 0) - ($adjustedStatsPerGame['penalties_scored'] ?? 0);
            Log::debug("ProjectionEngineService: Giocatore {$player->name} non identificato come rigorista principale. Rigori calciati/segnati/sbagliati basati su medie storiche individuali modulate.");
        }
        
        // Proiezione Clean Sheet per partita (Difensori/Portieri)
        $adjustedStatsPerGame['clean_sheet_per_game_proj'] = 0.0;
        if (strtoupper($player->role ?? '') === 'P' || strtoupper($player->role ?? '') === 'D') {
            $baseCleanSheetProbMap = ($this->projectionSettings['clean_sheet_probabilities_by_tier'] ?? []);
            $probCS = $baseCleanSheetProbMap[$teamTier] ?? 0.10; // Probabilità base di clean sheet per tier
            $csAgeEffect = ($ageModifierParams['cs_age_effect_ratio'] ?? 0.3);
            $probCS *= (1 + ($ageModifier - 1) * $csAgeEffect); // Applica l'effetto età
            $adjustedStatsPerGame['clean_sheet_per_game_proj'] = max(0, min(($this->projectionSettings['max_clean_sheet_probability'] ?? 0.8), round($probCS, 3)));
        }
        
        // Stima Presenze Attese
        $basePresenze = $weightedStatsPerGame['avg_games_played'] ?? ($this->projectionSettings['default_presences_map']['base'] ?? 20);
        Log::debug("[DEBUG TIER PRESENZE] Team: {$player->team?->name}, Tier DB: {$player->team?->tier}, TierMultiplierOffensive usato per presenze: {$tierMultiplierOffensive}");
        
        $teamTierPresenceFactors = $this->teamTierPresenceFactors;
        $presenzeTierFactor = $teamTierPresenceFactors[$teamTier] ?? 1.0;
        Log::debug("[DEBUG TIER PRESENZE] PresenzeTierFactor CALCOLATO: {$presenzeTierFactor}");
        
        $presenzeAgeFactor = $ageModifier;
        if ($ageModifier < 1.0 && isset($ageModifierParams)) { // Giocatore in fase di declino per età
            $declineEffect = ($ageModifierParams['presenze_decline_effect_ratio'] ?? 1.1);
            $declineCap = ($ageModifierParams['presenze_decline_cap'] ?? 0.65);
            $presenzeAgeFactor = max($declineCap, 1 - ((1 - $ageModifier) * $declineEffect));
        } elseif ($ageModifier > 1.0 && isset($ageModifierParams)) { // Giocatore in fase di crescita per età
            $growthEffect = ($ageModifierParams['presenze_growth_effect_ratio'] ?? 0.4);
            $growthCap = ($ageModifierParams['presenze_growth_cap'] ?? 1.12);
            $presenzeAgeFactor = min($growthCap, 1 + (($ageModifier - 1) * $growthEffect));
        }
        
        $presenzeAttese = round($basePresenze * $presenzeTierFactor * $presenzeAgeFactor);
        $presenzeAttese = max(($this->projectionSettings['min_projected_presences'] ?? 5), min(($this->projectionSettings['max_projected_presences'] ?? 38), (int)$presenzeAttese));
        Log::debug("ProjectionEngineService: Stima Presenze per {$player->name} - Base:{$basePresenze}, TierFactor:{$presenzeTierFactor}, AgeFactor:{$presenzeAgeFactor} => Finale:{$presenzeAttese}");
        
        // Rimuovi avg_games_played da adjustedStatsPerGame, è solo un intermedio
        if (isset($adjustedStatsPerGame['avg_games_played'])) unset($adjustedStatsPerGame['avg_games_played']);
        
        return [
            'adjusted_stats_per_game' => $adjustedStatsPerGame,
            'presenze_attese' => $presenzeAttese,
        ];
    }
    
    /**
     * Stima le statistiche di default per partita per un ruolo specifico,
     * modulando per tier squadra ed età.
     * Usato quando non ci sono statistiche storiche sufficienti.
     *
     * @param string|null $role Ruolo del giocatore (P, D, C, A).
     * @param int|null $teamTier Tier della squadra del giocatore.
     * @param int|null $age Età del giocatore.
     * @return array Statistiche di default per partita.
     */
    private function getDefaultStatsPerGameForRole(?string $role, ?int $teamTier, ?int $age): array
    {
        Log::debug("ProjectionEngineService: GetDefaultStats - Ruolo:{$role}, Tier:{$teamTier}, Età:{$age}");
        
        $roleKey = strtoupper($role ?? 'C');
        $currentTeamTier = $teamTier ?? ($this->projectionSettings['default_team_tier'] ?? 3);
        $defaultStatsConfig = ($this->projectionSettings['default_stats_per_role'][$roleKey] ?? $this->projectionSettings['default_stats_per_role']['C'] ?? []);
        
        $baseMv = $defaultStatsConfig['mv'] ?? 5.8;
        $baseGoalsNoPen = $defaultStatsConfig['goals_scored'] ?? 0.0;
        $baseAssists = $defaultStatsConfig['assists'] ?? 0.0;
        $baseYellow = $defaultStatsConfig['yellow_cards'] ?? 0.1;
        $baseRed = $defaultStatsConfig['red_cards'] ?? 0.005;
        $baseOwn = $defaultStatsConfig['own_goals'] ?? 0.002;
        $basePenTakenByPlayer = $defaultStatsConfig['penalties_taken'] ?? 0.0;
        $basePenSaved = $defaultStatsConfig['penalties_saved'] ?? 0.0;
        $baseGoalsConceded = $defaultStatsConfig['goals_conceded'] ?? 0.0;
        $baseCleanSheet = $defaultStatsConfig['clean_sheet'] ?? 0.0;
        
        
        $ageCurvesConfig = $this->ageCurvesConfig; // Usa la proprietà di configurazione caricata
        $ageModifierForDefaults = 1.0;
        
        if ($age && $ageCurvesConfig) {
            $configForRole = $ageCurvesConfig[$roleKey] ?? $ageCurvesConfig['C'] ?? null;
            if ($configForRole && isset($configForRole['fasi_carriera'])) {
                $fasi = $configForRole['fasi_carriera'];
                $peakStart = $fasi['picco_inizio'] ?? 25;
                $peakEnd = $fasi['picco_fine'] ?? 30;
                $growthFactor = $configForRole['growth_factor'] ?? 0.020;
                $declineFactor = $configForRole['decline_factor'] ?? 0.030;
                // Applica un effetto età più smorzato per i default
                if ($age < $peakStart) {
                    $ageModifierForDefaults = 1.0 + (($peakStart - $age) * $growthFactor * ($this->projectionSettings['default_age_effect_multiplier_young'] ?? 0.5));
                } elseif ($age > $peakEnd) {
                    $ageModifierForDefaults = 1.0 - (($age - $peakEnd) * $declineFactor * ($this->projectionSettings['default_age_effect_multiplier_old'] ?? 0.8));
                }
                $ageModifierForDefaults = max(($this->projectionSettings['default_age_modifier_min_cap'] ?? 0.7), min(($this->projectionSettings['default_age_modifier_max_cap'] ?? 1.15), $ageModifierForDefaults));
            }
        }
        
        $offensiveTierFactors = $this->teamTierMultipliersOffensive;
        $defensiveTierFactors = $this->teamTierMultipliersDefensive;
        $tierOffensive = $offensiveTierFactors[$currentTeamTier] ?? 1.0;
        $tierDefensive = $defensiveTierFactors[$currentTeamTier] ?? 1.0;
        
        $finalMv = $baseMv * $ageModifierForDefaults;
        $finalGoalsNoPen = $baseGoalsNoPen * $tierOffensive * $ageModifierForDefaults;
        $finalAssists = $baseAssists * $tierOffensive * $ageModifierForDefaults;
        
        $finalPenTaken = $basePenTakenByPlayer * $tierOffensive * $ageModifierForDefaults;
        $finalPenScored = $finalPenTaken * ($this->projectionSettings['default_penalty_conversion_rate'] ?? 0.75);
        $finalPenMissed = $finalPenTaken * (1 - ($this->projectionSettings['default_penalty_conversion_rate'] ?? 0.75));
        
        $finalTotalGoals = $finalGoalsNoPen + $finalPenScored;
        
        $finalCleanSheet = ($roleKey === 'P' || $roleKey === 'D') ? ($baseCleanSheet / $tierDefensive * $ageModifierForDefaults) : 0.0;
        $finalGoalsConceded = ($roleKey === 'P') ? ($baseGoalsConceded * $tierDefensive / $ageModifierForDefaults) : 0.0;
        
        return [
            'mv' => round(max(($this->projectionSettings['default_mv_min_cap'] ?? 5.0), min(($this->projectionSettings['default_mv_max_cap'] ?? 7.5), $finalMv)), 2),
            'goals_scored' => round($finalTotalGoals, 3),
            'assists' => round($finalAssists, 3),
            'ammonizioni' => $baseYellow,
            'espulsioni' => $baseRed,
            'autogol' => $baseOwn,
            'penalties_taken' => round($finalPenTaken, 3),
            'penalties_scored' => round($finalPenScored, 3),
            'penalties_missed' => round($finalPenMissed, 3),
            'rigori_parati' => ($roleKey === 'P' ? round($basePenSaved * $tierDefensive, 3) : 0.0),
            'gol_subiti' => ($roleKey === 'P' ? round(max(0, $finalGoalsConceded), 2) : 0.0),
            'clean_sheet' => (($roleKey === 'P' || $roleKey === 'D') ? round(max(0, min(1, $finalCleanSheet)), 2) : 0.0),
        ];
    }
    
    /**
     * Stima le presenze attese per un giocatore basandosi su ruolo, tier squadra ed età.
     * Utilizzato per i giocatori senza storico o con storico insufficiente.
     *
     * @param string|null $role Ruolo del giocatore (P, D, C, A).
     * @param int|null $teamTier Tier della squadra del giocatore.
     * @param int|null $age Età del giocatore.
     * @return int Presenze attese.
     */
    private function estimateDefaultPresences(?string $role, ?int $teamTier, ?int $age): int
    {
        $base = ($this->projectionSettings['default_presences_map']['base'] ?? 20); // Use loaded config
        $roleKey = strtoupper($role ?? 'C');
        $currentTeamTier = $teamTier ?? ($this->projectionSettings['default_team_tier'] ?? 3); // Use loaded config
        
        $ageCurvesConfig = $this->ageCurvesConfig; // Use loaded config
        $ageModifierParams = ($this->projectionSettings['age_modifier_params'] ?? Config::get('player_age_curves.age_modifier_params')); // Use loaded config
        $ageModifierForPresences = 1.0;
        
        if ($age && $ageCurvesConfig) {
            $configForRole = $ageCurvesConfig[$roleKey] ?? $ageCurvesConfig['C'] ?? null;
            if ($configForRole && isset($configForRole['fasi_carriera'])) {
                $fasi = $configForRole['fasi_carriera'];
                $peakStart = $fasi['picco_inizio'] ?? 25;
                $peakEnd = $fasi['picco_fine'] ?? 30;
                $growthFactorPresenze = $configForRole['presenze_growth_factor'] ?? $configForRole['growth_factor'] ?? 0.020;
                $declineFactorPresenze = $configForRole['presenze_decline_factor'] ?? $configForRole['decline_factor'] ?? 0.030;
                
                if ($age < $peakStart) {
                    $growthEffect = ($ageModifierParams['presenze_growth_effect_ratio'] ?? 0.4); // Use loaded config
                    $growthCap = ($ageModifierParams['presenze_growth_cap'] ?? 1.12); // Use loaded config
                    $ageModifierForPresences = min($growthCap, 1.0 + (($peakStart - $age) * $growthFactorPresenze * $growthEffect));
                } elseif ($age > $peakEnd) {
                    $declineEffect = ($ageModifierParams['presenze_decline_effect_ratio'] ?? 1.1); // Use loaded config
                    $declineCap = ($ageModifierParams['presenze_decline_cap'] ?? 0.65); // Use loaded config
                    $ageModifierForPresences = max($declineCap, 1.0 - (($age - $peakEnd) * $declineFactorPresenze * $declineEffect));
                }
            }
        }
        
        // Utilizza una mappa da config per i valori base per ruolo e tier
        $presencesMap = ($this->projectionSettings['default_presences_map'] ?? []); // Use loaded config
        if (isset($presencesMap[$roleKey]) && isset($presencesMap[$roleKey]['tier'.$currentTeamTier])) {
            $base = $presencesMap[$roleKey]['tier'.$currentTeamTier];
        } elseif (isset($presencesMap[$roleKey]) && isset($presencesMap[$roleKey]['default'])) {
            $base = $presencesMap[$roleKey]['default'];
        } elseif ($currentTeamTier > 3) {
            $base *= ($this->projectionSettings['default_presences_low_tier_factor'] ?? 0.9); // Use loaded config
        }
        
        $base *= $ageModifierForPresences;
        
        return max(($this->projectionSettings['min_projected_presences'] ?? 5), min(($this->projectionSettings['max_projected_presences'] ?? 38), (int)round($base)));
    }
}