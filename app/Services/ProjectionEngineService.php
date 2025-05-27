<?php

namespace App\Services;

use App\Models\Player;
use App\Models\HistoricalPlayerStat;
use App\Models\Team;
use App\Models\UserLeagueProfile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection; // Utile per manipolare collezioni di dati

class ProjectionEngineService
{
    protected FantasyPointCalculatorService $pointCalculator;
    
    public function __construct(FantasyPointCalculatorService $pointCalculator)
    {
        $this->pointCalculator = $pointCalculator;
    }
    
    /**
     * Genera le proiezioni statistiche e la FantaMedia per un singolo giocatore.
     *
     * @param Player $player Il giocatore per cui generare le proiezioni.
     * @param UserLeagueProfile $leagueProfile Il profilo della lega dell'utente con le regole.
     * @param int $numberOfSeasonsToConsider Numero di stagioni storiche da considerare.
     * @param array $seasonWeights Pesi per le stagioni (es. [0.5, 0.3, 0.2] per le ultime 3, la più recente prima).
     * @return array Un array contenente le statistiche proiettate e la fanta_media_proiettata.
     * Esempio: ['mv_proj' => 6.5, 'gol_fatti_proj' => 5, ..., 'fanta_media_proj' => 7.25]
     */
    public function generatePlayerProjection(
        Player $player,
        UserLeagueProfile $leagueProfile,
        int $numberOfSeasonsToConsider = 3,
        array $seasonWeights = [] // Se vuoto, useremo pesi uguali o una logica di default
        ): array
        {
            Log::info("ProjectionEngineService: Inizio proiezioni per giocatore ID {$player->fanta_platform_id} ({$player->name})");
            
            // 1. Recupera le statistiche storiche del giocatore
            $historicalStats = HistoricalPlayerStat::where('player_fanta_platform_id', $player->fanta_platform_id)
            ->orderBy('season_year', 'desc') // Dalla più recente alla più vecchia
            ->take($numberOfSeasonsToConsider)
            ->get();
            
            if ($historicalStats->isEmpty()) {
                Log::warning("ProjectionEngineService: Nessuna statistica storica trovata per giocatore ID {$player->fanta_platform_id}. Impossibile generare proiezioni.");
                // Potresti restituire proiezioni di default o basate solo sul ruolo/squadra (molto grezzo)
                return $this->getDefaultProjectionsForRole($player->role, $player->team ? $player->team->tier : null);
            }
            
            // 2. Prepara i pesi per le stagioni se non forniti o non corrispondenti
            if (empty($seasonWeights) || count($seasonWeights) !== $historicalStats->count()) {
                // Default: pesi uguali o più peso alla più recente
                $seasonWeights = $this->calculateDefaultSeasonWeights($historicalStats->count());
                Log::info("ProjectionEngineService: Pesi stagionali di default calcolati: " . json_encode($seasonWeights));
            }
            
            // 3. Calcola le medie ponderate delle statistiche chiave
            $weightedStats = $this->calculateWeightedAverageStats($historicalStats, $seasonWeights);
            Log::debug("ProjectionEngineService: Statistiche medie ponderate calcolate: " . json_encode($weightedStats));
            
            // 4. Applica aggiustamenti (età, tier squadra, ruolo tattico, ecc.) - DA IMPLEMENTARE
            $adjustedStats = $this->applyAdjustments($weightedStats, $player, $leagueProfile);
            Log::debug("ProjectionEngineService: Statistiche aggiustate: " . json_encode($adjustedStats));
            
            // 5. Stima il minutaggio/presenze attese - DA IMPLEMENTARE (fattore cruciale)
            // Per ora, potremmo usare le presenze medie storiche o un valore fisso
            $projectedGamesPlayed = $adjustedStats['avg_games_played'] ?? ($historicalStats->avg('games_played') ?: 20); // Esempio grezzo
            $adjustedStats['presenze_attese'] = round($projectedGamesPlayed);
            
            // 6. Normalizza/Scala le statistiche proiettate in base alle presenze attese
            // Ad esempio, se la media gol è per partita, moltiplica per le presenze attese.
            // Questa logica dipende da come sono calcolate le medie ponderate.
            // Per ora, assumiamo che $adjustedStats contenga già valori totali proiettati per la stagione.
            // Se invece sono medie per partita, andrebbero scalate qui.
            
            // 7. Prepara l'array di statistiche finali per il calcolatore di punti
            // Le chiavi qui devono corrispondere a quelle attese da FantasyPointCalculatorService
            // e a quelle che vuoi visualizzare.
            $finalProjectedStats = [
                'mv' => $adjustedStats['avg_rating'] ?? 6.0, // Media Voto proiettata
                'gol_fatti' => $adjustedStats['goals_scored'] ?? 0,
                'assist' => $adjustedStats['assists'] ?? 0,
                'ammonizioni' => $adjustedStats['yellow_cards'] ?? 0,
                'espulsioni' => $adjustedStats['red_cards'] ?? 0,
                'autogol' => $adjustedStats['own_goals'] ?? 0,
                'rigori_segnati' => $adjustedStats['penalties_scored'] ?? 0,
                'rigori_sbagliati' => $adjustedStats['penalties_missed'] ?? 0,
                // Specifiche portiere
                'rigori_parati' => $adjustedStats['penalties_saved'] ?? 0,
                'gol_subiti' => $adjustedStats['goals_conceded'] ?? 0,
                'clean_sheet' => $adjustedStats['clean_sheets_proj'] ?? 0, // Da calcolare/proiettare
                // Altre statistiche necessarie per il calcolo dei punti...
            ];
            
            // 8. Calcola la FantaMedia Proiettata
            $fantaMediaProjected = $this->pointCalculator->calculateFantasyPoints(
                $finalProjectedStats,
                $leagueProfile->scoring_rules ?? [], // Assicura che scoring_rules sia un array
                $player->role
                );
            
            Log::info("ProjectionEngineService: FantaMedia proiettata per {$player->name}: {$fantaMediaProjected}");
            
            return array_merge($finalProjectedStats, ['fanta_media_proj' => $fantaMediaProjected, 'presenze_proj' => $adjustedStats['presenze_attese']]);
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
        // Esempio: 3 stagioni -> [0.5, 0.3, 0.2] o [3/6, 2/6, 1/6]
        // Esempio semplice: lineare decrescente
        $totalWeightParts = 0;
        for ($i = $numberOfSeasons; $i >= 1; $i--) {
            $totalWeightParts += $i;
        }
        
        if ($totalWeightParts === 0) return array_fill(0, $numberOfSeasons, 1/$numberOfSeasons); // Pesi uguali
        
        for ($i = $numberOfSeasons; $i >= 1; $i--) {
            $weights[] = $i / $totalWeightParts;
        }
        return $weights; // La più recente ha il peso maggiore (primo elemento)
    }
    
