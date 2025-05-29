<?php

namespace App\Services;

use App\Models\Team;
use App\Models\TeamHistoricalStanding;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class TeamTieringService
{
    private array $config;
    
    public function __construct()
    {
        $this->config = Config::get('team_tiering_settings');
        if (empty($this->config)) {
            Log::error(self::class . " Errore: File di configurazione 'team_tiering_settings.php' non trovato o vuoto.");
            // Potresti voler lanciare un'eccezione qui per bloccare l'uso del servizio se la config è vitale
        }
    }
    
    /**
     * Metodo principale per aggiornare i tier di tutte le squadre di Serie A per una stagione target.
     *
     * @param string $targetSeasonYear Es. "2024-25" (la stagione PER CUI si calcola il tier)
     * @return array Conteggio dei team aggiornati per tier
     */
    public function updateAllTeamTiersForSeason(string $targetSeasonYear): array
    {
        Log::info(self::class . ": Inizio calcolo e aggiornamento tier per la stagione target {$targetSeasonYear}");
        
        // Consideriamo i team che sono marcati come serie_a_team nel DB,
        // presumendo che il TeamSeeder o altri processi li tengano aggiornati
        // per la stagione corrente/prossima.
        $teamsToTier = Team::where('serie_a_team', true)->get();
        
        if ($teamsToTier->isEmpty()) {
            Log::warning(self::class . ": Nessuna squadra di Serie A trovata nel database per il tiering.");
            return ['updated_count' => 0, 'tier_distribution' => []];
        }
        
        $teamStrengthScores = [];
        foreach ($teamsToTier as $team) {
            $strengthScore = $this->calculateTeamStrengthScore($team, $targetSeasonYear);
            if ($strengthScore !== null) {
                $teamStrengthScores[$team->id] = [
                    'name' => $team->name,
                    'score' => $strengthScore
                ];
            }
        }
        
        if (empty($teamStrengthScores)) {
            Log::warning(self::class . ": Nessun punteggio di forza calcolato per le squadre.");
            return ['updated_count' => 0, 'tier_distribution' => []];
        }
        
        // Normalizza i punteggi (es. min-max scaling 0-100) se le soglie sono assolute
        // O prepara per il calcolo dei percentili se le soglie sono basate su percentili
        $scores = array_column($teamStrengthScores, 'score');
        $minScore = min($scores);
        $maxScore = max($scores);
        
        $normalizedScores = [];
        if ($this->config['tier_thresholds_source'] === 'config' && ($maxScore - $minScore) > 0) {
            foreach ($teamStrengthScores as $teamId => $data) {
                $normalizedScores[$teamId] = 100 * ($data['score'] - $minScore) / ($maxScore - $minScore);
                $teamStrengthScores[$teamId]['normalized_score'] = $normalizedScores[$teamId];
            }
        } elseif ($this->config['tier_thresholds_source'] === 'config') { // Tutti i punteggi sono uguali
            foreach ($teamStrengthScores as $teamId => $data) {
                $normalizedScores[$teamId] = 50; // Valore medio arbitrario se tutti uguali
                $teamStrengthScores[$teamId]['normalized_score'] = $normalizedScores[$teamId];
            }
        }
        
        
        $updatedCount = 0;
        $tierDistribution = array_fill(1, 5, 0); // Inizializza conteggio per tier 1-5
        
        foreach ($teamsToTier as $team) {
            if (!isset($teamStrengthScores[$team->id])) {
                Log::warning(self::class.": Punteggio di forza non disponibile per {$team->name} (ID: {$team->id}), tier non aggiornato.");
                continue;
            }
            
            $currentScore = $teamStrengthScores[$team->id]['score'];
            $normalizedScore = $normalizedScores[$team->id] ?? null; // Usato solo se tier_thresholds_source è 'config'
            
            $assignedTier = $this->assignTier($currentScore, $normalizedScore, $scores);
            
            if ($team->tier != $assignedTier) {
                Log::info(self::class . ": Aggiornamento tier per {$team->name} (ID: {$team->id}): Vecchio Tier {$team->tier}, Nuovo Tier {$assignedTier}, Punteggio Forza: {$currentScore}" . ($normalizedScore !== null ? ", Norm: {$normalizedScore}" : ""));
                $team->tier = $assignedTier;
                $team->save();
                $updatedCount++;
            }
            $tierDistribution[$assignedTier]++;
        }
        
        Log::info(self::class . ": Aggiornamento tier completato. Squadre aggiornate: {$updatedCount}. Distribuzione Tier: " . json_encode($tierDistribution));
        return ['updated_count' => $updatedCount, 'tier_distribution' => $tierDistribution, 'team_scores' => $teamStrengthScores];
    }
    
    /**
     * Calcola il punteggio di forza di una squadra basato sullo storico.
     *
     * @param Team $team
     * @param string $targetSeasonYear Es. "2024-25"
     * @return float|null Punteggio di forza o null se non calcolabile
     */
    private function calculateTeamStrengthScore(Team $team, string $targetSeasonYear): ?float
    {
        $lookbackSeasons = $this->config['lookback_seasons_for_tiering'] ?? 3;
        $seasonWeights = $this->config['season_weights'] ?? [0.5, 0.3, 0.2]; // Assicurati che la lunghezza corrisponda a lookbackSeasons
        $metricWeights = $this->config['metric_weights'] ?? ['points' => 1.0];
        
        // Determina gli anni di inizio stagione per cui cercare i dati storici
        // Se targetSeasonYear è "2024-25", l'anno di inizio è 2024.
        // La stagione precedente è "2023-24", con inizio 2023.
        $targetStartYear = (int)substr($targetSeasonYear, 0, 4);
        $historicalSeasonStartYears = [];
        for ($i = 0; $i < $lookbackSeasons; $i++) {
            $historicalSeasonStartYears[] = $targetStartYear - 1 - $i;
        }
        
        $historicalStandings = TeamHistoricalStanding::where('team_id', $team->id)
        ->whereIn('season_year', array_map(function($year) {
            return $year . '-' . substr($year + 1, 2, 2);
        }, $historicalSeasonStartYears))
        ->orderBy('season_year', 'desc') // La più recente per prima
        ->get();
        
        if ($historicalStandings->count() < $lookbackSeasons && $historicalStandings->count() > 0) {
            Log::warning(self::class.": {$team->name} ha solo {$historicalStandings->count()}/{$lookbackSeasons} stagioni storiche. Il calcolo del tier potrebbe essere meno accurato.");
        } elseif ($historicalStandings->isEmpty()) {
            Log::warning(self::class.": Nessun dato storico trovato per {$team->name} nelle stagioni richieste. Assegno tier di default per neopromossa/sconosciuta.");
            // Potresti voler restituire un punteggio che porti al tier di default per neopromosse.
            // Per ora, restituiamo null e lasciamo che updateAllTeamTiers gestisca il caso.
            // Oppure, potremmo avere una logica qui per assegnare un punteggio molto basso.
            // Per un punteggio che porti a un tier di default (es. 4 o 5), potremmo restituire un valore basso.
            // Questo dipende da come sono impostate le soglie. Se 0-30 è tier 5, e 30-50 è tier 4.
            $defaultTierForNew = $this->config['newly_promoted_tier_default'] ?? 4;
            // Trova un punteggio che corrisponda a quel tier
            if ($this->config['tier_thresholds_source'] === 'config') {
                $thresholds = $this->config['tier_thresholds_config'] ?? [1=>85, 2=>70, 3=>50, 4=>30, 5=>0];
                return (float)($thresholds[$defaultTierForNew] ?? 25); // Ritorna il limite inferiore o un valore nel range
            }
            return null; // Se non si usa config per soglie, diventa più complesso dare un punteggio default
        }
        
        $weightedSeasonScores = [];
        $totalWeightUsed = 0;
        
        foreach ($historicalStandings as $index => $standing) {
            if (!isset($seasonWeights[$index])) {
                Log::warning(self::class.": Peso stagione mancante per indice {$index} per team {$team->name}. Salto questa stagione.");
                continue;
            }
            $seasonWeight = $seasonWeights[$index];
            $currentSeasonScore = 0;
            $totalMetricWeightUsedThisSeason = 0;
            
            foreach ($metricWeights as $metric => $metricWeight) {
                if (isset($standing->{$metric}) && $standing->{$metric} !== null) {
                    $value = (float)$standing->{$metric};
                    if ($metric === 'position') { // Inverti la posizione (1° è meglio)
                        // Semplice inversione: max_pos (es. 20) - pos + 1. O normalizza diversamente.
                        // Per ora, un modo semplice è usare 1/posizione, poi si normalizzerà globalmente.
                        // O, se si normalizza per metrica: (max_pos - pos) / (max_pos - min_pos)
                        // Temporaneamente, usiamo una scala: (21 - posizione) -> più alto è meglio
                        $value = 21 - $value;
                    }
                    $currentSeasonScore += $value * $metricWeight;
                    $totalMetricWeightUsedThisSeason += $metricWeight;
                }
            }
            
            if ($totalMetricWeightUsedThisSeason > 0) {
                // Normalizza il punteggio della stagione se i pesi delle metriche non sommano a 1
                // $currentSeasonScore = $currentSeasonScore / $totalMetricWeightUsedThisSeason;
                // Non normalizzare qui, la normalizzazione globale dei punteggi finali è più robusta
                $weightedSeasonScores[] = $currentSeasonScore * $seasonWeight;
                $totalWeightUsed += $seasonWeight;
            }
        }
        
        if ($totalWeightUsed == 0 || empty($weightedSeasonScores)) {
            Log::warning(self::class.": Impossibile calcolare un punteggio stagione pesato per {$team->name}. Pesi usati: {$totalWeightUsed}");
            return null;
        }
        
        // Il punteggio finale è la somma dei punteggi stagione pesati, normalizzata per la somma dei pesi usati
        $finalStrengthScore = array_sum($weightedSeasonScores) / $totalWeightUsed;
        Log::info(self::class.": Punteggio forza calcolato per {$team->name}: {$finalStrengthScore}");
        return $finalStrengthScore;
    }
    
    /**
     * Assegna un tier basato sul punteggio di forza.
     *
     * @param float $strengthScore Punteggio grezzo
     * @param float|null $normalizedScore Punteggio normalizzato (0-100), se applicabile
     * @param array $allScores Array di tutti i punteggi grezzi (per percentile)
     * @return int Tier assegnato
     */
    private function assignTier(float $strengthScore, ?float $normalizedScore, array $allScores): int
    {
        if ($this->config['tier_thresholds_source'] === 'config') {
            $thresholds = $this->config['tier_thresholds_config'] ?? [1=>85, 2=>70, 3=>50, 4=>30, 5=>0];
            // Le soglie sono il limite INFERIORE per quel tier (punteggio >= soglia)
            // Quindi, se normalizedScore è 90, è Tier 1 (>=85)
            // Se è 75, è Tier 2 (>=70)
            // Se è 35, è Tier 4 (>=30)
            // Se è 10, è Tier 5 (>=0)
            // Iteriamo dal tier più alto (1) al più basso (5)
            for ($tier = 1; $tier <= 5; $tier++) {
                if (isset($thresholds[$tier]) && $normalizedScore >= $thresholds[$tier]) {
                    return $tier;
                }
            }
            return 5; // Default tier più basso se nessuna soglia corrisponde (non dovrebbe succedere con 5=>0)
        } elseif ($this->config['tier_thresholds_source'] === 'dynamic_percentiles') {
            $percentilesConfig = $this->config['tier_percentiles_config'] ?? [1=>0.80, 2=>0.60, 3=>0.40, 4=>0.20, 5=>0.0];
            sort($allScores); // Ordina i punteggi grezzi
            $count = count($allScores);
            
            // Iteriamo dal tier più alto (1) al più basso (5)
            foreach($percentilesConfig as $tier => $percentileValue) {
                $index = floor($percentileValue * ($count -1)); // Indice del percentile
                $thresholdScore = $allScores[$index];
                if($strengthScore >= $thresholdScore) {
                    // Caso speciale per l'ultimo percentile (0.0) che potrebbe includere tutti se il punteggio è esattamente il minimo
                    // Se è l'ultimo tier della config e stiamo ancora valutando, assegna questo tier.
                    if ($percentileValue == min(array_values($percentilesConfig))) return $tier;
                    // Altrimenti, se il punteggio è >= soglia del percentile, è quel tier
                    return $tier;
                }
            }
            return 5; // Default al tier più basso
        }
        
        Log::warning(self::class.": Metodo soglie tier non riconosciuto: " . $this->config['tier_thresholds_source'] . ". Ritorno tier di default 5.");
        return 5; // Fallback
    }
}