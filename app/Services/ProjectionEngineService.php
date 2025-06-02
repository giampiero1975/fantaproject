<?php

namespace App\Services;

use App\Models\Player;
use App\Models\HistoricalPlayerStat;
use App\Models\Team; // Aggiunto se necessario per info team da player
use App\Models\UserLeagueProfile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
// Collection non è usata direttamente qui come type hint, ma Eloquent la usa internamente

class ProjectionEngineService
{
    protected FantasyPointCalculatorService $pointCalculator;
    protected array $settings; // Per projection_settings
    protected array $ageCurveSettings; // Per player_age_curves
    
    // Parametri per la logica dei rigoristi (potrebbero essere in $settings)
    protected int $penaltyTakerLookbackSeasons;
    protected int $minPenaltiesTakenThreshold;
    protected float $leagueAvgPenaltiesAwardedPerTeamGame;
    protected float $penaltyTakerShareOfTeamPenalties;
    protected float $defaultPenaltyConversionRate;
    protected float $minPenaltiesTakenForReliableConversionRate;
    protected float $minPenaltyConversionRate;
    protected float $maxPenaltyConversionRate;
    
    
    public function __construct(FantasyPointCalculatorService $pointCalculator)
    {
        $this->pointCalculator = $pointCalculator;
        $this->settings = Config::get('projection_settings');
        $this->ageCurveSettings = Config::get('player_age_curves');
        
        if (empty($this->settings)) {
            Log::critical(self::class . " Errore CRITICO: File di configurazione 'projection_settings.php' non caricato o vuoto.");
            // In un'applicazione reale, potresti voler lanciare un'eccezione qui per fermare l'esecuzione
            // throw new \Exception("Configurazione projection_settings mancante o vuota.");
        }
        if (empty($this->ageCurveSettings)) {
            Log::critical(self::class . " Errore CRITICO: File di configurazione 'player_age_curves.php' non caricato o vuoto.");
        }
        
        // Inizializzazione parametri rigoristi da config, con fallback
        $this->penaltyTakerLookbackSeasons = $this->settings['penalty_logic']['penalty_taker_lookback_seasons'] ?? 2;
        $this->minPenaltiesTakenThreshold = $this->settings['penalty_logic']['min_penalties_taken_threshold'] ?? 3;
        $this->leagueAvgPenaltiesAwardedPerTeamGame = $this->settings['penalty_logic']['league_avg_penalties_awarded'] ?? 0.20;
        $this->penaltyTakerShareOfTeamPenalties = $this->settings['penalty_logic']['penalty_taker_share'] ?? 0.85;
        $this->defaultPenaltyConversionRate = $this->settings['penalty_logic']['default_penalty_conversion_rate'] ?? 0.75;
        $this->minPenaltiesTakenForReliableConversionRate = $this->settings['penalty_logic']['min_penalties_taken_for_reliable_conversion_rate'] ?? 5;
        $this->minPenaltyConversionRate = $this->settings['penalty_logic']['min_penalty_conversion_rate'] ?? 0.50;
        $this->maxPenaltyConversionRate = $this->settings['penalty_logic']['max_penalty_conversion_rate'] ?? 0.95;
    }
    
    // ... (altri metodi come generatePlayerProjection, applyAdjustmentsAndEstimatePresences, etc. rimangono qui) ...
    // Assicurati che il metodo generatePlayerProjection chiami calculateWeightedAverageStats in questo modo:
    // $weightedStatsPerGame = $this->calculateWeightedAverageStats($player);
    // E che la gestione del caso ->isEmpty() in generatePlayerProjection sia robusta.
    
