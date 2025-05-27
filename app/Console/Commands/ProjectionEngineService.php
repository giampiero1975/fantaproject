<?php

namespace App\Services;

use App\Models\Player;
use App\Models\HistoricalPlayerStat;
use App\Models\Team;
use App\Models\UserLeagueProfile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Carbon\Carbon; // NECESSARIO per calcolare l'età

class ProjectionEngineService
{
    protected FantasyPointCalculatorService $pointCalculator;
    
    public function __construct(FantasyPointCalculatorService $pointCalculator)
    {
        $this->pointCalculator = $pointCalculator;
    }
    
    public function generatePlayerProjection(
        Player $player,
        UserLeagueProfile $leagueProfile,
        int $numberOfSeasonsToConsider = 3,
        array $seasonWeights = []
        ): array {
            Log::info("ProjectionEngineService: Inizio proiezioni per giocatore ID {$player->fanta_platform_id} ({$player->name})");
            
            $historicalStats = HistoricalPlayerStat::where('player_fanta_platform_id', $player->fanta_platform_id)
            ->orderBy('season_year', 'desc')
            ->take($numberOfSeasonsToConsider)
            ->get();
            
            if ($historicalStats->isEmpty()) {
                Log::warning("ProjectionEngineService: Nessuna statistica storica trovata per giocatore ID {$player->fanta_platform_id}. Utilizzo proiezioni di default per ruolo.");
                $defaultStatsPerGame = $this->getDefaultStatsPerGameForRole($player->role, $player->team?->tier, $player->date_of_birth?->age); // Passa l'età se disponibile
                
                $fantaMediaProjectedPerGame = $this->pointCalculator->calculateFantasyPoints(
                    $defaultStatsPerGame,
                    $leagueProfile->scoring_rules ?? [],
                    $player->role
                    );
                $defaultPresences = $this->estimateDefaultPresences($player->role, $player->team?->tier, $player->date_of_birth?->age);
                
                return [
                    'stats_per_game_for_fm_calc' => $defaultStatsPerGame,
                    'mv_proj_per_game' => $defaultStatsPerGame['mv'] ?? 6.0,
                    'fanta_media_proj_per_game' => round($fantaMediaProjectedPerGame, 2),
                    'presenze_proj' => $defaultPresences,
                    'total_fantasy_points_proj' => round($fantaMediaProjectedPerGame * $defaultPresences, 2),
                    'seasonal_totals_proj' => collect($defaultStatsPerGame)->mapWithKeys(function ($value, $key) use ($defaultPresences) {
                    if ($key === 'mv' || $key === 'clean_sheet') {
                        return [$key.'_proj' => $value];
                    }
                    return [$key.'_proj' => round($value * $defaultPresences, 2)];
                    })->all(),
                    ];
            }
            
            if (empty($seasonWeights) || count($seasonWeights) !== $historicalStats->count()) {
                $seasonWeights = $this->calculateDefaultSeasonWeights($historicalStats->count());
            }
            
            $weightedStatsPerGame = $this->calculateWeightedAverageStats($historicalStats, $seasonWeights);
            Log::debug("ProjectionEngineService: Statistiche medie ponderate PER PARTITA calcolate: " . json_encode($weightedStatsPerGame));
            
            $adjustmentResult = $this->applyAdjustmentsAndEstimatePresences($weightedStatsPerGame, $player, $leagueProfile);
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
                    $projectedSeasonalTotals['mv_proj_per_game'] = round($valuePerGame,2);
                } elseif ($key === 'clean_sheet_per_game_proj') {
                    $projectedSeasonalTotals[$key] = round($valuePerGame,2);
                } else {
                    // Non scalare le medie delle medie (come avg_rating) che sono già "per game"
                    // Scala solo le stats che sono contatori per partita (gol/partita, assist/partita etc.)
                    if(!in_array($key, ['avg_rating', 'avg_games_played'])){ // avg_games_played è già stato rimosso
                        $projectedSeasonalTotals[$key.'_proj'] = round($valuePerGame * $presenzeAttese, 2);
                    }
                }
            }
            if (!isset($projectedSeasonalTotals['mv_proj_per_game']) && isset($adjustedStatsPerGame['avg_rating'])) {
                $projectedSeasonalTotals['mv_proj_per_game'] = round($adjustedStatsPerGame['avg_rating'],2);
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
        ];
        $totalWeightSumForPerGameStats = 0;
        $totalWeightSumForGamesPlayed = 0; // Usato per normalizzare avg_games_played
        
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
                $weightedAverages['penalties_missed'] += ($stats->penalties_missed / $games) * $weight;
                $weightedAverages['penalties_saved'] += ($stats->penalties_saved / $games) * $weight;
                $weightedAverages['goals_conceded'] += ($stats->goals_conceded / $games) * $weight;
                $totalWeightSumForPerGameStats += $weight;
            }
            $weightedAverages['avg_games_played'] += $stats->games_played * $weight;
            $totalWeightSumForGamesPlayed += $weight; // Somma i pesi usati per avg_games_played
        }
        
        // Normalizza le medie per partita
        if ($totalWeightSumForPerGameStats > 0) {
            foreach (['avg_rating', 'goals_scored', 'assists', 'yellow_cards', 'red_cards', 'own_goals', 'penalties_scored', 'penalties_missed', 'penalties_saved', 'goals_conceded'] as $key) {
                if (isset($weightedAverages[$key])) {
                    $weightedAverages[$key] = $weightedAverages[$key] / $totalWeightSumForPerGameStats;
                }
            }
        }
        // Normalizza avg_games_played se la somma dei pesi non è esattamente 1 (potrebbe succedere con pesi custom)
        if ($totalWeightSumForGamesPlayed > 0 && $totalWeightSumForGamesPlayed != 1) {
            $weightedAverages['avg_games_played'] = $weightedAverages['avg_games_played'] / $totalWeightSumForGamesPlayed;
        }
        
        return $weightedAverages;
    }
    
    private function applyAdjustmentsAndEstimatePresences(array $weightedStatsPerGame, Player $player, UserLeagueProfile $leagueProfile): array
    {
        $adjustedStatsPerGame = $weightedStatsPerGame;
        $ageModifier = 1.0;
        
        // --- INIZIO BLOCCO AGGIUSTAMENTO ETÀ ---
        if ($player->date_of_birth) {
            $age = $player->date_of_birth->age; // Carbon calcola l'età
            Log::debug("ProjectionEngineService: Giocatore {$player->name}, Età: {$age}");
            
            // Struttura di configurazione per età di picco e fattori per ruolo
            // Questi valori sono ESEMPLIFICATIVI e necessitano di CALIBRAZIONE!
            $peakAgeConfig = [
                'P' => ['peak_start' => 27, 'peak_end' => 33, 'growth_factor' => 0.010, 'decline_factor' => 0.015, 'young_cap' => 1.10, 'old_cap' => 0.80],
                'D' => ['peak_start' => 26, 'peak_end' => 31, 'growth_factor' => 0.015, 'decline_factor' => 0.025, 'young_cap' => 1.15, 'old_cap' => 0.75],
                'C' => ['peak_start' => 25, 'peak_end' => 30, 'growth_factor' => 0.020, 'decline_factor' => 0.030, 'young_cap' => 1.20, 'old_cap' => 0.70],
                'A' => ['peak_start' => 24, 'peak_end' => 29, 'growth_factor' => 0.025, 'decline_factor' => 0.035, 'young_cap' => 1.25, 'old_cap' => 0.65],
            ];
            
            $roleKey = strtoupper($player->role);
            $config = $peakAgeConfig[$roleKey] ?? null;
            
            if ($config) {
                if ($age < $config['peak_start']) {
                    $ageModifier = min($config['young_cap'], 1.0 + (($config['peak_start'] - $age) * $config['growth_factor']));
                } elseif ($age > $config['peak_end']) {
                    $ageModifier = max($config['old_cap'], 1.0 - (($age - $config['peak_end']) * $config['decline_factor']));
                }
                // Nessun modificatore aggiuntivo se in età di picco (ageModifier rimane 1.0)
                // o potresti aggiungere un piccolo bonus: else { $ageModifier = 1.02; }
            }
            Log::debug("ProjectionEngineService: Giocatore {$player->name}, Età: {$age}, Ruolo: {$roleKey}, Modificatore Età: {$ageModifier}");
            
            // Applica il modificatore età alle stats chiave (non cartellini o autogol)
            // Per la MV, l'effetto dell'età potrebbe essere più complesso e meno lineare,
            // quindi applichiamo un effetto "smorzato" del modificatore.
            if (isset($adjustedStatsPerGame['avg_rating'])) {
                $mvEffectRatio = 0.5; // Quanto del modificatore età influenza la MV (0.5 = 50%)
                $adjustedStatsPerGame['avg_rating'] *= (1 + ($ageModifier - 1) * $mvEffectRatio);
            }
            foreach (['goals_scored', 'assists'] as $key) { // Aggiungi altre stats offensive/creative se necessario
                if (isset($adjustedStatsPerGame[$key])) {
                    $adjustedStatsPerGame[$key] *= $ageModifier;
                }
            }
        }
        // --- FINE BLOCCO AGGIUSTAMENTO ETÀ ---
        
        // --- INIZIO BLOCCO TIER SQUADRA ---
        $teamTier = $player->team?->tier ?? 3; // Usa la relazione team per accedere al tier
        $tierMultiplier = 1.0;
        // Fattori specifici per attacco e difesa basati sul tier
        $offensiveTierFactors = [1 => 1.15, 2 => 1.05, 3 => 1.00, 4 => 0.95, 5 => 0.85];
        $defensiveTierFactors = [1 => 0.85, 2 => 0.95, 3 => 1.00, 4 => 1.05, 5 => 1.15]; // Inverso per gol subiti/clean sheet
        
        $tierMultiplierOffensive = $offensiveTierFactors[$teamTier] ?? 1.0;
        $tierMultiplierDefensive = $defensiveTierFactors[$teamTier] ?? 1.0;
        
        // Applica tier multiplier offensivo
        foreach (['goals_scored', 'assists'] as $key) {
            if (isset($adjustedStatsPerGame[$key])) {
                $adjustedStatsPerGame[$key] *= $tierMultiplierOffensive;
            }
        }
        // Applica tier multiplier difensivo
        if (strtoupper($player->role) === 'P' || strtoupper($player->role) === 'D') {
            if (isset($adjustedStatsPerGame['goals_conceded'])) { // Per Portieri
                $adjustedStatsPerGame['goals_conceded'] *= $tierMultiplierDefensive;
            }
        }
        // --- FINE BLOCCO TIER SQUADRA ---
        
        
        // --- INIZIO BLOCCO PROIEZIONE CLEAN SHEET (basilare) ---
        $adjustedStatsPerGame['clean_sheet_per_game_proj'] = 0.0;
        if (strtoupper($player->role) === 'P' || strtoupper($player->role) === 'D') {
            // Stima base della probabilità di clean sheet per tier
            $baseCleanSheetProb = [1 => 0.40, 2 => 0.30, 3 => 0.20, 4 => 0.15, 5 => 0.10];
            $probCS = $baseCleanSheetProb[$teamTier] ?? 0.10;
            
            // L'età e la forma individuale potrebbero influenzare leggermente (ma la CS è molto di squadra)
            // Applichiamo una modulazione più leggera dell'ageModifier qui
            $csAgeEffectRatio = 0.3; // Solo il 30% dell'effetto del modificatore età
            $probCS *= (1 + ($ageModifier - 1) * $csAgeEffectRatio);
            $adjustedStatsPerGame['clean_sheet_per_game_proj'] = max(0, min(0.8, round($probCS,3))); // Limita tra 0 e 80%
        }
        // --- FINE BLOCCO PROIEZIONE CLEAN SHEET ---
        
        
        // --- INIZIO BLOCCO STIMA PRESENZE ---
        $basePresenze = $weightedStatsPerGame['avg_games_played'] ?? 20; // Fallback se non disponibile
        
        // Modulazione presenze per tier (più leggero rispetto a stats offensive)
        $presenzeTierFactor = 1 + (($tierMultiplierOffensive - 1) * 0.3); // Es. 30% dell'impatto del tier offensivo
        
        // Modulazione presenze per età (più forte per declino)
        $presenzeAgeFactor = $ageModifier;
        if ($ageModifier < 1.0) { // Se in declino, l'effetto sulle presenze è più marcato
            $presenzeAgeFactor = 1 - ((1 - $ageModifier) * 1.5); // Amplifica leggermente il declino per le presenze
            $presenzeAgeFactor = max(0.5, $presenzeAgeFactor); // Non scendere sotto il 50%
        } elseif ($ageModifier > 1.0) { // Se in crescita, effetto più contenuto (non è detto giochi di più solo perché giovane)
            $presenzeAgeFactor = 1 + (($ageModifier - 1) * 0.5);
            $presenzeAgeFactor = min(1.15, $presenzeAgeFactor); // Max +15% presenze per crescita
        }
        
        $presenzeAttese = round($basePresenze * $presenzeTierFactor * $presenzeAgeFactor);
        $presenzeAttese = max(5, min(38, (int)$presenzeAttese)); // Minimo 5 presenze, massimo 38
        
        Log::debug("ProjectionEngineService: Stima Presenze per {$player->name} - Base:{$basePresenze}, TierFactor:{$presenzeTierFactor}, AgeFactor:{$presenzeAgeFactor} => Finale:{$presenzeAttese}");
        // --- FINE BLOCCO STIMA PRESENZE ---
        
        // Rimuovi avg_games_played dalle stats PER PARTITA ora che abbiamo presenze_attese
        if (isset($adjustedStatsPerGame['avg_games_played'])) {
            unset($adjustedStatsPerGame['avg_games_played']);
        }
        
        return [
            'adjusted_stats_per_game' => $adjustedStatsPerGame,
            'presenze_attese' => $presenzeAttese,
        ];
    }
    
    private function estimateDefaultPresences(?string $role, ?int $teamTier, ?int $age): int
    {
        $base = 20; // Default molto generico
        if ($role === 'P' && $teamTier <=2) $base = 30; // Portiere titolare in buona squadra
        elseif ($role === 'A' && $teamTier <=2) $base = 28;
        elseif ($role === 'C' && $teamTier <=2) $base = 26;
        elseif ($role === 'D' && $teamTier <=2) $base = 25;
        
        // Semplice riduzione per età avanzata
        if ($age && $age > 33) $base *= 0.8;
        if ($age && $age > 36) $base *= 0.7;
        return max(5,min(38, (int)round($base)));
    }
    
    
    private function getDefaultStatsPerGameForRole(?string $role, ?int $teamTier, ?int $age): array
    {
        Log::warning("ProjectionEngineService: Utilizzo stats di default PER PARTITA per ruolo {$role}, tier {$teamTier}, età {$age}");
        $baseMv = 5.8; $baseGoalsPerGame = 0.0; $baseAssistsPerGame = 0.0;
        $baseYellowCardsPerGame = 0.1; $baseRedCardsPerGame = 0.005; $baseOwnGoalsPerGame = 0.002;
        $basePenScoredPerGame = 0.0; $basePenMissedPerGame = 0.0; $basePenSavedPerGame = 0.0;
        $baseGoalsConcededPerGame = 0.0; $baseCleanSheetProb = 0.0;
        
        $roleKey = strtoupper($role ?? 'C'); // Default a 'C' se ruolo è null
        
        switch ($roleKey) {
            case 'P': $baseMv = 6.0; $baseCleanSheetProb = 0.20; $baseGoalsConcededPerGame = 1.3; $basePenSavedPerGame = 0.02; break;
            case 'D': $baseMv = 5.9; $baseGoalsPerGame = 0.03; $baseAssistsPerGame = 0.03; $baseCleanSheetProb = 0.20; break;
            case 'C': $baseMv = 6.0; $baseGoalsPerGame = 0.08; $baseAssistsPerGame = 0.08; break;
            case 'A': $baseMv = 6.05; $baseGoalsPerGame = 0.30; $baseAssistsPerGame = 0.06; break;
        }
        
        $offensiveTierFactors = [1 => 1.15, 2 => 1.05, 3 => 1.00, 4 => 0.95, 5 => 0.85];
        $defensiveTierFactors = [1 => 0.85, 2 => 0.95, 3 => 1.00, 4 => 1.05, 5 => 1.15];
        $tierOffensive = $offensiveTierFactors[$teamTier ?? 3] ?? 1.0;
        $tierDefensive = $defensiveTierFactors[$teamTier ?? 3] ?? 1.0;
        
        // Semplice modificatore età per default stats (meno granulare rispetto all'aggiustamento principale)
        $ageModifierDefault = 1.0;
        if ($age) {
            if ($age < 23) $ageModifierDefault = 1.05; // Leggero boost per giovani
            elseif ($age > 32 && !in_array($roleKey, ['P'])) $ageModifierDefault = 0.85; // Declino
            elseif ($age > 35 && $roleKey === 'P') $ageModifierDefault = 0.90; // Declino portieri più tardi
        }
        
        return [
            'mv' => round($baseMv * $ageModifierDefault, 2),
            'gol_fatti' => round($baseGoalsPerGame * $tierOffensive * $ageModifierDefault, 3),
            'assist' => round($baseAssistsPerGame * $tierOffensive * $ageModifierDefault, 3),
            'ammonizioni' => $baseYellowCardsPerGame, 'espulsioni' => $baseRedCardsPerGame, 'autogol' => $baseOwnGoalsPerGame,
            'rigori_segnati' => $basePenScoredPerGame, 'rigori_sbagliati' => $basePenMissedPerGame,
            'rigori_parati' => ($roleKey === 'P' ? $basePenSavedPerGame : 0.0),
            'gol_subiti' => ($roleKey === 'P' ? round($baseGoalsConcededPerGame * $tierDefensive / $ageModifierDefault, 2) : 0.0), // Inverso per età per gol subiti
            'clean_sheet' => (($roleKey === 'P' || $roleKey === 'D') ? round($baseCleanSheetProb / $tierDefensive * $ageModifierDefault, 2) : 0.0), // Modulato da età
        ];
    }
}