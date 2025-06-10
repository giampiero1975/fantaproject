<?php

namespace App\Services;

use App\Models\Team;
use App\Models\TeamHistoricalStanding;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Collection; // Non usata direttamente, ma Eloquent la usa

class TeamTieringService
{
    private array $config;
    
    public function __construct()
    {
        $this->config = Config::get('team_tiering_settings');
        if (empty($this->config)) {
            Log::error(self::class . " Errore: File di configurazione 'team_tiering_settings.php' non trovato o vuoto.");
            // Considera di lanciare un'eccezione se la config è vitale per il funzionamento del servizio
            // throw new \Exception("Configurazione team_tiering_settings mancante.");
        }
    }
    
    /**
     * Metodo principale per aggiornare i tier di tutte le squadre (marcate come serie_a_team)
     * per una stagione target specifica.
     *
     * @param string $targetSeasonYear Es. "2024-25" (la stagione PER CUI si calcola il tier)
     * @return array Conteggio dei team aggiornati per tier, distribuzione e punteggi
     */
    public function updateAllTeamTiersForSeason(string $targetSeasonYear): array
    {
        Log::info(self::class . ": Inizio calcolo e aggiornamento tier per la stagione target {$targetSeasonYear}");
        
        $teamsToTier = Team::where('serie_a_team', true)->get();
        
        if ($teamsToTier->isEmpty()) {
            Log::warning(self::class . ": Nessuna squadra con 'serie_a_team = true' trovata nel database per il tiering della stagione {$targetSeasonYear}.");
            return ['updated_count' => 0, 'tier_distribution' => array_fill(1, 5, 0), 'team_scores' => []];
        }
        Log::info(self::class . ": Trovate {$teamsToTier->count()} squadre da tierizzare (serie_a_team=true) per la stagione {$targetSeasonYear}.");
        
        $teamStrengthScores = [];
        foreach ($teamsToTier as $team) {
            $strengthScore = $this->calculateTeamStrengthScore($team, $targetSeasonYear);
            // Anche se calculateTeamStrengthScore ora restituisce un default per neopromosse,
            // manteniamo il controllo per una maggiore robustezza nel caso restituisca null per altri errori.
            if ($strengthScore !== null) {
                $teamStrengthScores[$team->id] = [
                    'name' => $team->name,
                    'score' => $strengthScore,
                    'current_tier' => $team->tier
                ];
            } else {
                Log::warning(self::class.": Punteggio di forza Nullo per {$team->name} (ID: {$team->id}). Sarà escluso dalla normalizzazione e manterrà il tier attuale o riceverà un default se gestito in assignTier.");
            }
        }
        
        if (empty($teamStrengthScores)) {
            Log::warning(self::class . ": Nessun punteggio di forza calcolato per le squadre eleggibili.");
            return ['updated_count' => 0, 'tier_distribution' => array_fill(1, 5, 0), 'team_scores' => []];
        }
        
        // Prepara i punteggi per la normalizzazione o per i percentili
        // Escludi i team per cui non è stato possibile calcolare uno score valido (se calculateTeamStrengthScore potesse restituire null)
        $validScores = array_filter(array_column($teamStrengthScores, 'score'), function($score) {
            return $score !== null;
        });
            
            $minScore = !empty($validScores) ? min($validScores) : 0;
            $maxScore = !empty($validScores) ? max($validScores) : 100; // Default a 100 se non ci sono score o c'è solo uno
            
            $normalizedScoresMap = [];
            
            if ($this->config['normalization_method'] === 'min_max') {
                foreach ($teamStrengthScores as $teamId => $data) {
                    if ($data['score'] === null) {
                        $normalizedScoresMap[$teamId] = null;
                        $teamStrengthScores[$teamId]['normalized_score'] = 'N/A (No Score)';
                        continue;
                    }
                    if (($maxScore - $minScore) > 0) {
                        $normalizedScoreValue = 100 * ($data['score'] - $minScore) / ($maxScore - $minScore);
                    } else {
                        $normalizedScoreValue = 50;
                    }
                    $normalizedScoresMap[$teamId] = $normalizedScoreValue;
                    $teamStrengthScores[$teamId]['normalized_score'] = round($normalizedScoreValue, 2);
                }
            } else {
                foreach ($teamStrengthScores as $teamId => $data) {
                    $normalizedScoresMap[$teamId] = $data['score'];
                    $teamStrengthScores[$teamId]['normalized_score'] = 'N/A (Raw Used)';
                }
            }
            
            $updatedCount = 0;
            $tierDistribution = array_fill(1, 5, 0);
            
            foreach ($teamsToTier as $team) {
                $assignedTier = $team->tier; // Inizia con il tier attuale come fallback
                
                if (isset($teamStrengthScores[$team->id]) && $teamStrengthScores[$team->id]['score'] !== null) {
                    $currentRawScore = $teamStrengthScores[$team->id]['score'];
                    $scoreToUseForTiering = $normalizedScoresMap[$team->id] ?? $currentRawScore; // Usa normalizzato se c'è, altrimenti grezzo (se normalization = 'none')
                    
                    // Passa l'array di tutti i punteggi validi (grezzi) se il metodo è percentile
                    $allValidRawScoresForPercentile = ($this->config['tier_thresholds_source'] === 'dynamic_percentiles') ? $validScores : [];
                    $assignedTier = $this->assignTier($currentRawScore, $scoreToUseForTiering, $allValidRawScoresForPercentile);
                } else {
                    // Se non c'è punteggio, potrebbe essere una squadra appena aggiunta senza storico o un errore.
                    // Potremmo assegnarle il 'newly_promoted_tier_default' o mantenere il suo tier attuale.
                    // La logica in calculateTeamStrengthScore dovrebbe già aver assegnato un punteggio di default.
                    // Se arriva qui con score nullo, è un caso anomalo.
                    Log::warning(self::class.": Punteggio di forza Nullo per {$team->name} (ID: {$team->id}) anche dopo il calcolo, il tier non verrà modificato da questo score.");
                }
                
                if ($team->tier != $assignedTier) {
                    Log::info(self::class . ": Aggiornamento tier per {$team->name} (ID: {$team->id}): Vecchio Tier {$team->tier}, Nuovo Tier {$assignedTier}, P.Forza: " . ($teamStrengthScores[$team->id]['score'] ?? 'N/A') . ", Norm/Used: " . ($teamStrengthScores[$team->id]['normalized_score'] ?? ($teamStrengthScores[$team->id]['score'] ?? 'N/A')));
                    $team->tier = $assignedTier;
                    $team->save();
                    $updatedCount++;
                }
                if (isset($tierDistribution[$assignedTier])) {
                    $tierDistribution[$assignedTier]++;
                } else {
                    Log::warning(self::class.": Tier {$assignedTier} non valido assegnato a {$team->name}.");
                }
            }
            
            Log::info(self::class . ": Aggiornamento tier completato per {$targetSeasonYear}. Squadre aggiornate: {$updatedCount}. Distribuzione Tier: " . json_encode($tierDistribution));
            return ['updated_count' => $updatedCount, 'tier_distribution' => $tierDistribution, 'team_scores' => $teamStrengthScores];
    }
    
