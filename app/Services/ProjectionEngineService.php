<?php

namespace App\Services;

use App\Models\Player;
use App\Models\HistoricalPlayerStat;
use App\Models\Team; // Assicurati sia importato se lo usi direttamente qui, altrimenti Player->team è sufficiente
use App\Models\UserLeagueProfile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class ProjectionEngineService
{
    protected FantasyPointCalculatorService $pointCalculator;
    
    public function __construct(FantasyPointCalculatorService $pointCalculator)
    {
        $this->pointCalculator = $pointCalculator;
    }
    
    /**
     * Genera le proiezioni statistiche per un singolo giocatore, inclusa la FantaMedia proiettata per partita.
     *
     * @param Player $player Il giocatore per cui generare le proiezioni.
     * @param UserLeagueProfile $leagueProfile Il profilo della lega dell'utente con le regole.
     * @param int $numberOfSeasonsToConsider Numero di stagioni storiche da considerare.
     * @param array $seasonWeights Pesi per le stagioni (es. [0.5, 0.3, 0.2] per le ultime 3, la più recente prima).
     * @return array Un array contenente le proiezioni.
     */
    public function generatePlayerProjection(
        Player $player,
        UserLeagueProfile $leagueProfile,
        int $numberOfSeasonsToConsider = 3,
        array $seasonWeights = []
        ): array {
            Log::info("ProjectionEngineService: Inizio proiezioni per giocatore ID {$player->fanta_platform_id} ({$player->name})");
            
            $historicalStats = HistoricalPlayerStat::where('player_fanta_platform_id', $player->fanta_platform_id)
            ->orderBy('season_year', 'desc') // Dalla più recente alla più vecchia
            ->take($numberOfSeasonsToConsider)
            ->get();
            
            if ($historicalStats->isEmpty()) {
                Log::warning("ProjectionEngineService: Nessuna statistica storica trovata per giocatore ID {$player->fanta_platform_id}. Utilizzo proiezioni di default per ruolo.");
                // Gestisci il caso di nessuna statistica: calcola una FM di default per partita
                $defaultStatsPerGame = $this->getDefaultStatsPerGameForRole($player->role, $player->team ? $player->team->tier : null); //
                
                $fantaMediaProjectedPerGame = $this->pointCalculator->calculateFantasyPoints(
                    $defaultStatsPerGame,
                    $leagueProfile->scoring_rules ?? [],
                    $player->role
                    );
                $defaultPresences = 15; // Valore arbitrario di default per le presenze
                
                return [
                    'stats_per_game_for_fm_calc' => $defaultStatsPerGame,
                    'mv_proj_per_game' => $defaultStatsPerGame['mv'] ?? 6.0,
                    'fanta_media_proj_per_game' => $fantaMediaProjectedPerGame,
                    'presenze_proj' => $defaultPresences,
                    'total_fantasy_points_proj' => $fantaMediaProjectedPerGame * $defaultPresences,
                    'seasonal_totals_proj' => collect($defaultStatsPerGame)->mapWithKeys(function ($value, $key) use ($defaultPresences) {
                    if ($key === 'mv' || $key === 'clean_sheet') { // MV e clean_sheet (probabilità) rimangono "per game"
                        return [$key.'_proj' => $value];
                    }
                    return [$key.'_proj' => $value * $defaultPresences];
                    })->all(),
                    ];
            }
            
            // Prepara i pesi per le stagioni se non forniti o non corrispondenti
            if (empty($seasonWeights) || count($seasonWeights) !== $historicalStats->count()) {
                $seasonWeights = $this->calculateDefaultSeasonWeights($historicalStats->count());
                Log::info("ProjectionEngineService: Pesi stagionali di default calcolati: " . json_encode($seasonWeights));
            }
            
            // 1. Calcola le medie ponderate PER PARTITA
            $weightedStatsPerGame = $this->calculateWeightedAverageStats($historicalStats, $seasonWeights);
            Log::debug("ProjectionEngineService: Statistiche medie ponderate PER PARTITA calcolate: " . json_encode($weightedStatsPerGame));
            
            // 2. Applica aggiustamenti alle statistiche PER PARTITA e ottieni le presenze attese
            $adjustmentResult = $this->applyAdjustmentsAndEstimatePresences($weightedStatsPerGame, $player, $leagueProfile);
            $adjustedStatsPerGame = $adjustmentResult['adjusted_stats_per_game'];
            $presenzeAttese = $adjustmentResult['presenze_attese'];
            Log::debug("ProjectionEngineService: Statistiche PER PARTITA aggiustate: " . json_encode($adjustedStatsPerGame));
            Log::debug("ProjectionEngineService: Presenze attese stimate: " . $presenzeAttese);
            
            // 3. Prepara l'array di statistiche PER PARTITA per il calcolatore di punti
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
                'clean_sheet' => $adjustedStatsPerGame['clean_sheet_per_game_proj'] ?? 0, //  MODIFICATO: Usa la nuova chiave se esiste
            ];
            
            // 4. Calcola la FantaMedia Proiettata PER PARTITA
            $fantaMediaProjectedPerGame = $this->pointCalculator->calculateFantasyPoints(
                $statsForFmCalculation,
                $leagueProfile->scoring_rules ?? [],
                $player->role
                );
            Log::info("ProjectionEngineService: FantaMedia proiettata PER PARTITA per {$player->name}: {$fantaMediaProjectedPerGame}");
            
            // 5. Calcola i fantapunti totali stagionali proiettati
            $totalFantasyPointsProjected = $fantaMediaProjectedPerGame * $presenzeAttese;
            Log::info("ProjectionEngineService: Fantapunti totali stagionali proiettati per {$player->name}: {$totalFantasyPointsProjected}");
            
            // 6. Scala le statistiche PER PARTITA per ottenere i totali stagionali, se ti servono
            $projectedSeasonalTotals = [];
            foreach ($adjustedStatsPerGame as $key => $valuePerGame) {
                // avg_rating e la probabilità di clean_sheet per game rimangono "per partita" o sono già una probabilità
                // Le altre vengono scalate per le presenze.
                if ($key === 'avg_rating') {
                    $projectedSeasonalTotals['mv_proj_per_game'] = $valuePerGame;
                } elseif ($key === 'clean_sheet_per_game_proj') {
                    $projectedSeasonalTotals[$key] = $valuePerGame; // o scala per presenze se vuoi il numero totale di clean sheet
                    // $projectedSeasonalTotals['total_clean_sheets_proj'] = $valuePerGame * $presenzeAttese; // Esempio
                } else {
                    $projectedSeasonalTotals[$key.'_proj'] = $valuePerGame * $presenzeAttese;
                }
            }
            // Assicurati che 'mv_proj_per_game' sia sempre presente se 'avg_rating' c'era
            if (!isset($projectedSeasonalTotals['mv_proj_per_game']) && isset($adjustedStatsPerGame['avg_rating'])) {
                $projectedSeasonalTotals['mv_proj_per_game'] = $adjustedStatsPerGame['avg_rating'];
            }
            
            
            return [
                'stats_per_game_for_fm_calc' => $statsForFmCalculation, // Dati usati per calcolare la FM/partita
                'mv_proj_per_game' => $adjustedStatsPerGame['avg_rating'] ?? 6.0,
                'fanta_media_proj_per_game' => round($fantaMediaProjectedPerGame, 2), // Arrotonda la FM
                'presenze_proj' => $presenzeAttese,
                'total_fantasy_points_proj' => round($totalFantasyPointsProjected, 2), // Arrotonda i punti totali
                'seasonal_totals_proj' => $projectedSeasonalTotals, // Contiene i totali stagionali per stat
            ];
    }
    
    /**
     * Calcola i pesi di default per le stagioni se non forniti.
     * Dà più peso alle stagioni più recenti.
     */
    private function calculateDefaultSeasonWeights(int $numberOfSeasons): array
    {
        if ($numberOfSeasons === 0) return [];
        if ($numberOfSeasons === 1) return [1.0];
        
        $weights = [];
        $totalWeightParts = 0;
        for ($i = $numberOfSeasons; $i >= 1; $i--) {
            $totalWeightParts += $i;
        }
        
        if ($totalWeightParts === 0) return array_fill(0, $numberOfSeasons, 1 / $numberOfSeasons);
        
        for ($i = $numberOfSeasons; $i >= 1; $i--) {
            $weights[] = $i / $totalWeightParts;
        }
        return $weights;
    }
    
    /**
     * Calcola le medie ponderate delle statistiche chiave PER PARTITA.
     */
    private function calculateWeightedAverageStats(Collection $historicalStats, array $seasonWeights): array
    {
        $weightedAverages = [
            'avg_rating' => 0.0, 'goals_scored' => 0.0, 'assists' => 0.0,
            'yellow_cards' => 0.0, 'red_cards' => 0.0, 'own_goals' => 0.0,
            'penalties_scored' => 0.0, 'penalties_missed' => 0.0,
            'penalties_saved' => 0.0, 'goals_conceded' => 0.0,
            'avg_games_played' => 0.0, // Media ponderata delle presenze storiche, usata per stimare le future
        ];
        $totalWeightSumForPerGameStats = 0;
        
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
        }
        
        if ($totalWeightSumForPerGameStats > 0) {
            foreach (['avg_rating', 'goals_scored', 'assists', 'yellow_cards', 'red_cards', 'own_goals', 'penalties_scored', 'penalties_missed', 'penalties_saved', 'goals_conceded'] as $key) {
                if (isset($weightedAverages[$key])) { // Aggiunto controllo isset
                    $weightedAverages[$key] = $weightedAverages[$key] / $totalWeightSumForPerGameStats;
                }
            }
        }
        // avg_games_played è già una media ponderata corretta, non necessita di divisione per $totalWeightSumForPerGameStats
        // perché i suoi pesi sono già normalizzati implicitamente dalla somma dei pesi stessi.
        // Se $seasonWeights somma a 1, $weightedAverages['avg_games_played'] è già la media ponderata.
        
        
        return $weightedAverages;
    }
    
    /**
     * Applica aggiustamenti alle statistiche ponderate PER PARTITA e stima le presenze.
     * Restituisce un array con 'adjusted_stats_per_game' e 'presenze_attese'.
     */
    private function applyAdjustmentsAndEstimatePresences(array $weightedStatsPerGame, Player $player, UserLeagueProfile $leagueProfile): array
    {
        $adjustedStatsPerGame = $weightedStatsPerGame;
        $ageModifier = 1.0; // Default, nessun modificatore
        
        // Carica la configurazione delle curve di età
        $ageCurveConfigData = config('player_age_curves.dati_ruoli');
        $ageModParams = config('player_age_curves.age_modifier_params');
        
        if ($player->date_of_birth && $ageCurveConfigData && $ageModParams) {
            $age = $player->date_of_birth->age;
            Log::debug("ProjectionEngineService: Giocatore {$player->name}, Età: {$age}");
            
            $roleKey = strtoupper($player->role);
            // Gestione preliminare per ruoli Difensivi specifici se hai intenzione di usare D_CENTRALE/D_ESTERNO
            // Per ora, se il ruolo è 'D', usiamo una configurazione generica per 'D' o una media.
            // O, per semplicità iniziale, mappa tutti i 'D' a 'D_CENTRALE' o a una nuova entry 'D' nel config.
            // Per questo esempio, aggiungo una chiave 'D' generica al config se non hai distinto
            // Se non c'è 'D' ma ci sono 'D_CENTRALE' e 'D_ESTERNO', scegliamo D_CENTRALE come fallback per 'D'.
            if ($roleKey === 'D' && !isset($ageCurveConfigData[$roleKey])) {
                $config = $ageCurveConfigData['D_CENTRALE'] ?? ($ageCurveConfigData['D_ESTERNO'] ?? null); // Fallback
            } else {
                $config = $ageCurveConfigData[$roleKey] ?? null;
            }
            
            
            if ($config) {
                if ($age <= $config['fasi_carriera']['sviluppo_fino_a']) {
                    // Fase di sviluppo, potrebbe essere prima di peak_start
                    $ageModifier = min($config['young_cap'], 1.0 + (($config['fasi_carriera']['picco_inizio'] - $age) * $config['growth_factor']));
                } elseif ($age >= $config['fasi_carriera']['picco_inizio'] && $age <= $config['fasi_carriera']['picco_fine']) {
                    // Fase di picco - ageModifier rimane 1.0 o un piccolo bonus
                    // $ageModifier = 1.02; // Esempio di piccolo bonus per il picco
                } elseif ($age > $config['fasi_carriera']['picco_fine'] && $age <= $config['fasi_carriera']['mantenimento_fino_a']) {
                    // Fase di mantenimento/declino graduale
                    // Applica una frazione del decline_factor o un decline_factor più piccolo
                    $declineFactorGradual = $config['decline_factor'] * 0.5; // Esempio: metà del fattore di declino
                    $ageModifier = max($config['old_cap'], 1.0 - (($age - $config['fasi_carriera']['picco_fine']) * $declineFactorGradual));
                } elseif ($age >= $config['fasi_carriera']['declino_da']) {
                    // Fase di declino più marcato
                    // Calcola il declino a partire dalla fine del picco per continuità, o da decline_da per un salto
                    $effectiveDeclineStartAge = $config['fasi_carriera']['picco_fine']; // O $config['fasi_carriera']['declino_da'] se vuoi un "salto"
                    $ageModifier = max($config['old_cap'], 1.0 - (($age - $effectiveDeclineStartAge) * $config['decline_factor']));
                }
                // Assicurati che ageModifier non sia diventato 0 o negativo se i calcoli sono strani
                $ageModifier = max(0.1, $ageModifier); // Minimo 0.1
                
                Log::debug("ProjectionEngineService: Giocatore {$player->name}, Età: {$age}, Ruolo: {$roleKey}, Config Ruolo: " . json_encode($config) . ", Modificatore Età Calcolato: {$ageModifier}");
                
                if (isset($adjustedStatsPerGame['avg_rating'])) {
                    $adjustedStatsPerGame['avg_rating'] *= (1 + ($ageModifier - 1) * $ageModParams['mv_effect_ratio']);
                    $adjustedStatsPerGame['avg_rating'] = round($adjustedStatsPerGame['avg_rating'], 4);
                }
                foreach (['goals_scored', 'assists'] as $key) {
                    if (isset($adjustedStatsPerGame[$key])) {
                        $adjustedStatsPerGame[$key] *= $ageModifier;
                    }
                }
            } else {
                Log::warning("ProjectionEngineService: Nessuna configurazione curva età trovata per Ruolo: {$roleKey} per giocatore {$player->name}.");
            }
        } else {
            Log::debug("ProjectionEngineService: Data di nascita non disponibile per {$player->name} o configurazione curve età mancante, salto aggiustamento età.");
        }
        // --- FINE BLOCCO AGGIUSTAMENTO ETÀ ---
        
        // --- BLOCCO TIER SQUADRA (come prima, assicurati che $player->team->tier sia corretto) ---
        $teamTier = $player->team?->tier ?? 3;
        $offensiveTierFactors = [1 => 1.15, 2 => 1.05, 3 => 1.00, 4 => 0.95, 5 => 0.85]; // Da config?
        $defensiveTierFactors = [1 => 0.85, 2 => 0.95, 3 => 1.00, 4 => 1.05, 5 => 1.15]; // Da config?
        $tierMultiplierOffensive = $offensiveTierFactors[$teamTier] ?? 1.0;
        $tierMultiplierDefensive = $defensiveTierFactors[$teamTier] ?? 1.0;
        
        foreach (['goals_scored', 'assists'] as $key) {
            if (isset($adjustedStatsPerGame[$key])) {
                $adjustedStatsPerGame[$key] *= $tierMultiplierOffensive;
            }
        }
        if (strtoupper($player->role) === 'P') {
            if (isset($adjustedStatsPerGame['goals_conceded'])) {
                $adjustedStatsPerGame['goals_conceded'] *= $tierMultiplierDefensive;
            }
        }
        // --- FINE BLOCCO TIER SQUADRA ---
        
        
        // --- BLOCCO PROIEZIONE CLEAN SHEET (come prima, con $ageModParams) ---
        $adjustedStatsPerGame['clean_sheet_per_game_proj'] = 0.0;
        if (strtoupper($player->role) === 'P' || strtoupper($player->role) === 'D') {
            $baseCleanSheetProb = [1 => 0.40, 2 => 0.30, 3 => 0.20, 4 => 0.15, 5 => 0.10]; // Da config?
            $probCS = $baseCleanSheetProb[$teamTier] ?? 0.10;
            $probCS *= (1 + ($ageModifier - 1) * $ageModParams['cs_age_effect_ratio']);
            $adjustedStatsPerGame['clean_sheet_per_game_proj'] = max(0.05, min(0.75, round($probCS,3)));
        }
        // --- FINE BLOCCO PROIEZIONE CLEAN SHEET ---
        
        // --- BLOCCO STIMA PRESENZE (come prima, con $ageModParams) ---
        $basePresenze = $weightedStatsPerGame['avg_games_played'] ?? 20;
        $presenzeTierFactor = 1 + (($tierMultiplierOffensive - 1) * 0.3);
        $presenzeAgeFactor = $ageModifier;
        if ($ageModifier < 1.0) {
            $presenzeAgeFactor = 1 - ((1 - $ageModifier) * $ageModParams['presenze_decline_effect_ratio']);
            $presenzeAgeFactor = max($ageModParams['presenze_decline_cap'], $presenzeAgeFactor);
        } elseif ($ageModifier > 1.0) {
            $presenzeAgeFactor = 1 + (($ageModifier - 1) * $ageModParams['presenze_growth_effect_ratio']);
            $presenzeAgeFactor = min($ageModParams['presenze_growth_cap'], $presenzeAgeFactor);
        }
        $presenzeAttese = round($basePresenze * $presenzeTierFactor * $presenzeAgeFactor);
        $presenzeAttese = max(5, min(38, (int)$presenzeAttese));
        Log::debug("ProjectionEngineService: Stima Presenze per {$player->name} - Base:{$basePresenze}, TierFactor:{$presenzeTierFactor}, AgeFactor:{$presenzeAgeFactor} => Finale:{$presenzeAttese}");
        // --- FINE BLOCCO STIMA PRESENZE ---
        
        if (isset($adjustedStatsPerGame['avg_games_played'])) {
            unset($adjustedStatsPerGame['avg_games_played']);
        }
        foreach($adjustedStatsPerGame as $key => &$value) {
            if(is_numeric($value)) {
                $value = round($value, 4);
            }
        }
        return [
            'adjusted_stats_per_game' => $adjustedStatsPerGame,
            'presenze_attese' => $presenzeAttese,
        ];
    }
    
    /**
     * Fornisce statistiche di default PER PARTITA se non ci sono dati storici.
     */
    private function getDefaultStatsPerGameForRole(string $role, ?int $teamTier): array
    {
        Log::warning("ProjectionEngineService: Utilizzo stats di default PER PARTITA per ruolo {$role}, tier {$teamTier}");
        $baseMv = 5.8;
        $baseGoalsPerGame = 0.0;
        $baseAssistsPerGame = 0.0;
        $baseYellowCardsPerGame = 0.1; // ~1 ogni 10 partite
        $baseRedCardsPerGame = 0.005; // ~1 ogni 200 partite
        $baseOwnGoalsPerGame = 0.002;
        $basePenScoredPerGame = 0.0;
        $basePenMissedPerGame = 0.0;
        $basePenSavedPerGame = 0.0; // Solo Portieri
        $baseGoalsConcededPerGame = 0.0; // Solo Portieri
        $baseCleanSheetProb = 0.0; // Solo P/D
        
        switch (strtoupper($role)) {
            case 'P':
                $baseMv = 6.0; $baseCleanSheetProb = 0.20; $baseGoalsConcededPerGame = 1.3; $basePenSavedPerGame = 0.05;
                break;
            case 'D':
                $baseMv = 5.9; $baseGoalsPerGame = 0.03; $baseAssistsPerGame = 0.03; $baseCleanSheetProb = 0.20;
                break;
            case 'C':
                $baseMv = 6.0; $baseGoalsPerGame = 0.10; $baseAssistsPerGame = 0.10;
                break;
            case 'A':
                $baseMv = 6.1; $baseGoalsPerGame = 0.35; $baseAssistsPerGame = 0.08;
                break;
        }
        
        $tierMultiplierOffensive = 1.0;
        $tierMultiplierDefensiveFactor = 1.0; // Usato per invertire l'effetto su stats difensive
        if ($teamTier) {
            switch ($teamTier) {
                case 1: $tierMultiplierOffensive = 1.15; $tierMultiplierDefensiveFactor = 0.85; break;
                case 2: $tierMultiplierOffensive = 1.05; $tierMultiplierDefensiveFactor = 0.95; break;
                case 3: $tierMultiplierOffensive = 1.0;  $tierMultiplierDefensiveFactor = 1.0; break;
                case 4: $tierMultiplierOffensive = 0.95; $tierMultiplierDefensiveFactor = 1.05; break;
                case 5: $tierMultiplierOffensive = 0.85; $tierMultiplierDefensiveFactor = 1.15; break;
            }
        }
        
        return [
            'mv' => $baseMv,
            'gol_fatti' => round($baseGoalsPerGame * $tierMultiplierOffensive, 3),
            'assist' => round($baseAssistsPerGame * $tierMultiplierOffensive, 3),
            'ammonizioni' => $baseYellowCardsPerGame,
            'espulsioni' => $baseRedCardsPerGame,
            'autogol' => $baseOwnGoalsPerGame,
            'rigori_segnati' => $basePenScoredPerGame, // Andrebbe stimato meglio
            'rigori_sbagliati' => $basePenMissedPerGame,
            'rigori_parati' => (strtoupper($role) === 'P' ? $basePenSavedPerGame : 0.0),
            'gol_subiti' => (strtoupper($role) === 'P' ? round($baseGoalsConcededPerGame * $tierMultiplierDefensiveFactor, 2) : 0.0),
            'clean_sheet' => ((strtoupper($role) === 'P' || strtoupper($role) === 'D') ? round($baseCleanSheetProb / $tierMultiplierDefensiveFactor, 2) : 0.0),
        ];
    }
}