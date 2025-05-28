<?php

namespace App\Console\Commands; // Assicurati che sia questo se il file è qui

use App\Models\Player;
use App\Models\HistoricalPlayerStat;
use App\Models\Team;
use App\Services\FantasyPointCalculatorService; // Usa il servizio dal namespace corretto
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class ProjectionEngineService // Il nome della classe è ProjectionEngineService
{
    private array $settings;
    private array $ageCurveConfig;
    private FantasyPointCalculatorService $fantasyPointCalculator;
    
    public function __construct(FantasyPointCalculatorService $fantasyPointCalculator)
    {
        $this->settings = Config::get('projection_settings', []);
        $this->ageCurveConfig = Config::get('player_age_curves', []);
        $this->fantasyPointCalculator = $fantasyPointCalculator;
        
        if (empty($this->settings)) {
            Log::error(self::class . " CONSTRUCTOR: Config 'projection_settings' VUOTA!");
        } else {
            Log::info(self::class . " CONSTRUCTOR: 'projection_settings' caricata. 'lookback_seasons': " . ($this->settings['lookback_seasons'] ?? 'NON TROVATA'));
        }
        if (empty($this->ageCurveConfig) || !isset($this->ageCurveConfig['dati_ruoli'])) {
            Log::error(self::class . " CONSTRUCTOR: Config 'player_age_curves' VUOTA o struttura 'dati_ruoli' mancante!");
        }
    }
    
    public function generatePlayerProjection(Player $player, $leagueScoringRules = null): array
    {
        $serviceInstanceID = spl_object_hash($this);
        // Usa il namespace corretto se il file è in Commands
        $logPrefix = 'App\Console\Commands\ProjectionEngineService' . " ({$serviceInstanceID})";
        Log::info("{$logPrefix}: Inizio proiezioni per giocatore ID {$player->fanta_platform_id} ({$player->name})");
        
        if (empty($this->settings) || !isset($this->settings['lookback_seasons'])) {
            Log::critical("{$logPrefix}: Settings non caricate o 'lookback_seasons' mancante. Ricarico...");
            $this->settings = Config::get('projection_settings', []);
            if (empty($this->settings) || !isset($this->settings['lookback_seasons'])) {
                Log::critical("{$logPrefix}: Ricarica fallita. Impossibile procedere.");
                return $this->generateBaseProjections($player);
            }
        }
        if (empty($this->ageCurveConfig) || !isset($this->ageCurveConfig['dati_ruoli'])) {
            $this->ageCurveConfig = Config::get('player_age_curves', []);
            if (empty($this->ageCurveConfig) || !isset($this->ageCurveConfig['dati_ruoli'])) {
                Log::error("{$logPrefix}: Ricarica ageCurveConfig fallita o struttura 'dati_ruoli' mancante.");
            }
        }
        
        $averageStats = $this->calculateWeightedAverageStats($player);
        
        if (empty($averageStats)) {
            Log::warning("{$logPrefix}: Nessuna statistica storica sufficiente per {$player->name}.");
            return $this->generateBaseProjections($player);
        }
        
        $age = $player->age ?? $this->calculateAge($player->date_of_birth);
        $playerBaseRole = $player->role ?? 'N/A';
        
        $playerRoleForCurve = $this->getPlayerRoleForAgeCurve($playerBaseRole, $player);
        Log::debug("{$logPrefix}: Giocatore {$player->name}, Età: {$age}, Ruolo Base: {$playerBaseRole}, Ruolo per Curva Età: {$playerRoleForCurve}");
        
        $ageModifierGeneral = $this->getAgeModifier($age, $playerRoleForCurve, 'general');
        Log::debug("{$logPrefix}: Modificatore Età (general) per {$player->name} ({$playerRoleForCurve}): {$ageModifierGeneral}");
        
        $team = $player->team;
        $teamTier = $team ? $team->tier : ($this->settings['default_team_tier'] ?? 3);
        $teamTierMultiplierOffensive = $this->settings['team_tier_multipliers_offensive'][$teamTier] ?? 1.0;
        $teamTierMultiplierDefensive = $this->settings['team_tier_multipliers_defensive'][$teamTier] ?? 1.0;
        Log::debug("[DEBUG TIER - {$serviceInstanceID}] Team: " . ($team->name ?? 'N/A') . ", Tier: {$teamTier}, OffM: {$teamTierMultiplierOffensive}, DefM: {$teamTierMultiplierDefensive}");
        
        $projectedStatsPerGame = [];
        $allStatKeys = array_unique(array_merge(
            $this->settings['fields_to_project'] ?? [],
            ['avg_rating', 'fanta_avg_rating', 'avg_games_played', 'penalties_taken', 'penalties_scored', 'penalties_missed', 'penalties_saved', 'goals_conceded', 'clean_sheet_per_game_proj']
            ));
        foreach($allStatKeys as $key) {
            $projectedStatsPerGame[$key] = $averageStats[$key] ?? 0;
        }
        
        foreach ($projectedStatsPerGame as $stat => &$value) {
            $currentAgeModifier = $this->getAgeModifier($age, $playerRoleForCurve, $stat);
            
            if (in_array($stat, ['avg_rating', 'fanta_avg_rating', 'avg_games_played'])) {
                if ($stat !== 'avg_games_played') $value = $value * $currentAgeModifier;
            } elseif (in_array($stat, $this->settings['offensive_stats_fields'] ?? ['goals_scored', 'assists'])) {
                $value = $value * $currentAgeModifier * $teamTierMultiplierOffensive;
            } elseif (in_array($stat, $this->settings['defensive_stats_fields_goalkeeper'] ?? ['goals_conceded'])) {
                if ($playerBaseRole === 'P') $value = $value * $currentAgeModifier * $teamTierMultiplierDefensive;
            } elseif ($stat === 'penalties_saved') {
                if ($playerBaseRole === 'P') $value = $value * $currentAgeModifier;
                else $value = 0;
            } else if (in_array($stat, ['penalties_taken', 'penalties_scored', 'penalties_missed'])) {
                $value = $value * $currentAgeModifier;
            } else {
                $value = $value * $currentAgeModifier;
            }
        }
        unset($value);
        
        if ($this->isLikelyPenaltyTaker($player)) {
            $totalPenaltiesTakenInLookback = $this->getTotalPenaltiesTakenInLookback($player);
            Log::info("{$logPrefix}: Giocatore {$player->name} ID probable rigorista (Storico calciati: {$totalPenaltiesTakenInLookback}).");
            
            $teamExpectedPenaltiesPerGame = ($this->settings['league_avg_penalties_awarded'] ?? 0.2) * $teamTierMultiplierOffensive;
            $penaltyTakerShare = $this->settings['penalty_taker_share'] ?? 0.85;
            $ageModifierPenaltyAbility = $this->getAgeModifier($age, $playerRoleForCurve, 'penalty_taking_ability');
            $projectedPenaltiesTakenPerGame = $teamExpectedPenaltiesPerGame * $penaltyTakerShare * $ageModifierPenaltyAbility;
            Log::debug("{$logPrefix}: Rigorista {$player->name} - Proiezione Rigori Calciati/G: {$projectedPenaltiesTakenPerGame}");
            
            $historicalTakenPerGame = $averageStats['penalties_taken'] ?? 0;
            $historicalScoredPerGame = $averageStats['penalties_scored'] ?? 0;
            $defaultConversionRate = $this->settings['default_penalty_conversion_rate'] ?? 0.75;
            $historicalConversionRate = ($historicalTakenPerGame > 0.001) ? ($historicalScoredPerGame / $historicalTakenPerGame) : $defaultConversionRate;
            
            if ($totalPenaltiesTakenInLookback < ($this->settings['min_penalties_taken_for_reliable_conversion_rate'] ?? 5) ) {
                $historicalConversionRate = ($historicalConversionRate + $defaultConversionRate) / 2;
            }
            $ageModifierConversion = $this->getAgeModifier($age, $playerRoleForCurve, 'penalty_conversion_rate');
            $finalConversionRate = max(0, min(1, $historicalConversionRate * $ageModifierConversion));
            Log::debug("{$logPrefix}: Rigorista {$player->name} - Tasso Conversione Finale: {$finalConversionRate}");
            
            $newProjectedPenaltiesScored = $projectedPenaltiesTakenPerGame * $finalConversionRate;
            $newProjectedPenaltiesMissed = $projectedPenaltiesTakenPerGame * (1 - $finalConversionRate);
            
            $projectedNonPenaltyGoalsPerGame = ($projectedStatsPerGame['goals_scored'] ?? 0) - ($projectedStatsPerGame['penalties_scored'] ?? 0);
            
            $projectedStatsPerGame['goals_scored'] = max(0, $projectedNonPenaltyGoalsPerGame + $newProjectedPenaltiesScored);
            $projectedStatsPerGame['penalties_taken'] = $projectedPenaltiesTakenPerGame;
            $projectedStatsPerGame['penalties_scored'] = $newProjectedPenaltiesScored;
            $projectedStatsPerGame['penalties_missed'] = $newProjectedPenaltiesMissed;
        } else {
            Log::debug("{$logPrefix}: Giocatore {$player->name} non identificato come rigorista principale.");
        }
        
        $projectedCleanSheetPerGame = 0;
        if ($playerBaseRole === 'P') {
            $baseCleanSheetRate = $this->settings['league_average_clean_sheet_rate_per_game'] ?? 0.25;
            $ageModifierDefensive = $this->getAgeModifier($age, $playerRoleForCurve, 'defensive_ability');
            $projectedCleanSheetPerGame = $baseCleanSheetRate * $teamTierMultiplierDefensive * $ageModifierDefensive;
        }
        $projectedStatsPerGame['clean_sheet_per_game_proj'] = max(0, min(1, $projectedCleanSheetPerGame));
        
        $ageModifierGames = $this->getAgeModifier($age, $playerRoleForCurve, 'games_played');
        $presencesTierFactor = $this->settings['team_tier_presence_factor'][$teamTier] ?? 1.0;
        $projectedGames = round(($averageStats['avg_games_played'] ?? 0) * $presencesTierFactor * $ageModifierGames);
        $projectedGames = (int) min($projectedGames, 38);
        Log::debug("{$logPrefix}: Stima Presenze per {$player->name} => {$projectedGames}");
        
        Log::debug("{$logPrefix}: Statistiche PER PARTITA finali prima del calcolo FM: " . json_encode($projectedStatsPerGame));
        
        // Prepara l'array $statsForFantasyCalc con le chiavi esatte che FantasyPointCalculatorService si aspetta
        $statsForFantasyCalc = [
            'mv' => (float)($projectedStatsPerGame['avg_rating'] ?? 0),
            'gf' => (float)($projectedStatsPerGame['goals_scored'] ?? 0),
            'ass' => (float)($projectedStatsPerGame['assists'] ?? 0),
            'amm' => (float)($projectedStatsPerGame['yellow_cards'] ?? 0),
            'esp' => (float)($projectedStatsPerGame['red_cards'] ?? 0),
            'rig_par' => (float)($projectedStatsPerGame['penalties_saved'] ?? 0),
            'autogol' => (float)($projectedStatsPerGame['own_goals'] ?? 0),
            'gol_sub' => (float)($projectedStatsPerGame['goals_conceded'] ?? 0),
            'rig_sba' => (float)($projectedStatsPerGame['penalties_missed'] ?? 0),
            'rig_segn' => (float)($projectedStatsPerGame['penalties_scored'] ?? 0),
            // Aggiungi qui altre chiavi se FantasyPointCalculatorService le usa DALL'ARRAY $stats
            // Esempio: 'clean_sheet' => ($projectedStatsPerGame['clean_sheet_per_game_proj'] > 0.5) ? 1 : 0, // Se CS è un flag 0/1
        ];
        
        // La firma di calculateFantasyPoints nel tuo FantasyPointCalculatorService.php è:
        // public function calculateFantasyPoints(array $stats, string $playerRole, ?array $customRules = null): float
        $fantaMediaPerGame = $this->fantasyPointCalculator->calculateFantasyPoints(
            $statsForFantasyCalc,
            $playerBaseRole, // P, D, C, A
            $leagueScoringRules
            );
        Log::info("{$logPrefix}: FantaMedia proiettata (da calculateFantasyPoints) PER PARTITA per {$player->name}: {$fantaMediaPerGame}");
        
        $finalProjections = [];
        $outputFieldsConfig = $this->settings['fields_to_project_output'] ?? [];
        foreach ($outputFieldsConfig as $projKey => $config) {
            if (isset($config['type']) && $config['type'] === 'sum' && isset($config['source_per_game']) && isset($projectedStatsPerGame[$config['source_per_game']])) {
                $finalProjections[$projKey] = round($projectedStatsPerGame[$config['source_per_game']] * $projectedGames, 2);
            }
        }
        $finalProjections['avg_rating_proj'] = round($projectedStatsPerGame['avg_rating'] ?? ($this->settings['fallback_mv_if_no_history'] ?? 6.0), 2);
        $finalProjections['fanta_mv_proj'] = round($fantaMediaPerGame, 2);
        $finalProjections['games_played_proj'] = (int)$projectedGames;
        $finalProjections['total_fanta_points_proj'] = round($fantaMediaPerGame * $projectedGames, 2);
        
        Log::info("{$logPrefix}: Fantapunti totali stagionali proiettati per {$player->name}: {$finalProjections['total_fanta_points_proj']}");
        return $finalProjections;
    }
    
    private function calculateWeightedAverageStats(Player $player): array
    {
        if (empty($this->settings) || !isset($this->settings['lookback_seasons'])) {
            Log::critical(self::class . " calculateWeightedAverageStats: Settings non caricate o 'lookback_seasons' mancante.");
            return [];
        }
        
        $historicalStats = HistoricalPlayerStat::where('player_fanta_platform_id', $player->fanta_platform_id)
        ->orderBy('season_year', 'desc')
        ->take((int)$this->settings['lookback_seasons'])
        ->get();
        
        if ($historicalStats->isEmpty()) {
            return [];
        }
        
        $weights = [];
        $totalRawWeight = 0;
        $currentWeight = 1.0;
        $statsArray = $historicalStats->values()->all();
        
        for ($i = 0; $i < count($statsArray); $i++) {
            $weights[$i] = $currentWeight;
            $totalRawWeight += $currentWeight;
            $currentWeight *= ($this->settings['season_decay_factor'] ?? 0.75);
        }
        
        $normalizedWeights = [];
        if ($totalRawWeight > 0) {
            for ($i = 0; $i < count($statsArray); $i++) {
                $normalizedWeights[$i] = $weights[$i] / $totalRawWeight;
            }
        } elseif (count($statsArray) > 0) {
            $equalWeight = 1.0 / count($statsArray);
            for ($i = 0; $i < count($statsArray); $i++) $normalizedWeights[$i] = $equalWeight;
        }
        
        $averageStats = [];
        $fieldsToProject = $this->settings['fields_to_project'] ?? [];
        $fieldsToProject = array_unique(array_merge($fieldsToProject, ['penalties_taken', 'penalties_scored', 'penalties_missed', 'penalties_saved']));
        
        foreach ($fieldsToProject as $field) {
            $weightedSum = 0;
            $sumOfWeightsUsed = 0;
            
            if ($field === 'penalties_saved') Log::debug("[PENALTY_SAVED_DEBUG] Inizio calcolo per {$player->name}, campo: penalties_saved");
            
            foreach ($statsArray as $index => $stats) {
                $seasonWeight = $normalizedWeights[$index] ?? 0;
                
                if (in_array($field, ['avg_rating', 'fanta_avg_rating'])) {
                    if (($stats->games_played ?? 0) >= ($this->settings['min_games_for_reliable_avg_rating'] ?? 10)) {
                        $weightedSum += ($stats->{$field} ?? 0) * $seasonWeight * $stats->games_played;
                        $sumOfWeightsUsed += $seasonWeight * $stats->games_played;
                    }
                } else {
                    if (($stats->games_played ?? 0) > 0) {
                        $statPerGame = ($stats->{$field} ?? 0) / $stats->games_played;
                        $weightedSum += $statPerGame * $seasonWeight;
                        $sumOfWeightsUsed += $seasonWeight;
                        if ($field === 'penalties_saved') Log::debug("[PENALTY_SAVED_DEBUG] Stagione: {$stats->season_year}, PS: {$stats->penalties_saved}, GP: {$stats->games_played}, PS/GP: {$statPerGame}, PesoNorm: {$seasonWeight}, sumParziale: {$weightedSum}");
                    }
                }
            }
            $averageStats[$field] = $sumOfWeightsUsed > 0 ? $weightedSum / $sumOfWeightsUsed : 0;
            if ($field === 'penalties_saved') Log::debug("[PENALTY_SAVED_DEBUG] sumFinale: {$weightedSum}, sumWeightsFinale: {$sumOfWeightsUsed}, Risultato per penalties_saved: " . ($averageStats[$field] ?? 'N/A'));
        }
        
        $totalWeightedGames = 0;
        $totalWeightForGames = 0;
        foreach ($statsArray as $index => $stats) {
            $seasonWeight = $normalizedWeights[$index] ?? 0;
            $totalWeightedGames += ($stats->games_played ?? 0) * $seasonWeight;
            $totalWeightForGames += $seasonWeight;
        }
        $averageStats['avg_games_played'] = $totalWeightForGames > 0 ? $totalWeightedGames / $totalWeightForGames : ($historicalStats->isNotEmpty() ? ($historicalStats->avg('games_played') ?? 0) : 0);
        
        Log::debug(self::class . ": Statistiche medie ponderate PER PARTITA calcolate: " . json_encode($averageStats));
        return $averageStats;
    }
    
    private function getTotalPenaltiesTakenInLookback(Player $player): int
    {
        $totalPenalties = 0;
        $lookback = $this->settings['lookback_seasons_penalty_taker_check'] ?? ($this->settings['lookback_seasons'] ?? 4);
        $stats = HistoricalPlayerStat::where('player_fanta_platform_id', $player->fanta_platform_id)
        ->orderBy('season_year', 'desc')
        ->take((int)$lookback)
        ->get();
        foreach ($stats as $stat) $totalPenalties += ($stat->penalties_taken ?? 0);
        return $totalPenalties;
    }
    
    private function isLikelyPenaltyTaker(Player $player): bool
    {
        $totalPenaltiesTakenInLookback = $this->getTotalPenaltiesTakenInLookback($player);
        return $totalPenaltiesTakenInLookback >= ($this->settings['min_penalties_for_taker_status'] ?? 3);
    }
    
    private function calculateAge(?string $dateOfBirth): int
    {
        if (!$dateOfBirth) return $this->settings['default_player_age'] ?? 25;
        try {
            return (new \DateTime($dateOfBirth))->diff(new \DateTime())->y;
        } catch (\Exception $e) {
            Log::error("Errore calcolo età per {$dateOfBirth}: " . $e->getMessage());
            return $this->settings['default_player_age'] ?? 25;
        }
    }
    
    private function getPlayerRoleForAgeCurve(string $playerBaseRole, Player $player): string
    {
        $roleKey = strtoupper($playerBaseRole);
        // Se il ruolo base è 'D', e hai un modo per determinare se è centrale o esterno,
        // aggiorna $roleKey qui. Altrimenti, usa una logica di default.
        if ($roleKey === 'D') {
            // Esempio: basati su un campo 'detailed_position' se esiste e se la tua config lo usa
            // if (isset($player->detailed_position) && strtoupper($player->detailed_position) === 'DC') {
            // return 'D_CENTRALE';
            // }
            // return 'D_ESTERNO'; // Default se non specificato o non DC
            
            // Poiché la tua config usa D_CENTRALE e D_ESTERNO, e non D generico,
            // devi scegliere uno dei due o migliorare questa logica.
            // Per ora, come default, usiamo D_ESTERNO per evitare errori,
            // ma questo potrebbe non essere accurato per tutti i difensori.
            Log::warning(self::class . " getPlayerRoleForAgeCurve: Ruolo 'D' per {$player->name}, uso 'D_ESTERNO' per curva età. Implementare logica per D_CENTRALE se necessario.");
            return 'D_ESTERNO';
        }
        // Per P, C, A, la tua config player_age_curves usa direttamente queste chiavi.
        return $roleKey;
    }
    
    private function getAgeModifierForRole(int $age, string $playerRoleForCurve): float
    {
        if (empty($this->ageCurveConfig) || !isset($this->ageCurveConfig['dati_ruoli'])) {
            Log::error(self::class . " getAgeModifierForRole: ageCurveConfig VUOTA o 'dati_ruoli' mancante. Ritorno 1.0");
            return 1.0;
        }
        
        $roleKey = strtoupper($playerRoleForCurve);
        
        if (!isset($this->ageCurveConfig['dati_ruoli'][$roleKey])) {
            Log::warning("Configurazione 'dati_ruoli' non trovata per RUOLO_CURVA: {$roleKey}. Uso modificatore età 1.0");
            return 1.0;
        }
        
        $config = $this->ageCurveConfig['dati_ruoli'][$roleKey];
        $fasi = $config['fasi_carriera'] ?? null;
        
        if (!$fasi || !isset($fasi['picco_inizio']) || !isset($fasi['picco_fine'])) {
            Log::warning("Configurazione 'fasi_carriera' (picco_inizio/picco_fine) non trovata o incompleta per RUOLO_CURVA: {$roleKey}. Uso modificatore età 1.0");
            return 1.0;
        }
        
        $peakStart     = $fasi['picco_inizio'];
        $peakEnd       = $fasi['picco_fine'];
        $growthFactor  = $config['growth_factor']    ?? 0.02;
        $declineFactor = $config['decline_factor']   ?? 0.03;
        $minModifier   = $config['old_cap']          ?? 0.70;
        $maxModifier   = $config['young_cap']        ?? 1.20;
        
        $modifier = 1.0;
        if ($age < $peakStart) {
            $modifier = 1.0 + (($peakStart - $age) * $growthFactor);
            $modifier = min($modifier, $maxModifier);
        } elseif ($age > $peakEnd) {
            $modifier = 1.0 - (($age - $peakEnd) * $declineFactor);
            $modifier = max($modifier, $minModifier);
        }
        
        Log::debug(self::class . " getAgeModifierForRole per {$roleKey}, Età {$age} => Modificatore Base Ruolo: {$modifier}");
        return $modifier;
    }
    
    private function getAgeModifier(int $age, string $playerRoleForCurve, string $metricType): float
    {
        $baseRoleModifier = $this->getAgeModifierForRole($age, $playerRoleForCurve);
        
        if ($metricType === 'general' || $baseRoleModifier == 1.0) {
            return $baseRoleModifier;
        }
        
        $ageParams = $this->ageCurveConfig['age_modifier_params'] ?? [];
        $effectRatio = 1.0;
        
        $metricEffectKey = $metricType . '_effect_ratio'; // Es. 'mv_effect_ratio'
        // Casi speciali per le chiavi in age_modifier_params
        if ($metricType === 'avg_rating' || $metricType === 'fanta_avg_rating') $metricEffectKey = 'mv_effect_ratio';
        if ($metricType === 'games_played') {
            $metricEffectKey = ($baseRoleModifier > 1.0) ? 'presenze_growth_effect_ratio' : 'presenze_decline_effect_ratio';
        }
        if (in_array($metricType, ['penalties_saved_ability', 'defensive_ability', 'goals_conceded', 'clean_sheet_per_game_proj'])) {
            $metricEffectKey = 'cs_age_effect_ratio'; // Proxy
        }
        // Aggiungi qui altre mappature per metricType a chiavi _effect_ratio se necessario
        
        if (isset($ageParams[$metricEffectKey])) {
            $effectRatio = $ageParams[$metricEffectKey];
        } else {
            Log::debug(self::class . " getAgeModifier: Nessun effect_ratio specifico per {$metricType} (o chiave mappata {$metricEffectKey}) in age_modifier_params. Uso ratio 1.0.");
        }
        
        $finalModifier = 1 + ($baseRoleModifier - 1) * $effectRatio;
        
        $roleConfig = $this->ageCurveConfig['dati_ruoli'][strtoupper($playerRoleForCurve)] ?? null;
        if($roleConfig) {
            $minCap = $roleConfig['old_cap'] ?? 0.1;
            $maxCap = $roleConfig['young_cap'] ?? 1.5;
            
            if ($metricType === 'games_played' && $baseRoleModifier < 1.0 && isset($ageParams['presenze_decline_cap'])) {
                $minCap = max($minCap, $ageParams['presenze_decline_cap']);
            }
            if ($metricType === 'games_played' && $baseRoleModifier > 1.0 && isset($ageParams['presenze_growth_cap'])) {
                $maxCap = min($maxCap, $ageParams['presenze_growth_cap']);
            }
            $finalModifier = max($minCap, min($maxCap, $finalModifier));
        }
        
        Log::debug(self::class . " getAgeModifier per {$playerRoleForCurve}.{$metricType}, Età {$age}: baseMod={$baseRoleModifier}, effRatio={$effectRatio} => FinalMod: {$finalModifier}");
        return $finalModifier;
    }
    
    private function generateBaseProjections(Player $player): array
    {
        $baseFields = [];
        $outputFieldsConfig = $this->settings['fields_to_project_output'] ?? [];
        foreach($outputFieldsConfig as $fieldKey => $fieldConfig){
            $baseFields[$fieldKey] = $fieldConfig['default_value'] ?? 0;
        }
        $baseFields['avg_rating_proj'] = $this->settings['fallback_mv_if_no_history'] ?? 6.0;
        $baseFields['fanta_mv_proj'] = $this->settings['fallback_fm_if_no_history'] ?? 6.0;
        $baseFields['games_played_proj'] = $this->settings['fallback_gp_if_no_history'] ?? 0;
        $baseFields['total_fanta_points_proj'] = 0;
        Log::info(self::class . ": Proiezioni base generate per {$player->name}");
        return $baseFields;
    }
}