    private function calculateTeamStrengthScore(Team $team, string $targetSeasonYear): ?float
    {
        $lookbackSeasonsCount = $this->config['lookback_seasons_for_tiering'] ?? 3;
        $seasonWeightsConfig = $this->config['season_weights'] ?? [0.5, 0.3, 0.2];
        $metricWeights = $this->config['metric_weights'] ?? ['points' => 1.0];
        
        $targetStartYear = (int)substr($targetSeasonYear, 0, 4);
        
        // --- INIZIO BLOCCO CORRETTO ---
        $lookbackYears = [];
        $lookbackSeasonsForLog = []; // Per un logging più chiaro
        for ($i = 1; $i <= $lookbackSeasonsCount; $i++) {
            $year = $targetStartYear - $i; // Crea [2024, 2023, 2022, ...]
            $lookbackYears[] = $year;
            $lookbackSeasonsForLog[] = $year . '-' . substr($year + 1, -2);
        }
        
        // Convertiamo gli anni in stringhe per un matching sicuro con la colonna del DB
        $lookbackYearsAsString = array_map('strval', $lookbackYears);
        // --- FINE BLOCCO CORRETTO ---
        
        $historicalStandings = TeamHistoricalStanding::where('team_id', $team->id)
        ->whereIn('season_year', $lookbackYearsAsString) // USA L'ARRAY DI STRINGHE CORRETTO
        ->orderBy('season_year', 'desc')
        ->get();
        
        if ($historicalStandings->isEmpty()) {
            Log::warning(self::class.": Nessun dato storico trovato per {$team->name} (ID: {$team->id}) nelle stagioni di lookback richieste (target {$targetSeasonYear} -> controllo: " . implode(', ', $lookbackSeasonsForLog) . "). Assegno punteggio grezzo per neopromossa/sconosciuta.");
            return (float)($this->config['newly_promoted_raw_score_target'] ?? 25.0);
        }
        
        if ($historicalStandings->count() < $lookbackSeasonsCount) {
            Log::warning(self::class.": {$team->name} (ID: {$team->id}) ha solo {$historicalStandings->count()}/{$lookbackSeasonsCount} stagioni storiche nelle stagioni di lookback. Il calcolo del tier potrebbe essere meno accurato.");
        }
        
        $weightedSeasonMetricsSum = 0;
        $totalSeasonWeightApplied = 0;
        
        $applicableSeasonWeights = array_slice($seasonWeightsConfig, 0, $historicalStandings->count());
        $sumOfApplicableWeights = array_sum($applicableSeasonWeights);
        if ($sumOfApplicableWeights > 0 && abs($sumOfApplicableWeights - 1.0) > 1e-9) {
            $applicableSeasonWeights = array_map(function($w) use ($sumOfApplicableWeights) {
                return $w / $sumOfApplicableWeights;
            }, $applicableSeasonWeights);
        }
        
        foreach ($historicalStandings as $index => $standing) {
            if (!isset($applicableSeasonWeights[$index])) {
                Log::error(self::class.": Errore logico: Peso stagione mancante per indice {$index} (stagione {$standing->season_year}) per team {$team->name}.");
                continue;
            }
            $seasonWeight = $applicableSeasonWeights[$index];
            $currentSeasonRawScore = 0;
            $totalMetricWeightUsedThisSeason = 0;
            
            foreach ($metricWeights as $metric => $metricWeight) {
                if (isset($standing->{$metric}) && $standing->{$metric} !== null) {
                    $value = (float)$standing->{$metric};
                    if ($metric === 'position') {
                        $value = max(0, ( ($standing->league_name === 'Serie B' ? 21 : 21) - $value));
                    }
                    $currentSeasonRawScore += $value * $metricWeight;
                    $totalMetricWeightUsedThisSeason += $metricWeight;
                }
            }
            
            if ($totalMetricWeightUsedThisSeason > 0) {
                $leagueStrengthMultipliers = $this->config['league_strength_multipliers'] ?? ['Serie A' => 1.0, 'Serie B' => 0.7];
                $leagueMultiplier = $leagueStrengthMultipliers[$standing->league_name] ?? ($standing->league_name === 'Serie A' ? 1.0 : 0.6);
                $seasonScoreAdjustedForLeague = $currentSeasonRawScore * $leagueMultiplier;
                
                Log::debug(self::class.": Team {$team->name}, Stag. {$standing->season_year} ({$standing->league_name}), P.Stag.Grezzo: {$currentSeasonRawScore}, MultiLega: {$leagueMultiplier}, P.Stag.Agg: {$seasonScoreAdjustedForLeague}, PesoStag: {$seasonWeight}");
                
                $weightedSeasonMetricsSum += $seasonScoreAdjustedForLeague * $seasonWeight;
                $totalSeasonWeightApplied += $seasonWeight;
            } else {
                Log::warning(self::class.": Nessuna metrica valida trovata per {$team->name} nella stagione {$standing->season_year} ({$standing->league_name}). Punteggio stagione sarà 0.");
            }
        }
        
        if ($totalSeasonWeightApplied == 0) {
            Log::warning(self::class.": Impossibile calcolare un punteggio finale pesato per {$team->name} (ID: {$team->id}). Nessun peso stagione applicato. Assegno punteggio neopromossa.");
            return (float)($this->config['newly_promoted_raw_score_target'] ?? 25.0);
        }
        
        $finalStrengthScore = $weightedSeasonMetricsSum / $totalSeasonWeightApplied;
        Log::info(self::class.": Punteggio forza calcolato per {$team->name} (ID: {$team->id}): {$finalStrengthScore}");
        return $finalStrengthScore;
    }
    