    private function calculateWeightedAverageStats(Player $player): array
    {
        // Definisci lo schema di output atteso con valori di default
        $defaultAverages = [];
        // Assicurati che 'fields_to_project' sia definito in projection_settings.php
        $fieldsToProject = $this->settings['fields_to_project'] ?? [
            'avg_rating', 'goals_scored', 'assists', 'yellow_cards', 'red_cards',
            'own_goals', 'penalties_taken', 'penalties_scored', 'penalties_missed',
            'penalties_saved', 'goals_conceded'
        ];
        // Rimuovi fanta_avg_rating se presente, verrà calcolato dopo e non è uno storico diretto
        $fieldsToProject = array_diff($fieldsToProject, ['fanta_avg_rating']);
        
        foreach ($fieldsToProject as $field) {
            $defaultAverages[$field] = 0.0;
        }
        $defaultAverages['avg_games_played'] = 0.0;
        $defaultAverages['avg_rating'] = $this->settings['fallback_mv_if_no_history'] ?? 5.5;
        
        
        if (empty($this->settings) || !isset($this->settings['lookback_seasons'])) {
            Log::critical(self::class . " calculateWeightedAverageStats: Settings non caricate o 'lookback_seasons' chiave mancante. Restituisco defaultAverages per giocatore {$player->name}.");
            return $defaultAverages;
        }
        
        $lookbackSeasonsForAvg = $this->settings['lookback_seasons'];
        $historicalStats = HistoricalPlayerStat::where('player_fanta_platform_id', $player->fanta_platform_id)
        ->orderBy('season_year', 'desc')
        ->take((int)$lookbackSeasonsForAvg)
        ->get();
        
        // Inizializza $averageStats con lo schema di default
        // Questo assicura che tutte le chiavi attese siano presenti nell'array restituito
        $averageStats = $defaultAverages;
        
        if ($historicalStats->isEmpty()) {
            Log::warning(self::class." Nessuno storico per giocatore {$player->name} (ID: {$player->fanta_platform_id}) nel lookback di {$lookbackSeasonsForAvg} stagioni. Restituisco defaultAverages (con fallback MV già impostato).");
            return $averageStats; // $averageStats contiene già il fallback MV
        }
        
        $seasonWeights = $this->calculateDefaultSeasonWeights($historicalStats->count());
        $playerConversionFactorsConfig = $this->settings['player_stats_league_conversion_factors'] ?? [];
        $defaultStatConversionFactor = 1.0;
        
        foreach ($fieldsToProject as $field) {
            $weightedSum = 0;
            $sumOfWeightsUsedForStat = 0;
            
            foreach ($historicalStats as $index => $stats) {
                $seasonWeight = $seasonWeights[$index] ?? 0;
                if ($seasonWeight == 0) continue;
                
                $games = $stats->games_played > 0 ? $stats->games_played : 1;
                // Se $stats->{$field} è null, usa 0 per evitare errori nei calcoli
                $statValueOriginal = $stats->{$field} ?? 0;
                $statValue = $statValueOriginal;
                
                
                $leagueOfThisSeason = $stats->league_name ?? 'Serie A'; // Default a 'Serie A' se league_name è NULL
                
                $conversionFactor = $defaultStatConversionFactor;
                if (isset($playerConversionFactorsConfig[$leagueOfThisSeason][$field])) {
                    $conversionFactor = $playerConversionFactorsConfig[$leagueOfThisSeason][$field];
                } elseif (isset($playerConversionFactorsConfig[$leagueOfThisSeason]['default'])) {
                    $conversionFactor = $playerConversionFactorsConfig[$leagueOfThisSeason]['default'];
                }
                
                if ($conversionFactor != 1.0) {
                    $statValue *= $conversionFactor;
                    Log::debug(self::class . ": Giocatore {$player->name}, Stagione {$stats->season_year}, Lega '{$leagueOfThisSeason}', Stat '{$field}', Valore orig: {$statValueOriginal}, Fattore: {$conversionFactor}, Valore Conv: {$statValue}");
                }
                
                if (in_array($field, ['avg_rating'])) {
                    if (($stats->games_played ?? 0) >= ($this->settings['min_games_for_reliable_avg_rating'] ?? 10)) {
                        $weightedSum += $statValue * $seasonWeight;
                        $sumOfWeightsUsedForStat += $seasonWeight;
                    }
                } else {
                    if (($stats->games_played ?? 0) > 0) {
                        $statPerGame = $statValue / $games;
                        $weightedSum += $statPerGame * $seasonWeight;
                        $sumOfWeightsUsedForStat += $seasonWeight;
                    }
                }
            }
            
            // Solo se la statistica è stata effettivamente calcolata (sumOfWeightsUsedForStat > 0), aggiorna il valore.
            // Altrimenti, mantiene il default (0.0 o fallback_mv).
            if ($sumOfWeightsUsedForStat > 0) {
                $averageStats[$field] = $weightedSum / $sumOfWeightsUsedForStat;
            } elseif ($field === 'avg_rating' && $averageStats[$field] === ($this->settings['fallback_mv_if_no_history'] ?? 5.5) ) {
                // Se avg_rating non è stato calcolato (perché nessuna stagione aveva abbastanza partite)
                // e ha ancora il valore di fallback, non fare nulla, è già impostato.
                // Altrimenti, se fosse stato 0.0 da $defaultAverages e nessuna stagione valida, allora imposta il fallback.
                // Questa logica è già coperta dall'inizializzazione di $averageStats con $defaultAverages.
                Log::debug(self::class . ": Per {$player->name}, stat '{$field}' usa il valore di default ({$averageStats[$field]}) perché sumOfWeightsUsedForStat è zero.");
            }
            // Se $averageStats[$field] è ancora 0.0 per avg_rating e non ci sono state stagioni valide,
            // il valore di fallback da $defaultAverages è già lì.
        }
        
        // Calcolo avg_games_played (non soggetto a conversione di lega)
        $totalWeightedGames = 0;
        $totalWeightForGames = 0;
        foreach ($historicalStats as $index => $stats) {
            $seasonWeight = $seasonWeights[$index] ?? 0;
            $totalWeightedGames += ($stats->games_played ?? 0) * $seasonWeight;
            $totalWeightForGames += $seasonWeight;
        }
        
        if ($totalWeightForGames > 0) {
            $averageStats['avg_games_played'] = round($totalWeightedGames / $totalWeightForGames, 0);
        } else if ($historicalStats->isNotEmpty()) {
            // Fallback se i pesi fossero tutti zero per qualche motivo ma ci sono stats
            $avgGamesFallback = $historicalStats->avg('games_played');
            $averageStats['avg_games_played'] = round($avgGamesFallback ?? ($this->settings['fallback_gp_if_no_history'] ?? 0), 0);
        } else {
            // Se non ci sono stats storiche, avg_games_played rimane 0 (dal $defaultAverages)
            $averageStats['avg_games_played'] = $this->settings['fallback_gp_if_no_history'] ?? 0;
        }
        
        Log::info(self::class . ": Statistiche medie ponderate PER PARTITA per {$player->name} (ID: {$player->fanta_platform_id}) calcolate (con conversione lega): " . json_encode($averageStats));
        return $averageStats;
    }
    