    /**
     * Calcola le medie ponderate delle statistiche chiave.
     * Le statistiche sono già ordinate dalla più recente.
     */
    private function calculateWeightedAverageStats(Collection $historicalStats, array $seasonWeights): array
    {
        $weightedAverages = [
            'avg_rating' => 0.0, 'goals_scored' => 0.0, 'assists' => 0.0,
            'yellow_cards' => 0.0, 'red_cards' => 0.0, 'own_goals' => 0.0,
            'penalties_scored' => 0.0, 'penalties_missed' => 0.0,
            'penalties_saved' => 0.0, 'goals_conceded' => 0.0,
            'avg_games_played' => 0.0, // Media ponderata delle presenze
            // Aggiungi altre statistiche che vuoi ponderare
        ];
        $totalGamesPlayedWeighted = 0;
        $totalWeightSumForPerGameStats = 0;
        
        foreach ($historicalStats as $index => $stats) {
            $weight = $seasonWeights[$index] ?? (1 / $historicalStats->count()); // Fallback a pesi uguali
            $games = $stats->games_played > 0 ? $stats->games_played : 1; // Evita divisione per zero, ma considera l'impatto
            
            // Pondera le medie per partita o le statistiche totali normalizzate per partita
            if ($stats->games_played > 0) {
                $weightedAverages['avg_rating'] += ($stats->avg_rating ?? 6.0) * $weight; // Pondera la media voto
                
                // Per le statistiche che sono totali stagionali, le normalizziamo per partita prima di ponderarle,
                // oppure le ponderiamo come totali e poi le normalizziamo.
                // Qui le ponderiamo come medie per partita.
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
        
        // Normalizza le medie per partita se abbiamo sommato pesi parziali
        if ($totalWeightSumForPerGameStats > 0) {
            foreach (['avg_rating', 'goals_scored', 'assists', 'yellow_cards', 'red_cards', 'own_goals', 'penalties_scored', 'penalties_missed', 'penalties_saved', 'goals_conceded'] as $key) {
                $weightedAverages[$key] = $weightedAverages[$key] / $totalWeightSumForPerGameStats;
            }
        }
        
        // Ora, le statistiche come 'goals_scored' sono medie ponderate *per partita*.
        // Dovranno essere moltiplicate per le presenze attese per ottenere i totali stagionali proiettati.
        // Lo faremo dopo gli aggiustamenti.
        
        return $weightedAverages;
    }
    
    /**
     * Applica aggiustamenti alle statistiche ponderate (età, tier squadra, ecc.).
     * Questa è una funzione placeholder da espandere.
     */
    private function applyAdjustments(array $weightedStats, Player $player, UserLeagueProfile $leagueProfile): array
    {
        $adjustedStats = $weightedStats; // Inizia con le medie ponderate
        
        // Esempio: Aggiustamento per Tier Squadra (molto semplificato)
        $teamTier = $player->team ? $player->team->tier : 3; // Default a tier medio se non disponibile
        $tierMultiplier = 1.0;
        
        // Ipotizziamo 5 tier, dove 1 è il migliore, 5 il peggiore.
        // Questi moltiplicatori sono puramente esemplificativi.
        switch ($teamTier) {
            case 1: $tierMultiplier = 1.15; break; // Squadra Top
            case 2: $tierMultiplier = 1.05; break; // Squadra Buona
            case 3: $tierMultiplier = 1.0;  break; // Squadra Media
            case 4: $tierMultiplier = 0.95; break; // Squadra Debole
            case 5: $tierMultiplier = 0.85; break; // Squadra Molto Debole
        }
        
        // Applica il moltiplicatore alle statistiche offensive
        foreach (['goals_scored', 'assists'] as $key) {
            if (isset($adjustedStats[$key])) {
                $adjustedStats[$key] *= $tierMultiplier;
            }
        }
        // Per i difensori/portieri, il tier potrebbe influenzare i gol subiti o clean sheet in modo inverso.
        // Esempio: $adjustedStats['goals_conceded'] /= $tierMultiplier; (se tierMultiplier > 1 per squadre forti)
        
        // TODO: Aggiungere aggiustamenti per età, ruolo tattico specifico, rigoristi (da stats Rc/R+), etc.
        // TODO: Calcolare 'clean_sheets_proj' basandosi sul ruolo e tier squadra.
        
        // Esempio grezzo per proiezione gol da rigore se il giocatore ne calcia
        // Questo andrebbe raffinato con probabilità e numero atteso di rigori per la squadra.
        if (isset($adjustedStats['penalties_scored']) && isset($player->historicalStats)) {
            $totalPenaltiesTakenHistorically = $player->historicalStats()->sum('penalties_taken'); // Rc
            $totalPenaltiesScoredHistorically = $player->historicalStats()->sum('penalties_scored'); // R+
            
            if ($totalPenaltiesTakenHistorically > 2) { // Se ha tirato almeno qualche rigore
                // Potremmo stimare una % di realizzazione e un numero di rigori che potrebbe tirare
                // $penaltyConversionRate = $totalPenaltiesScoredHistorically / $totalPenaltiesTakenHistorically;
                // $expectedPenaltiesToTake = ... (stima basata su squadra, ruolo, storico)
                // $adjustedStats['goals_from_penalties_proj'] = $expectedPenaltiesToTake * $penaltyConversionRate;
                // E poi $adjustedStats['goals_scored'] potrebbe essere la somma dei gol su azione + rigore
            }
        }
        
        
        // Dopo tutti gli aggiustamenti, le statistiche sono ancora medie *per partita*.
        // Le moltiplicheremo per le presenze attese nel metodo principale.
        // Per ora, le restituiamo come medie aggiustate per partita.
        // Il passo successivo sarà scalarle per le presenze attese.
        
        // Scala per le presenze attese per ottenere i totali stagionali
        $presenzeAttese = $adjustedStats['avg_games_played'] ?? ($player->historicalStats->avg('games_played') ?: 20);
        $presenzeAttese = round($presenzeAttese * $tierMultiplier); // Il tier può influenzare anche le presenze
        $presenzeAttese = max(1, min(38, $presenzeAttese)); // Limita tra 1 e 38
        
        $statsToScale = ['goals_scored', 'assists', 'yellow_cards', 'red_cards', 'own_goals',
            'penalties_scored', 'penalties_missed', 'penalties_saved', 'goals_conceded'];
        
        foreach ($statsToScale as $key) {
            if (isset($adjustedStats[$key])) {
                $adjustedStats[$key] = $adjustedStats[$key] * $presenzeAttese;
            }
        }
        // avg_rating rimane una media
        // avg_games_played è già una media ponderata, lo usiamo per le presenze attese
        
        $adjustedStats['presenze_attese'] = $presenzeAttese;
        
        
        return $adjustedStats;
    }
    
    
    /**
     * Fornisce proiezioni di default se non ci sono dati storici.
     * Molto grezzo, da migliorare.
     */
    private function getDefaultProjectionsForRole(string $role, ?int $teamTier): array
    {
        Log::warning("ProjectionEngineService: Utilizzo proiezioni di default per ruolo {$role} e tier {$teamTier}");
        // Valori base molto generici, da calibrare o rendere più intelligenti
        $baseMv = 5.8;
        $baseGoals = 0;
        $baseAssists = 0;
        
        switch (strtoupper($role)) {
            case 'P': $baseMv = 6.0; break;
            case 'D': $baseMv = 5.9; $baseGoals = 1; $baseAssists = 1; break;
            case 'C': $baseMv = 6.0; $baseGoals = 3; $baseAssists = 3; break;
            case 'A': $baseMv = 6.1; $baseGoals = 8; $baseAssists = 2; break;
        }
        
        // Applica un semplice modificatore di tier
        $tierMultiplier = 1.0;
        if ($teamTier) {
            switch ($teamTier) {
                case 1: $tierMultiplier = 1.2; break;
                case 2: $tierMultiplier = 1.1; break;
                case 3: $tierMultiplier = 1.0; break;
                case 4: $tierMultiplier = 0.9; break;
                case 5: $tierMultiplier = 0.8; break;
            }
        }
        
        return [
            'mv' => $baseMv,
            'gol_fatti' => round($baseGoals * $tierMultiplier),
            'assist' => round($baseAssists * $tierMultiplier),
            'ammonizioni' => 2,
            'espulsioni' => 0,
            'autogol' => 0,
            'rigori_segnati' => 0,
            'rigori_sbagliati' => 0,
            'rigori_parati' => 0,
            'gol_subiti' => (strtoupper($role) === 'P' ? round(30 / $tierMultiplier) : 0),
            'clean_sheet' => (strtoupper($role) === 'P' || strtoupper($role) === 'D' ? round(5 * $tierMultiplier) : 0),
            'presenze_attese' => 15, // Valore molto arbitrario
            'fanta_media_proj' => $baseMv, // Da ricalcolare con il calculator
        ];
    }
}