    // In app/Services/TeamTieringService.php
    private function assignTier(float $rawStrengthScore, ?float $scoreToUseForThresholds, array $allValidRawScoresForPercentile): int
    {
        $defaultTier = $this->config['newly_promoted_tier_default'] ?? 4; // O 5 se preferisci
        
        if ($this->config['tier_thresholds_source'] === 'config') {
            if ($scoreToUseForThresholds === null) {
                Log::warning(self::class.": Punteggio ('scoreToUseForThresholds') Nullo passato a assignTier con source 'config'. Assegno tier di default: {$defaultTier}.");
                return $defaultTier;
            }
            // $scoreToUseForThresholds è il punteggio normalizzato (0-100)
            $thresholds = $this->config['tier_thresholds_config'] ?? [1=>85, 2=>70, 3=>50, 4=>30, 5=>0];
            // Assicurati che le soglie siano ordinate dalla più alta alla più bassa per un corretto matching
            // ksort($thresholds); // Se le chiavi (tier) non sono già ordinate 1,2,3,4,5
            // No, l'iterazione deve essere sul tier, quindi le chiavi devono essere 1,2,3,4,5
            
            foreach ($thresholds as $tier => $minNormalizedScore) {
                // Arrotonda leggermente per evitare problemi di floating point vicino alle soglie
                if (round($scoreToUseForThresholds, 4) >= round($minNormalizedScore, 4)) {
                    return (int)$tier;
                }
            }
            Log::warning(self::class.": Nessuna soglia valida trovata per score normalizzato {$scoreToUseForThresholds}. Assegno default tier 5.");
            return 5; // Default tier più basso se nessuna soglia corrisponde (non dovrebbe succedere con 5=>0)
        } elseif ($this->config['tier_thresholds_source'] === 'dynamic_percentiles') {
            // ... (logica percentili come prima) ...
            // Ricontrolla questa logica se decidi di usarla, è un po' più complessa
            if (empty($allValidRawScoresForPercentile)) return $defaultTier;
            sort($allValidRawScoresForPercentile);
            $count = count($allValidRawScoresForPercentile);
            $assignedTier = 5;
            $sortedPercentileTiers = $this->config['tier_percentiles_config'] ?? [1=>0.80, 2=>0.60, 3=>0.40, 4=>0.20, 5=>0.0];
            // Assicurati che $sortedPercentileTiers sia ordinato per percentile decrescente se vuoi il break
            // Esempio: [1 => 0.8, 2 => 0.6, 3=>0.4, 4=>0.2, 5=>0.0] - la chiave è il tier, il valore è il percentile
            // Se il punteggio è >= soglia dell'80 percentile -> Tier 1
            // Se il punteggio è >= soglia del 60 percentile (ma < 80) -> Tier 2, etc.
            
            // Corretto approccio per percentili:
            // Tier 1: score >= percentile(0.80)
            // Tier 2: score >= percentile(0.60) AND score < percentile(0.80)
            // etc.
            // Oppure, itera DAL TIER PIU' ALTO:
            foreach($sortedPercentileTiers as $tier => $percentileValue){
                $index = max(0, floor($percentileValue * ($count -1) ));
                $thresholdScore = $allValidRawScoresForPercentile[$index];
                if($rawStrengthScore >= $thresholdScore){ // Confronta il punteggio GREZZO con le soglie di percentile GREZZE
                    return (int)$tier; // Assegna il primo (e quindi miglior) tier che soddisfa
                }
            }
            return 5; // Se non rientra in nessuno, è nel percentile più basso (Tier 5)
        }
        
        Log::warning(self::class.": Metodo soglie tier non riconosciuto: " . ($this->config['tier_thresholds_source'] ?? 'N/D') . ". Ritorno tier di default {$defaultTier}.");
        return $defaultTier;
    }
}