    // ... (calculateDefaultSeasonWeights, getDefaultStatsPerGameForRole, estimateDefaultPresences, applyAdjustmentsAndEstimatePresences) ...
    // Assicurati che applyAdjustmentsAndEstimatePresences restituisca sempre un array con ['adjusted_stats_per_game' => ..., 'presenze_attese' => ...]
    // Inizializza presenze_attese a un default all'inizio di applyAdjustmentsAndEstimatePresences
    
    // Esempio di come potrebbe iniziare applyAdjustmentsAndEstimatePresences:
    private function applyAdjustmentsAndEstimatePresences(array $weightedStatsPerGame, Player $player, UserLeagueProfile $leagueProfile, EloquentCollection $historicalStatsForPenaltyAnalysis): array
    {
        $adjustedStatsPerGame = $weightedStatsPerGame;
        // Inizializza presenzeAttese con un valore di fallback o dalla config
        $presenzeAttese = $this->settings['fallback_projected_presences'] ??
        ($weightedStatsPerGame['avg_games_played'] > 0 ? round($weightedStatsPerGame['avg_games_played']) : 20);
        
        
        // ... (tutta la logica di aggiustamento per età, tier, rigori) ...
        // Alla fine del metodo, quando calcoli $presenzeAttese, se per qualche motivo il calcolo non avviene,
        // il valore di fallback iniziale verrà usato.
        
        // Calcolo effettivo di presenzeAttese (esempio dalla tua logica precedente)
        $basePresenze = $weightedStatsPerGame['avg_games_played'] ?? ($this->settings['default_presences_map']['base'] ?? 20);
        $teamTier = $player->team?->tier ?? ($this->settings['default_team_tier'] ?? 3);
        $ageModifierParams = $this->ageCurveSettings['age_modifier_params'] ?? [];
        $ageModifier = $this->calculateAgeModifierValue($player, $ageModifierParams); // Ipotizzando un metodo helper
        
        $teamTierPresenceFactors = $this->settings['team_tier_presence_factor'] ?? [1 => 1.05, 2 => 1.02, 3 => 1.0, 4 => 0.98, 5 => 0.95];
        $presenzeTierFactor = $teamTierPresenceFactors[$teamTier] ?? 1.0;
        
        $presenzeAgeFactor = $ageModifier; // Semplificazione, usa la tua logica più dettagliata per presenzeAgeFactor
        if (isset($ageModifierParams['presenze_decline_effect_ratio']) && $ageModifier < 1.0) {
            $presenzeAgeFactor = max($ageModifierParams['presenze_decline_cap'] ?? 0.65, 1 - ((1 - $ageModifier) * $ageModifierParams['presenze_decline_effect_ratio']));
        } elseif (isset($ageModifierParams['presenze_growth_effect_ratio']) && $ageModifier > 1.0) {
            $presenzeAgeFactor = min($ageModifierParams['presenze_growth_cap'] ?? 1.12, 1 + (($ageModifier - 1) * $ageModifierParams['presenze_growth_effect_ratio']));
        }
        
        $calculatedPresences = round($basePresenze * $presenzeTierFactor * $presenzeAgeFactor);
        $presenzeAttese = max($this->settings['min_projected_presences'] ?? 5, min($this->settings['max_projected_presences'] ?? 38, (int)$calculatedPresences));
        Log::debug(self::class . ": Stima Presenze per {$player->name} - Base:{$basePresenze}, TierFactor:{$presenzeTierFactor}, AgeFactor:{$presenzeAgeFactor} => Finale:{$presenzeAttese}");
        
        // Assicurati di rimuovere avg_games_played se non serve più dopo il calcolo presenze
        if (isset($adjustedStatsPerGame['avg_games_played'])) unset($adjustedStatsPerGame['avg_games_played']);
        
        return [
            'adjusted_stats_per_game' => $adjustedStatsPerGame,
            'presenze_attese' => $presenzeAttese, // Ora $presenzeAttese è sempre definita
        ];
    }
    
