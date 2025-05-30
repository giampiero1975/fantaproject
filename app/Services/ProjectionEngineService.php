<?php

namespace App\Services;

use App\Models\Player;
use App\Models\HistoricalPlayerStat;
use App\Models\Team;
use App\Models\UserLeagueProfile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class ProjectionEngineService
{
    protected FantasyPointCalculatorService $pointCalculator;
    
    // Parametri per la logica dei rigoristi
    protected int $penaltyTakerLookbackSeasons;
    protected int $minPenaltiesTakenThreshold;
    protected float $leagueAvgPenaltiesAwardedPerTeamGame;
    protected float $penaltyTakerShareOfTeamPenalties;
    protected float $defaultPenaltyConversionRate;
    
    public function __construct(FantasyPointCalculatorService $pointCalculator)
    {
        $this->pointCalculator = $pointCalculator;
        
        // Carica i parametri da un file di configurazione o usa valori di default
        // Esempio: config/projection_settings.php
        $this->penaltyTakerLookbackSeasons = Config::get('projection_settings.penalty_taker_lookback_seasons', 2);
        $this->minPenaltiesTakenThreshold = Config::get('projection_settings.min_penalties_taken_threshold', 3);
        $this->leagueAvgPenaltiesAwardedPerTeamGame = Config::get('projection_settings.league_avg_penalties_awarded', 0.20); // Esempio: 1 rigore ogni 5 partite per squadra
        $this->penaltyTakerShareOfTeamPenalties = Config::get('projection_settings.penalty_taker_share', 0.85);   // Esempio: il rigorista designato calcia l'85%
        $this->defaultPenaltyConversionRate = Config::get('projection_settings.default_penalty_conversion_rate', 0.75); // Tasso di conversione di default
    }
    
    public function generatePlayerProjection(
        Player $player,
        UserLeagueProfile $leagueProfile,
        int $numberOfSeasonsToConsider = 3,
        array $seasonWeights = []
        ): array {
            Log::info("ProjectionEngineService: Inizio proiezioni per giocatore ID {$player->fanta_platform_id} ({$player->name})");
            
            $historicalStatsQuery = HistoricalPlayerStat::where('player_fanta_platform_id', $player->fanta_platform_id)
            ->orderBy('season_year', 'desc');
            
            // Stats per analisi rigoristi (ultime N stagioni definite da config)
            $allHistoricalStatsForPenaltyAnalysis = $historicalStatsQuery->take($this->penaltyTakerLookbackSeasons)->get();
            // Stats per medie generali (numero di stagioni passato come argomento)
            $historicalStatsForAverages = $historicalStatsQuery->take($numberOfSeasonsToConsider)->get();
            
            
            if ($historicalStatsForAverages->isEmpty()) {
                Log::warning("ProjectionEngineService: Nessuna statistica storica (medie) per ID {$player->fanta_platform_id}. Uso default.");
                $age = $player->date_of_birth ? $player->date_of_birth->age : null;
                $defaultStatsPerGame = $this->getDefaultStatsPerGameForRole($player->role, $player->team?->tier, $age);
                
                $fantaMediaProjectedPerGame = $this->pointCalculator->calculateFantasyPoints(
                    $defaultStatsPerGame,
                    $leagueProfile->scoring_rules ?? [],
                    $player->role
                    );
                $defaultPresences = $this->estimateDefaultPresences($player->role, $player->team?->tier, $age);
                
                return [
                    'stats_per_game_for_fm_calc' => $defaultStatsPerGame,
                    'mv_proj_per_game' => $defaultStatsPerGame['mv'] ?? 6.0,
                    'fanta_media_proj_per_game' => round($fantaMediaProjectedPerGame, 2),
                    'presenze_proj' => $defaultPresences,
                    'total_fantasy_points_proj' => round($fantaMediaProjectedPerGame * $defaultPresences, 2),
                    'seasonal_totals_proj' => collect($defaultStatsPerGame)->mapWithKeys(function ($value, $key) use ($defaultPresences) {
                    if ($key === 'mv' || $key === 'clean_sheet') {
                        return [$key . '_proj' => $value];
                    }
                    return [$key . '_proj' => round($value * $defaultPresences, 2)];
                    })->all(),
                    ];
            }
            
            if (empty($seasonWeights) || count($seasonWeights) !== $historicalStatsForAverages->count()) {
                $seasonWeights = $this->calculateDefaultSeasonWeights($historicalStatsForAverages->count());
            }
            
            $weightedStatsPerGame = $this->calculateWeightedAverageStats($historicalStatsForAverages, $seasonWeights);
            Log::debug("ProjectionEngineService: Statistiche medie ponderate PER PARTITA: " . json_encode($weightedStatsPerGame));
            
            $adjustmentResult = $this->applyAdjustmentsAndEstimatePresences($weightedStatsPerGame, $player, $leagueProfile, $allHistoricalStatsForPenaltyAnalysis);
            $adjustedStatsPerGame = $adjustmentResult['adjusted_stats_per_game'];
            $presenzeAttese = $adjustmentResult['presenze_attese'];
            Log::debug("ProjectionEngineService: Statistiche PER PARTITA aggiustate: " . json_encode($adjustedStatsPerGame));
            Log::debug("ProjectionEngineService: Presenze attese stimate: " . $presenzeAttese);
            
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
                'clean_sheet' => $adjustedStatsPerGame['clean_sheet_per_game_proj'] ?? 0,
            ];
            
            $fantaMediaProjectedPerGame = $this->pointCalculator->calculateFantasyPoints(
                $statsForFmCalculation,
                $leagueProfile->scoring_rules ?? [],
                $player->role
                );
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
            
            return [
                'stats_per_game_for_fm_calc' => $statsForFmCalculation,
                'mv_proj_per_game' => round($adjustedStatsPerGame['avg_rating'] ?? 6.0, 2),
                'fanta_media_proj_per_game' => round($fantaMediaProjectedPerGame, 2),
                'presenze_proj' => $presenzeAttese,
                'total_fantasy_points_proj' => round($totalFantasyPointsProjected, 2),
                'seasonal_totals_proj' => $projectedSeasonalTotals,
            ];
    }
    
    private function calculateDefaultSeasonWeights(int $numberOfSeasons): array
    {
        if ($numberOfSeasons === 0) return [];
        if ($numberOfSeasons === 1) return [1.0];
        $weights = [];
        $totalWeightParts = array_sum(range(1, $numberOfSeasons));
        if ($totalWeightParts === 0) return array_fill(0, $numberOfSeasons, 1 / $numberOfSeasons);
        for ($i = $numberOfSeasons; $i >= 1; $i--) {
            $weights[] = $i / $totalWeightParts;
        }
        return $weights;
    }
    
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
            
            if ($stats->games_played > 0) {
                $weightedAverages['avg_rating'] += ($stats->avg_rating ?? 6.0) * $weight;
                $weightedAverages['goals_scored'] += ($stats->goals_scored / $games) * $weight;
                $weightedAverages['assists'] += ($stats->assists / $games) * $weight;
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
        
        if ($totalWeightSumForPerGameStats > 0) {
            foreach (array_keys($weightedAverages) as $key) {
                if ($key !== 'avg_games_played' && isset($weightedAverages[$key])) {
                    $weightedAverages[$key] = $weightedAverages[$key] / $totalWeightSumForPerGameStats;
                }
            }
        }
        if ($totalWeightSumForGamesPlayed > 0 && abs($totalWeightSumForGamesPlayed - 1.0) > 1e-9) {
            $weightedAverages['avg_games_played'] = $weightedAverages['avg_games_played'] / $totalWeightSumForGamesPlayed;
        }
        return $weightedAverages;
    }
    
    private function applyAdjustmentsAndEstimatePresences(
        array $weightedStatsPerGame,
        Player $player,
        UserLeagueProfile $leagueProfile,
        Collection $historicalStatsForPenaltyAnalysis
        ): array {
            $adjustedStatsPerGame = $weightedStatsPerGame;
            $ageModifier = 1.0; // Calcolato come prima...
            // ... (logica per calcolare $ageModifier come prima) ...
            
            // APPLICAZIONE AGE MODIFIER (come prima)
            if (isset($adjustedStatsPerGame['avg_rating'])) {
                $mvEffectRatio = $this->config['player_age_curves']['age_modifier_params']['mv_effect_ratio'] ?? 0.5;
                $adjustedStatsPerGame['avg_rating'] *= (1 + ($ageModifier - 1) * $mvEffectRatio);
            }
            // ... (applicazione ageModifier ad altre stats come goals_scored, assists se lo facevi prima) ...
            // Nota: Se applichi ageModifier a goals_scored/assists qui, e poi applichi il tier multiplier,
            // i due effetti si cumuleranno. Valuta se è l'effetto desiderato.
            // Forse è meglio applicare l'age modifier PRIMA, e poi il tier modifier sulle stats già aggiustate per età.
            // Assumiamo che 'goals_scored' e 'assists' in $adjustedStatsPerGame siano già state
            // potenzialmente modificate dall'età se la tua logica precedente lo faceva.
            
            
            // --- NUOVA SEZIONE: APPLICAZIONE MOLTIPLICATORI TIER SQUADRA ALLE STATS GIOCATORE ---
            $playerTeamTier = $player->team?->tier ?? ($this->config['projection_settings']['default_team_tier'] ?? 3);
            $teamTierMultipliers = $this->config['projection_settings']['team_tier_player_projection_multipliers'] ?? [];
            
            // Moltiplicatore per output offensivo (gol, assist)
            if (isset($teamTierMultipliers['offensive_output'][$playerTeamTier])) {
                $offensiveMultiplier = (float)$teamTierMultipliers['offensive_output'][$playerTeamTier];
                
                if (isset($adjustedStatsPerGame['goals_scored'])) {
                    $adjustedStatsPerGame['goals_scored'] *= $offensiveMultiplier;
                    Log::debug(self::class . ": {$player->name} - Goals Scored Tier Adj: Moltiplicatore {$offensiveMultiplier} (Tier {$playerTeamTier}). Nuovi Gol/Partita: {$adjustedStatsPerGame['goals_scored']}");
                }
                if (isset($adjustedStatsPerGame['assists'])) {
                    $adjustedStatsPerGame['assists'] *= $offensiveMultiplier;
                    Log::debug(self::class . ": {$player->name} - Assists Tier Adj: Moltiplicatore {$offensiveMultiplier} (Tier {$playerTeamTier}). Nuovi Assist/Partita: {$adjustedStatsPerGame['assists']}");
                }
            }
            
            // Modificatore per gol subiti (solo per portieri)
            if (strtoupper($player->role ?? '') === 'P' && isset($teamTierMultipliers['goals_conceded_goalkeeper'][$playerTeamTier])) {
                $gcMultiplier = (float)$teamTierMultipliers['goals_conceded_goalkeeper'][$playerTeamTier];
                if (isset($adjustedStatsPerGame['goals_conceded'])) {
                    $adjustedStatsPerGame['goals_conceded'] *= $gcMultiplier;
                    Log::debug(self::class . ": {$player->name} (P) - Goals Conceded Tier Adj: Moltiplicatore {$gcMultiplier} (Tier {$playerTeamTier}). Nuovi GS/Partita: {$adjustedStatsPerGame['goals_conceded']}");
                }
            }
            
            // Modificatore per probabilità Clean Sheet (Portieri e Difensori)
            // Questo viene applicato alla probabilità di CS già calcolata (che a sua volta considera il tier squadra)
            if ((strtoupper($player->role ?? '') === 'P' || strtoupper($player->role ?? '') === 'D') && isset($teamTierMultipliers['clean_sheet_probability'][$playerTeamTier])) {
                $csMultiplier = (float)$teamTierMultipliers['clean_sheet_probability'][$playerTeamTier];
                if (!isset($adjustedStatsPerGame['clean_sheet_per_game_proj'])) { // Calcola se non esiste
                    // Questa parte della logica per calcolare la probabilità base di CS potrebbe già esistere
                    // o essere rifattorizzata qui se non presente prima.
                    // Per ora, presumiamo che sia già calcolata prima di questo blocco o che la calcoleremo dopo.
                    // Ai fini di questo esempio, la calcoliamo qui se mancante.
                    $baseCleanSheetProbMap = $this->config['projection_settings']['clean_sheet_probabilities_by_tier'] ?? [1 => 0.40, 2 => 0.30, 3 => 0.20, 4 => 0.15, 5 => 0.10];
                    $probCSBase = $baseCleanSheetProbMap[$playerTeamTier] ?? 0.10;
                    // Applica age modifier alla probabilità base di CS
                    $csAgeEffect = ($this->config['player_age_curves']['age_modifier_params']['cs_age_effect_ratio'] ?? 0.3);
                    $probCSBase *= (1 + ($ageModifier - 1) * $csAgeEffect);
                    $adjustedStatsPerGame['clean_sheet_per_game_proj'] = max(0, min(0.8, $probCSBase));
                }
                $adjustedStatsPerGame['clean_sheet_per_game_proj'] *= $csMultiplier;
                $adjustedStatsPerGame['clean_sheet_per_game_proj'] = max(0, min(0.9, $adjustedStatsPerGame['clean_sheet_per_game_proj'])); // Cap finale
                Log::debug(self::class . ": {$player->name} ({$player->role}) - Clean Sheet Prob Tier Adj: Moltiplicatore {$csMultiplier} (Tier {$playerTeamTier}). Nuova Prob CS/Partita: {$adjustedStatsPerGame['clean_sheet_per_game_proj']}");
            }
            // --- FINE NUOVA SEZIONE ---
            
            
            // ... (Logica RIGORISTI come prima, ma opera su 'goals_scored' già aggiustati per tier) ...
            // Assicurati che la logica dei rigoristi aggiorni i gol partendo da quelli già modulati da età e tier.
            // Se la logica dei rigoristi aggiunge/sottrae GOL DA RIGORE, va bene.
            // Se ricalcola i GOL TOTALI, devi assicurarti che parta dalla base corretta.
            // L'attuale logica dei rigoristi calcola $netChangeInScoredPenalties e lo somma/sottrae ai gol esistenti, quindi dovrebbe essere OK.
            
            // ... (Logica CALCOLO PROBABILITÀ CLEAN SHEET come prima, se non spostata sopra) ...
            // Se hai spostato il calcolo base del CS prima del moltiplicatore, questa sezione potrebbe non servire più qui.
            // O, se preferisci, calcoli la base qui e poi il moltiplicatore è già stato applicato.
            // L'ho inclusa nel blocco del moltiplicatore per coerenza.
            
            // ... (Logica STIMA PRESENZE come prima) ...
            // Potresti anche considerare un moltiplicatore per le presenze basato sul tier squadra
            // se definito in 'team_tier_player_projection_multipliers'.
            
            if (isset($adjustedStatsPerGame['avg_games_played'])) unset($adjustedStatsPerGame['avg_games_played']);
            
            return [
                'adjusted_stats_per_game' => $adjustedStatsPerGame,
                'presenze_attese' => $presenzeAttese,
            ];
    }
    
    private function estimateDefaultPresences(?string $role, ?int $teamTier, ?int $age): int
    {
        $base = 20;
        $roleKey = strtoupper($role ?? 'C');
        $currentTeamTier = $teamTier ?? 3;
        
        $ageCurvesConfig = Config::get('player_age_curves.dati_ruoli');
        $ageModifierParams = Config::get('player_age_curves.age_modifier_params');
        $ageModifierForPresences = 1.0;
        
        if ($age && $ageCurvesConfig && $ageModifierParams) {
            $configForRole = $ageCurvesConfig[$roleKey] ?? $ageCurvesConfig['C'] ?? null;
            if ($configForRole && isset($configForRole['fasi_carriera'])) {
                $fasi = $configForRole['fasi_carriera'];
                $peakStart = $fasi['picco_inizio'] ?? 25;
                $peakEnd = $fasi['picco_fine'] ?? 30;
                $growthFactorPresenze = $configForRole['presenze_growth_factor'] ?? $configForRole['growth_factor'] ?? 0.020;
                $declineFactorPresenze = $configForRole['presenze_decline_factor'] ?? $configForRole['decline_factor'] ?? 0.030;
                
                if ($age < $peakStart) {
                    $growthEffect = $ageModifierParams['presenze_growth_effect_ratio'] ?? 0.4;
                    $growthCap = $ageModifierParams['presenze_growth_cap'] ?? 1.12;
                    $ageModifierForPresences = min($growthCap, 1.0 + (($peakStart - $age) * $growthFactorPresenze * $growthEffect));
                } elseif ($age > $peakEnd) {
                    $declineEffect = $ageModifierParams['presenze_decline_effect_ratio'] ?? 1.1;
                    $declineCap = $ageModifierParams['presenze_decline_cap'] ?? 0.65;
                    $ageModifierForPresences = max($declineCap, 1.0 - (($age - $peakEnd) * $declineFactorPresenze * $declineEffect));
                }
            }
        }
        
        if ($roleKey === 'P' && $currentTeamTier <= 2) $base = 32;
        elseif ($roleKey === 'A' && $currentTeamTier <= 2) $base = 28;
        elseif ($roleKey === 'C' && $currentTeamTier <= 2) $base = 27;
        elseif ($roleKey === 'D' && $currentTeamTier <= 2) $base = 26;
        elseif ($currentTeamTier > 3) $base *= 0.9;
        
        $base *= $ageModifierForPresences;
        
        return max(5, min(38, (int)round($base)));
    }
    
    private function getDefaultStatsPerGameForRole(?string $role, ?int $teamTier, ?int $age): array
    {
        Log::debug("ProjectionEngineService: GetDefaultStats - Ruolo:{$role}, Tier:{$teamTier}, Età:{$age}");
        $baseMv = 5.8; $baseGoalsNoPen = 0.0; $baseAssists = 0.0; // Gol base senza rigori
        $baseYellow = 0.1; $baseRed = 0.005; $baseOwn = 0.002;
        $basePenTakenByPlayer = 0.0; // Rigori che il giocatore stesso potrebbe calciare (se non è il rigorista designato, sarà basso)
        $basePenSaved = 0.0;
        $baseGoalsConceded = 0.0; $baseCleanSheet = 0.0;
        
        $roleKey = strtoupper($role ?? 'C');
        $currentTeamTier = $teamTier ?? 3;
        
        $ageCurvesConfig = Config::get('player_age_curves.dati_ruoli');
        $ageModifierForDefaults = 1.0;
        
        if ($age && $ageCurvesConfig) {
            $configForRole = $ageCurvesConfig[$roleKey] ?? $ageCurvesConfig['C'] ?? null;
            if ($configForRole && isset($configForRole['fasi_carriera'])) {
                $fasi = $configForRole['fasi_carriera'];
                $peakStart = $fasi['picco_inizio'] ?? 25;
                $peakEnd = $fasi['picco_fine'] ?? 30;
                $growthFactor = $configForRole['growth_factor'] ?? 0.020;
                $declineFactor = $configForRole['decline_factor'] ?? 0.030;
                if ($age < $peakStart) $ageModifierForDefaults = 1.0 + (($peakStart - $age) * $growthFactor * 0.5);
                elseif ($age > $peakEnd) $ageModifierForDefaults = 1.0 - (($age - $peakEnd) * $declineFactor * 0.8);
                $ageModifierForDefaults = max(0.7, min(1.15, $ageModifierForDefaults));
            }
        }
        
        switch ($roleKey) {
            case 'P': $baseMv = 6.05; $baseCleanSheet = 0.22; $baseGoalsConceded = 1.25; $basePenSaved = 0.025; break;
            case 'D': $baseMv = 5.95; $baseGoalsNoPen = 0.03; $baseAssists = 0.03; $baseCleanSheet = 0.22; break;
            case 'C': $baseMv = 6.0; $baseGoalsNoPen = 0.08; $baseAssists = 0.08; $basePenTakenByPlayer = 0.01; break; // Pochi rigori spot
            case 'A': $baseMv = 6.0; $baseGoalsNoPen = 0.25; $baseAssists = 0.06; $basePenTakenByPlayer = 0.03; break; // Pochi rigori spot
        }
        
        $offensiveTierFactors = [1 => 1.20, 2 => 1.10, 3 => 1.00, 4 => 0.90, 5 => 0.80];
        $defensiveTierFactors = [1 => 0.80, 2 => 0.90, 3 => 1.00, 4 => 1.10, 5 => 1.20];
        $tierOffensive = $offensiveTierFactors[$currentTeamTier] ?? 1.0;
        $tierDefensive = $defensiveTierFactors[$currentTeamTier] ?? 1.0;
        
        $finalMv = $baseMv * $ageModifierForDefaults;
        $finalGoalsNoPen = $baseGoalsNoPen * $tierOffensive * $ageModifierForDefaults;
        $finalAssists = $baseAssists * $tierOffensive * $ageModifierForDefaults;
        
        // Per i default, la stima dei rigori è più semplice:
        // Si assume che i giocatori non rigoristi designati calcino molto pochi rigori.
        // Se il giocatore fosse rigorista, la logica principale in applyAdjustments sovrascriverebbe questo.
        $finalPenTaken = $basePenTakenByPlayer * $tierOffensive * $ageModifierForDefaults;
        $finalPenScored = $finalPenTaken * $this->defaultPenaltyConversionRate;
        $finalPenMissed = $finalPenTaken * (1 - $this->defaultPenaltyConversionRate);
        
        $finalTotalGoals = $finalGoalsNoPen + $finalPenScored;
        
        $finalCleanSheet = ($roleKey === 'P' || $roleKey === 'D') ? ($baseCleanSheet / $tierDefensive * $ageModifierForDefaults) : 0.0;
        $finalGoalsConceded = ($roleKey === 'P') ? ($baseGoalsConceded * $tierDefensive / $ageModifierForDefaults) : 0.0;
        
        return [
            'mv' => round(max(5.0, min(7.5, $finalMv)), 2),
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
}
