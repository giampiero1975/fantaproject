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
    
    private function applyAdjustmentsAndEstimatePresences(array $weightedStatsPerGame, Player $player, UserLeagueProfile $leagueProfile, Collection $historicalStatsForPenaltyAnalysis): array
    {
        $adjustedStatsPerGame = $weightedStatsPerGame;
        $ageModifier = 1.0;
        
        $ageCurvesConfig = Config::get('player_age_curves.dati_ruoli');
        $ageModifierParams = Config::get('player_age_curves.age_modifier_params');
        
        // --- BLOCCO AGGIUSTAMENTO ETÀ ---
        if ($player->date_of_birth && $ageCurvesConfig && $ageModifierParams) {
            $age = $player->date_of_birth->age;
            Log::debug("ProjectionEngineService: Giocatore {$player->name}, Età: {$age}");
            $roleKey = strtoupper($player->role ?? 'C');
            if ($roleKey === 'D' && !isset($ageCurvesConfig[$roleKey])) {
                $configForRole = $ageCurvesConfig['D_CENTRALE'] ?? $ageCurvesConfig['C'] ?? null;
            } else {
                $configForRole = $ageCurvesConfig[$roleKey] ?? $ageCurvesConfig['C'] ?? null;
            }
            
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
                // Applica ageModifier a gol e assist, ma non ancora ai rigori (verranno gestiti dopo)
                foreach (['goals_scored', 'assists'] as $key) {
                    if (isset($adjustedStatsPerGame[$key])) $adjustedStatsPerGame[$key] *= $ageModifier;
                }
            } else {
                Log::warning("ProjectionEngineService: Configurazione curva età non trovata o incompleta per ruolo {$roleKey}. Nessun age modifier applicato.");
            }
        }
        
        // --- BLOCCO TIER SQUADRA (PRIMA APPLICAZIONE) ---
        $teamTier = $player->team?->tier ?? 3;
        Log::debug("[DEBUG TIER] Player: {$player->name}, Team Name from DB: {$player->team?->name}, Team Tier from DB: {$teamTier}");
        $offensiveTierFactors = [1 => 1.15, 2 => 1.05, 3 => 1.00, 4 => 0.95, 5 => 0.85];
        $defensiveTierFactors = [1 => 0.85, 2 => 0.95, 3 => 1.00, 4 => 1.05, 5 => 1.15];
        $tierMultiplierOffensive = $offensiveTierFactors[$teamTier] ?? 1.0;
        $tierMultiplierDefensive = $defensiveTierFactors[$teamTier] ?? 1.0;
        Log::debug("[DEBUG TIER] Tier Multiplier Offensivo CALCOLATO per stats generali: {$tierMultiplierOffensive} (basato su teamTier: {$teamTier})");
        
        // Applica a gol (non da rigore, per ora) e assist
        if (isset($adjustedStatsPerGame['goals_scored'])) $adjustedStatsPerGame['goals_scored'] *= $tierMultiplierOffensive;
        if (isset($adjustedStatsPerGame['assists'])) $adjustedStatsPerGame['assists'] *= $tierMultiplierOffensive;
        // Applica a rigori calciati e segnati base (verranno sovrascritti se è rigorista)
        if (isset($adjustedStatsPerGame['penalties_taken'])) $adjustedStatsPerGame['penalties_taken'] *= $tierMultiplierOffensive;
        if (isset($adjustedStatsPerGame['penalties_scored'])) $adjustedStatsPerGame['penalties_scored'] *= $tierMultiplierOffensive;
        
        
        if (strtoupper($player->role ?? '') === 'P' || strtoupper($player->role ?? '') === 'D') {
            if (isset($adjustedStatsPerGame['goals_conceded'])) $adjustedStatsPerGame['goals_conceded'] *= $tierMultiplierDefensive;
        }
        
        // --- BLOCCO RIGORISTI ---
        $totalPenaltiesTakenInLookback = 0;
        $totalGamesPlayedInLookback = 0;
        $totalPenaltiesScoredInLookback = 0;
        
        if ($historicalStatsForPenaltyAnalysis->isNotEmpty()) {
            foreach ($historicalStatsForPenaltyAnalysis as $statSeason) {
                $totalPenaltiesTakenInLookback += $statSeason->penalties_taken;
                $totalPenaltiesScoredInLookback += $statSeason->penalties_scored;
                $totalGamesPlayedInLookback += $statSeason->games_played;
            }
        }
        $isLikelyPenaltyTaker = ($totalPenaltiesTakenInLookback >= $this->minPenaltiesTakenThreshold);
        // Futuro: $isLikelyPenaltyTaker = $player->is_designated_penalty_taker ?? $isLikelyPenaltyTaker;
        
        // Stats base dei rigori (già modulate da età e tier generali sulle medie storiche)
        $basePenaltiesTakenPerGame = $adjustedStatsPerGame['penalties_taken'] ?? 0;
        $basePenaltiesScoredPerGame = $adjustedStatsPerGame['penalties_scored'] ?? 0;
        // $basePenaltiesMissedPerGame = $adjustedStatsPerGame['penalties_missed'] ?? 0; // Calcolata dopo
        
        if ($isLikelyPenaltyTaker) {
            Log::info("ProjectionEngineService: Giocatore {$player->name} identificato come probabile rigorista (Storico: {$totalPenaltiesTakenInLookback} calciati).");
            
            // 1. Stima dei rigori che la squadra del giocatore potrebbe ottenere per partita
            $expectedPenaltiesForHisTeamPerGame = $this->leagueAvgPenaltiesAwardedPerTeamGame * $tierMultiplierOffensive;
            Log::debug("ProjectionEngineService: Rigorista {$player->name} - Rigori attesi per la sua squadra/partita: {$expectedPenaltiesForHisTeamPerGame} (Media Lega: {$this->leagueAvgPenaltiesAwardedPerTeamGame}, TierMultiOff: {$tierMultiplierOffensive})");
            
            // 2. Quota di questi rigori che il giocatore calcerà
            $projectedPenaltiesTakenByPlayerThisSeason = $expectedPenaltiesForHisTeamPerGame * $this->penaltyTakerShareOfTeamPenalties;
            // Applica l'ageModifier anche alla propensione a calciare rigori (un giocatore più anziano potrebbe cedere il compito)
            $projectedPenaltiesTakenByPlayerThisSeason *= $ageModifier;
            Log::debug("ProjectionEngineService: Rigorista {$player->name} - Proiezione Rigori Calciati da lui/partita: {$projectedPenaltiesTakenByPlayerThisSeason} (Quota: {$this->penaltyTakerShareOfTeamPenalties}, AgeMod: {$ageModifier})");
            
            
            // 3. Tasso di conversione storico del giocatore, con fallback
            $penaltyConversionRatePlayerHist = $this->defaultPenaltyConversionRate;
            if ($totalPenaltiesTakenInLookback > 0) {
                $penaltyConversionRatePlayerHist = $totalPenaltiesScoredInLookback / $totalPenaltiesTakenInLookback;
            }
            // Applica ageModifier al tasso di conversione
            $conversionAgeEffect = 1 + (($ageModifier - 1) * 0.2); // 20% dell'effetto età sulla conversione
            $finalPlayerConversionRate = $penaltyConversionRatePlayerHist * $conversionAgeEffect;
            $finalPlayerConversionRate = max(0.50, min(0.95, $finalPlayerConversionRate)); // Limita tra 50% e 95%
            Log::debug("ProjectionEngineService: Rigorista {$player->name} - Tasso Conversione Finale: {$finalPlayerConversionRate} (Storico: {$penaltyConversionRatePlayerHist}, AgeConvEffect: {$conversionAgeEffect})");
            
            
            // 4. Proiezione rigori segnati e sbagliati dal giocatore
            $projectedPenaltiesScoredByPlayer = $projectedPenaltiesTakenByPlayerThisSeason * $finalPlayerConversionRate;
            $projectedPenaltiesMissedByPlayer = $projectedPenaltiesTakenByPlayerThisSeason * (1 - $finalPlayerConversionRate);
            
            // 5. Aggiornamento stats e gol
            // Calcola la differenza netta di gol da rigore rispetto alla stima base
            // La stima base dei gol da rigore era $basePenaltiesScoredPerGame (già influenzata da età e tier generali)
            $netChangeInScoredPenalties = $projectedPenaltiesScoredByPlayer - $basePenaltiesScoredPerGame;
            
            $adjustedStatsPerGame['penalties_taken'] = $projectedPenaltiesTakenByPlayerThisSeason;
            $adjustedStatsPerGame['penalties_scored'] = $projectedPenaltiesScoredByPlayer;
            $adjustedStatsPerGame['penalties_missed'] = $projectedPenaltiesMissedByPlayer;
            
            // Aggiusta i gol totali con la *variazione netta* dei rigori segnati
            $adjustedStatsPerGame['goals_scored'] = ($adjustedStatsPerGame['goals_scored'] ?? 0) + $netChangeInScoredPenalties;
            Log::debug("ProjectionEngineService: Rigorista {$player->name} - Variazione Netta Gol da Rigore: {$netChangeInScoredPenalties}. Gol Totali Aggiornati: {$adjustedStatsPerGame['goals_scored']}");
            
        } else {
            // Se non è rigorista designato, le sue stats di rigori rimangono quelle delle medie ponderate (già modificate da età e tier generali)
            // Assicuriamoci che penalties_missed sia calcolato anche per i non rigoristi se hanno calciato rigori in passato
            $adjustedStatsPerGame['penalties_missed'] = ($adjustedStatsPerGame['penalties_taken'] ?? 0) - ($adjustedStatsPerGame['penalties_scored'] ?? 0);
            Log::debug("ProjectionEngineService: Giocatore {$player->name} non identificato come rigorista principale. Rigori calciati/segnati/sbagliati basati su medie storiche individuali modulate.");
        }
        
        
        // --- BLOCCO PROIEZIONE CLEAN SHEET ---
        $adjustedStatsPerGame['clean_sheet_per_game_proj'] = 0.0;
        if (strtoupper($player->role ?? '') === 'P' || strtoupper($player->role ?? '') === 'D') {
            $baseCleanSheetProbMap = [1 => 0.40, 2 => 0.30, 3 => 0.20, 4 => 0.15, 5 => 0.10];
            $probCS = $baseCleanSheetProbMap[$teamTier] ?? 0.10;
            $csAgeEffect = $ageModifierParams['cs_age_effect_ratio'] ?? 0.3;
            $probCS *= (1 + ($ageModifier - 1) * $csAgeEffect);
            $adjustedStatsPerGame['clean_sheet_per_game_proj'] = max(0, min(0.8, round($probCS, 3)));
        }
        
        // --- BLOCCO STIMA PRESENZE ---
        $basePresenze = $weightedStatsPerGame['avg_games_played'] ?? 20;
        Log::debug("[DEBUG TIER PRESENZE] Team: {$player->team?->name}, Tier DB: {$player->team?->tier}, TierMultiplierOffensive usato per presenze: {$tierMultiplierOffensive}");
        $presenzeTierFactor = 1 + (($tierMultiplierOffensive - 1) * 0.3);
        Log::debug("[DEBUG TIER PRESENZE] PresenzeTierFactor CALCOLATO: {$presenzeTierFactor}");
        
        $presenzeAgeFactor = $ageModifier;
        if ($ageModifier < 1.0 && isset($ageModifierParams)) {
            $declineEffect = $ageModifierParams['presenze_decline_effect_ratio'] ?? 1.1;
            $declineCap = $ageModifierParams['presenze_decline_cap'] ?? 0.65;
            $presenzeAgeFactor = max($declineCap, 1 - ((1 - $ageModifier) * $declineEffect));
        } elseif ($ageModifier > 1.0 && isset($ageModifierParams)) {
            $growthEffect = $ageModifierParams['presenze_growth_effect_ratio'] ?? 0.4;
            $growthCap = $ageModifierParams['presenze_growth_cap'] ?? 1.12;
            $presenzeAgeFactor = min($growthCap, 1 + (($ageModifier - 1) * $growthEffect));
        }
        
        $presenzeAttese = round($basePresenze * $presenzeTierFactor * $presenzeAgeFactor);
        $presenzeAttese = max(5, min(38, (int)$presenzeAttese));
        Log::debug("ProjectionEngineService: Stima Presenze per {$player->name} - Base:{$basePresenze}, TierFactor:{$presenzeTierFactor}, AgeFactor:{$presenzeAgeFactor} => Finale:{$presenzeAttese}");
        
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