    // Metodo helper per calcolare ageModifier (esempio, dovresti avere la tua logica)
    private function calculateAgeModifierValue(Player $player, array $ageModifierParams): float
    {
        if (!$player->date_of_birth || empty($this->ageCurveSettings['dati_ruoli'])) return 1.0;
        
        $age = $player->date_of_birth->age;
        $roleKey = strtoupper($player->role ?? 'C');
        $configForRole = $this->ageCurveSettings['dati_ruoli'][$roleKey] ?? ($this->ageCurveSettings['dati_ruoli']['C'] ?? null);
        
        if ($configForRole && isset($configForRole['fasi_carriera'])) {
            $fasi = $configForRole['fasi_carriera'];
            $peakStart = $fasi['picco_inizio'] ?? 25;
            $peakEnd = $fasi['picco_fine'] ?? 30;
            $growthFactor = $configForRole['growth_factor'] ?? 0.020;
            $declineFactor = $configForRole['decline_factor'] ?? 0.030;
            $youngCap = $configForRole['young_cap'] ?? 1.20;
            $oldCap = $configForRole['old_cap'] ?? 0.70;
            
            if ($age < $peakStart) {
                return min($youngCap, 1.0 + (($peakStart - $age) * $growthFactor));
            } elseif ($age > $peakEnd) {
                return max($oldCap, 1.0 - (($age - $peakEnd) * $declineFactor));
            }
        }
        return 1.0;
    }
    
    // ... (Il resto dei tuoi metodi come generatePlayerProjection, calculateDefaultSeasonWeights, getDefaultStatsPerGameForRole, estimateDefaultPresences)
    // Dovrai assicurarti che generatePlayerProjection sia aggiornato per chiamare la nuova calculateWeightedAverageStats
    // e per gestire la logica di default se calculateWeightedAverageStats restituisce un array vuoto/default.
    // La versione di generatePlayerProjection che mi hai fornito prima sembra già fare questo controllo
    // con if ($historicalStatsForAverages->isEmpty()) e poi la chiamata a calculateWeightedAverageStats($player).
    // L'importante è che la gestione degli errori sia coerente.
    
    // Assicurati che generatePlayerProjection sia strutturato come segue (estratto semplificato):
    public function generatePlayerProjection(
        Player $player,
        UserLeagueProfile $leagueProfile,
        int $numberOfSeasonsToConsider = 0, // Lo prendiamo da config ora
        array $seasonWeightsArg = [] // Non più usato direttamente se calculateWeightedAverageStats gestisce i pesi
        ): array {
            Log::info(self::class . ": Inizio proiezioni per giocatore ID {$player->fanta_platform_id} ({$player->name})");
            
            // $this->settings dovrebbe essere già inizializzato nel costruttore.
            // Se projection_settings non è caricato, il costruttore logga un CRITICAL error.
            if (empty($this->settings)) {
                // Gestisci il caso in cui le settings non sono caricate, magari restituendo un errore o una proiezione vuota/default
                Log::error(self::class.": Impossibile generare proiezioni, projection_settings non caricate.");
                // Ritorna una struttura di proiezione vuota o con valori di errore
                return $this->getEmptyProjectionStructure($player); // Dovrai creare questo metodo helper
            }
            
            // Il lookback per le medie generali viene ora gestito dentro calculateWeightedAverageStats
            // basato su $this->settings['lookback_seasons']
            
            // Stats per analisi rigoristi (usa un lookback specifico per i rigori)
            $penaltyLookback = $this->settings['penalty_logic']['penalty_taker_lookback_seasons'] ?? 2;
            $allHistoricalStatsForPenaltyAnalysis = HistoricalPlayerStat::where('player_fanta_platform_id', $player->fanta_platform_id)
            ->orderBy('season_year', 'desc')
            ->take($penaltyLookback)
            ->get();
            
            // Prova a calcolare le medie ponderate
            $weightedStatsPerGame = $this->calculateWeightedAverageStats($player);
            
            // Se calculateWeightedAverageStats restituisce uno schema vuoto o solo con fallback_mv
            // (es., perché non c'è storico), allora usa i default del giocatore.
            // Dobbiamo definire meglio cosa significa "storico non sufficiente" per usare i default.
            // Forse se weightedStatsPerGame['avg_games_played'] è molto basso o zero.
            $hasSufficientHistory = false;
            if (!empty($weightedStatsPerGame) && ($weightedStatsPerGame['avg_games_played'] ?? 0) >= ($this->settings['min_avg_games_for_projection_base'] ?? 1)) {
                $hasSufficientHistory = true;
            }
            
            if (!$hasSufficientHistory) {
                Log::warning(self::class . ": Storico non sufficiente o medie ponderate non calcolabili per ID {$player->fanta_platform_id} ({$player->name}). Uso default.");
                // ... (la tua logica per $defaultStatsPerGame e $defaultPresences come prima) ...
                // Assicurati che questa logica di default sia completa e restituisca lo stesso formato array finale.
                // Per coerenza, potresti chiamare anche applyAdjustmentsAndEstimatePresences con i defaultStats
                // se vuoi applicare età/tier anche ai default (cosa che getDefaultStatsPerGameForRole già fa).
                $age = $player->date_of_birth ? $player->date_of_birth->age : ($this->settings['default_player_age'] ?? 25);
                $defaultStatsPerGame = $this->getDefaultStatsPerGameForRole($player->role, $player->team?->tier, $age);
                
                // Applico aggiustamenti base anche ai default se necessario, o li uso così come sono.
                // In questo caso, getDefaultStatsPerGameForRole già considera età e tier.
                $adjustedStatsPerGame = $defaultStatsPerGame;
                $presenzeAttese = $this->estimateDefaultPresences($player->role, $player->team?->tier, $age);
                
                // Qui, clean_sheet_per_game_proj potrebbe essere già in $defaultStatsPerGame
                // ma la gestione del contributo medio al FM è ora separata.
            } else {
                Log::debug(self::class . ": Statistiche medie ponderate PER PARTITA per {$player->name}: " . json_encode($weightedStatsPerGame));
                $adjustmentResult = $this->applyAdjustmentsAndEstimatePresences($weightedStatsPerGame, $player, $leagueProfile, $allHistoricalStatsForPenaltyAnalysis);
                $adjustedStatsPerGame = $adjustmentResult['adjusted_stats_per_game'];
                $presenzeAttese = $adjustmentResult['presenze_attese'];
            }
            
            Log::debug(self::class . ": Statistiche PER PARTITA aggiustate per {$player->name}: " . json_encode($adjustedStatsPerGame));
            Log::debug(self::class . ": Presenze attese stimate per {$player->name}: " . $presenzeAttese);
            
            // Prepara $statsForFmCalculation SENZA 'clean_sheet' diretto
            $statsForFmCalculation = [
                'mv' => $adjustedStatsPerGame['avg_rating'] ?? ($this->settings['fallback_mv_if_no_history'] ?? 5.5),
                'gol_fatti' => $adjustedStatsPerGame['goals_scored'] ?? 0,
                'assist' => $adjustedStatsPerGame['assists'] ?? 0,
                'ammonizioni' => $adjustedStatsPerGame['yellow_cards'] ?? 0,
                'espulsioni' => $adjustedStatsPerGame['red_cards'] ?? 0,
                'autogol' => $adjustedStatsPerGame['own_goals'] ?? 0,
                'rigori_segnati' => $adjustedStatsPerGame['penalties_scored'] ?? 0,
                'rigori_sbagliati' => $adjustedStatsPerGame['penalties_missed'] ?? 0,
                'rigori_parati' => $adjustedStatsPerGame['penalties_saved'] ?? 0,
                'gol_subiti' => $adjustedStatsPerGame['goals_conceded'] ?? 0,
            ];
            
            $fantaMediaProjectedPerGame_base = $this->pointCalculator->calculateFantasyPoints(
                $statsForFmCalculation,
                $leagueProfile->scoring_rules ?? [],
                $player->role
                );
            
            // Calcola il contributo medio del clean sheet
            $avgCleanSheetContribution = 0;
            $probCS = $adjustedStatsPerGame['clean_sheet_per_game_proj'] ?? 0.0; // clean_sheet_per_game_proj dovrebbe essere calcolato in applyAdjustments...
            
            if (strtoupper($player->role ?? '') === 'P' || strtoupper($player->role ?? '') === 'D') {
                if (($statsForFmCalculation['mv'] ?? 0) >= 6.0) {
                    $scoringRules = $leagueProfile->scoring_rules ?? [];
                    $csBonusRuleKey = (strtoupper($player->role) === 'P') ? 'clean_sheet_p' : 'clean_sheet_d';
                    $csBonusFallbackKey = (strtoupper($player->role) === 'P') ? 'bonus_imbattibilita_portiere' : 'bonus_imbattibilita_difensore';
                    $csDefaultBonus = (strtoupper($player->role) === 'P' ? 1.0 : 0.5);
                    
                    $csBonusValue = (float)($scoringRules[$csBonusRuleKey] ?? $scoringRules[$csBonusFallbackKey] ?? $csDefaultBonus);
                    $avgCleanSheetContribution = $probCS * $csBonusValue;
                }
            }
            
            $fantaMediaProjectedPerGame = $fantaMediaProjectedPerGame_base + $avgCleanSheetContribution;
            Log::info(self::class . ": FM base per {$player->name}: {$fantaMediaProjectedPerGame_base}, Contributo medio CS: {$avgCleanSheetContribution}, FM Finale/partita: {$fantaMediaProjectedPerGame}");
            
            $totalFantasyPointsProjected = $fantaMediaProjectedPerGame * $presenzeAttese;
            Log::info(self::class . ": Fantapunti totali stagionali proiettati per {$player->name}: {$totalFantasyPointsProjected}");
            
            // Preparazione output finale
            $finalStatsForFmCalcOutput = $statsForFmCalculation;
            $finalStatsForFmCalcOutput['avg_cs_bonus_added'] = round($avgCleanSheetContribution, 3);
            $finalStatsForFmCalcOutput['clean_sheet_probability_input'] = round($probCS, 3); // La probabilità usata
            
            // *** AGGIUNGI QUESTA RIGA QUI ***
            $fieldsToProject = $this->settings['fields_to_project'] ?? [
                'avg_rating', 'goals_scored', 'assists',
                'yellow_cards', 'red_cards', 'own_goals',
                'penalties_taken', 'penalties_scored', 'penalties_missed',
                'penalties_saved', 'goals_conceded'
            ];
            $fieldsToProject = array_diff($fieldsToProject, ['fanta_avg_rating']); // Rimuovi se non vuoi proiettarlo direttamente
            
            $projectedSeasonalTotals = [];
            // Popola $projectedSeasonalTotals basandoti su $adjustedStatsPerGame e $presenzeAttese
            // Assicurati che tutte le stats in $fieldsToProject siano coperte qui
            foreach ($fieldsToProject as $statKey) {
                if ($statKey === 'avg_rating') {
                    $projectedSeasonalTotals['mv_proj_per_game'] = round($adjustedStatsPerGame[$statKey] ?? ($this->settings['fallback_mv_if_no_history'] ?? 5.5), 2);
                } elseif (isset($adjustedStatsPerGame[$statKey])) {
                    // Escludi stats che sono già per partita o tassi, a meno che non si voglia il totale stagionale
                    $projectedSeasonalTotals[$statKey . '_proj'] = round($adjustedStatsPerGame[$statKey] * $presenzeAttese, 2);
                }
            }
            // Aggiungi specificamente clean_sheet_per_game_proj se non è già in $fieldsToProject
            if(isset($adjustedStatsPerGame['clean_sheet_per_game_proj'])){
                $projectedSeasonalTotals['clean_sheet_per_game_proj'] = round($adjustedStatsPerGame['clean_sheet_per_game_proj'], 2);
            }
            
            
            return [
                'stats_per_game_for_fm_calc' => $finalStatsForFmCalcOutput,
                'mv_proj_per_game' => round($adjustedStatsPerGame['avg_rating'] ?? ($this->settings['fallback_mv_if_no_history'] ?? 5.5), 2),
                'fanta_media_proj_per_game' => round($fantaMediaProjectedPerGame, 2),
                'presenze_proj' => (int)$presenzeAttese,
                'total_fantasy_points_proj' => round($totalFantasyPointsProjected, 2),
                'seasonal_totals_proj' => $projectedSeasonalTotals,
            ];
    }
    
    // Helper per creare una struttura di proiezione vuota/default in caso di errori critici
    private function getEmptyProjectionStructure(Player $player): array
    {
        $role = $player->role ?? 'C';
        $age = $player->date_of_birth ? $player->date_of_birth->age : ($this->settings['default_player_age'] ?? 25);
        $defaultStats = $this->getDefaultStatsPerGameForRole($role, $player->team?->tier ?? 5, $age);
        $defaultPresences = $this->estimateDefaultPresences($role, $player->team?->tier ?? 5, $age);
        
        return [
            'stats_per_game_for_fm_calc' => $defaultStats,
            'mv_proj_per_game' => $defaultStats['mv'] ?? 5.5,
            'fanta_media_proj_per_game' => $defaultStats['mv'] ?? 5.5, // Stima molto grezza
            'presenze_proj' => $defaultPresences,
            'total_fantasy_points_proj' => round(($defaultStats['mv'] ?? 5.5) * $defaultPresences, 2),
            'seasonal_totals_proj' => collect($defaultStats)->mapWithKeys(function ($value, $key) use ($defaultPresences) {
            if ($key === 'mv' || $key === 'clean_sheet') return [$key . '_proj' => $value];
            return [$key . '_proj' => round($value * $defaultPresences, 2)];
            })->all(),
            'error' => 'Proiezione non disponibile a causa di errore configurazione settings.'
                ];
    }
    
    // ... (calculateDefaultSeasonWeights, getDefaultStatsPerGameForRole, estimateDefaultPresences come li hai)
    // Assicurati che calculateDefaultSeasonWeights non dia problemi con count=0
    private function calculateDefaultSeasonWeights(int $numberOfSeasons): array
    {
        if ($numberOfSeasons <= 0) return []; // Gestisci il caso di zero stagioni
        if ($numberOfSeasons === 1) return [1.0];
        
        $weights = [];
        $totalWeightParts = array_sum(range(1, $numberOfSeasons));
        
        if ($totalWeightParts === 0) { // Non dovrebbe succedere se numberOfSeasons > 0
            Log::warning(self::class.": totalWeightParts è zero in calculateDefaultSeasonWeights con {$numberOfSeasons} stagioni.");
            return array_fill(0, $numberOfSeasons, 1 / $numberOfSeasons);
        }
        for ($i = $numberOfSeasons; $i >= 1; $i--) {
            $weights[] = $i / $totalWeightParts;
        }
        return $weights;
    }
    
    // Devi avere anche i metodi getDefaultStatsPerGameForRole e estimateDefaultPresences
    // nel tuo ProjectionEngineService.php
    // Adattati da codice precedente:
    
    private function getDefaultStatsPerGameForRole(?string $role, ?int $teamTier, ?int $age): array
    {
        $roleKey = strtoupper($role ?? 'C');
        $currentTeamTier = $teamTier ?? ($this->settings['default_team_tier'] ?? 3);
        $age = $age ?? ($this->settings['default_player_age'] ?? 25);
        
        $defaultStatsConfig = $this->settings['default_stats_per_role'][$roleKey] ?? ($this->settings['default_stats_per_role']['C'] ?? []);
        $ageCurvesConfig = $this->ageCurveSettings['dati_ruoli'] ?? [];
        $ageModifierForDefaults = 1.0;
        
        if (!empty($ageCurvesConfig)) {
            $configForRole = $ageCurvesConfig[$roleKey] ?? ($ageCurvesConfig['C'] ?? null);
            if ($configForRole && isset($configForRole['fasi_carriera'])) {
                $fasi = $configForRole['fasi_carriera'];
                $peakStart = $fasi['picco_inizio'] ?? 25;
                $peakEnd = $fasi['picco_fine'] ?? 30;
                $growthFactor = $configForRole['growth_factor'] ?? 0.020;
                $declineFactor = $configForRole['decline_factor'] ?? 0.030;
                
                $ageEffectMultiplierYoung = $this->settings['default_values_config']['age_effect_multiplier_young'] ?? 0.5;
                $ageEffectMultiplierOld = $this->settings['default_values_config']['age_effect_multiplier_old'] ?? 0.8;
                $ageModifierMinCap = $this->settings['default_values_config']['age_modifier_min_cap'] ?? 0.7;
                $ageModifierMaxCap = $this->settings['default_values_config']['age_modifier_max_cap'] ?? 1.15;
                
                if ($age < $peakStart) $ageModifierForDefaults = 1.0 + (($peakStart - $age) * $growthFactor * $ageEffectMultiplierYoung);
                elseif ($age > $peakEnd) $ageModifierForDefaults = 1.0 - (($age - $peakEnd) * $declineFactor * $ageEffectMultiplierOld);
                $ageModifierForDefaults = max($ageModifierMinCap, min($ageModifierMaxCap, $ageModifierForDefaults));
            }
        }
        
        $offensiveTierFactors = $this->settings['team_tier_multipliers_offensive'] ?? [1 => 1.20, 2 => 1.10, 3 => 1.00, 4 => 0.90, 5 => 0.80];
        $defensiveTierFactors = $this->settings['team_tier_multipliers_defensive'] ?? [1 => 0.80, 2 => 0.90, 3 => 1.00, 4 => 1.10, 5 => 1.20];
        $tierOffensive = $offensiveTierFactors[$currentTeamTier] ?? 1.0;
        $tierDefensive = $defensiveTierFactors[$currentTeamTier] ?? 1.0;
        
        $finalMv = ($defaultStatsConfig['mv'] ?? 5.8) * $ageModifierForDefaults;
        $finalGoalsNoPen = ($defaultStatsConfig['goals_scored'] ?? 0.0) * $tierOffensive * $ageModifierForDefaults;
        $finalAssists = ($defaultStatsConfig['assists'] ?? 0.0) * $tierOffensive * $ageModifierForDefaults;
        
        $finalPenTaken = ($defaultStatsConfig['penalties_taken'] ?? 0.0) * $tierOffensive * $ageModifierForDefaults;
        $finalPenScored = $finalPenTaken * ($this->settings['penalty_logic']['default_penalty_conversion_rate'] ?? 0.75);
        
        $finalTotalGoals = $finalGoalsNoPen + $finalPenScored;
        
        $probCSBase = ($this->settings['clean_sheet_probabilities_by_tier'][$currentTeamTier] ?? 0.1);
        $csAgeEffect = ($this->ageCurveSettings['age_modifier_params']['cs_age_effect_ratio'] ?? 0.3);
        $finalCleanSheet = ($roleKey === 'P' || $roleKey === 'D') ? ($probCSBase * (1 + ($ageModifierForDefaults - 1) * $csAgeEffect)) : 0.0;
        $finalCleanSheet = max(0, min(0.8, $finalCleanSheet));
        
        $defaultMVMinCap = $this->settings['default_values_config']['mv_min_cap'] ?? 5.0;
        $defaultMVMaxCap = $this->settings['default_values_config']['mv_max_cap'] ?? 7.5;
        
        return [
            'avg_rating' => round(max($defaultMVMinCap, min($defaultMVMaxCap, $finalMv)), 2),
            'goals_scored' => round($finalTotalGoals, 3),
            'assists' => round($finalAssists, 3),
            'yellow_cards' => $defaultStatsConfig['yellow_cards'] ?? 0.1,
            'red_cards' => $defaultStatsConfig['red_cards'] ?? 0.005,
            'own_goals' => $defaultStatsConfig['own_goals'] ?? 0.002,
            'penalties_taken' => round($finalPenTaken, 3),
            'penalties_scored' => round($finalPenScored, 3),
            'penalties_missed' => round($finalPenTaken - $finalPenScored, 3),
            'penalties_saved' => ($roleKey === 'P' ? (($defaultStatsConfig['penalties_saved'] ?? 0.02) * $tierDefensive) : 0.0), // Modulato da tier difensivo
            'goals_conceded' => ($roleKey === 'P' ? round(max(0, ($defaultStatsConfig['goals_conceded'] ?? 1.2) * $tierDefensive / $ageModifierForDefaults), 2) : 0.0),
            'clean_sheet_per_game_proj' => round($finalCleanSheet, 2), // Questa è la probabilità di CS
        ];
    }
    
    private function estimateDefaultPresences(?string $role, ?int $teamTier, ?int $age): int
    {
        $roleKey = strtoupper($role ?? 'C');
        $currentTeamTier = $teamTier ?? ($this->settings['default_team_tier'] ?? 3);
        $age = $age ?? ($this->settings['default_player_age'] ?? 25);
        
        $base = ($this->settings['default_presences_map'][$roleKey]['tier'.$currentTeamTier] ??
            $this->settings['default_presences_map'][$roleKey]['default'] ??
            $this->settings['default_presences_map']['C']['default'] ?? 20);
        
        $ageCurvesConfig = $this->ageCurveSettings['dati_ruoli'] ?? [];
        $ageModifierParams = $this->ageCurveSettings['age_modifier_params'] ?? [];
        $ageModifierForPresences = 1.0;
        
        if (!empty($ageCurvesConfig) && !empty($ageModifierParams)) {
            $configForRole = $ageCurvesConfig[$roleKey] ?? ($ageCurvesConfig['C'] ?? null);
            if ($configForRole && isset($configForRole['fasi_carriera'])) {
                $fasi = $configForRole['fasi_carriera'];
                $peakStart = $fasi['picco_inizio'] ?? 25;
                $peakEnd = $fasi['picco_fine'] ?? 30;
                $growthFactorPresenze = $configForRole['presenze_growth_factor'] ?? $configForRole['growth_factor'] ?? 0.020;
                $declineFactorPresenze = $configForRole['presenze_decline_factor'] ?? $configForRole['decline_factor'] ?? 0.030;
                
                $presGrowthEffect = $ageModifierParams['presenze_growth_effect_ratio'] ?? 0.4;
                $presGrowthCap = $ageModifierParams['presenze_growth_cap'] ?? 1.12;
                $presDeclineEffect = $ageModifierParams['presenze_decline_effect_ratio'] ?? 1.1;
                $presDeclineCap = $ageModifierParams['presenze_decline_cap'] ?? 0.65;
                
                if ($age < $peakStart) {
                    $ageModifierForPresences = min($presGrowthCap, 1.0 + (($peakStart - $age) * $growthFactorPresenze * $presGrowthEffect));
                } elseif ($age > $peakEnd) {
                    $ageModifierForPresences = max($presDeclineCap, 1.0 - (($age - $peakEnd) * $declineFactorPresenze * $presDeclineEffect));
                }
            }
        }
        $base *= $ageModifierForPresences;
        
        return max($this->settings['min_projected_presences'] ?? 5, min($this->settings['max_projected_presences'] ?? 38, (int)round($base)));
    }
    
}