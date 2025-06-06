<?php

namespace App\Services;

use App\Models\Player;
use App\Models\UserLeagueProfile;
use App\Models\HistoricalPlayerStat;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ProjectionEngineService
{
    protected array $leagueConversionFactors;
    protected array $ageCurves;
    protected array $regressionMeans;
    protected array $tierFactors;
    protected array $seasonWeights;
    protected FantasyPointCalculatorService $fantasyPointCalculator;
    
    public function __construct(FantasyPointCalculatorService $fantasyPointCalculator)
    {
        $this->fantasyPointCalculator = $fantasyPointCalculator;
        
        // WORKAROUND: CONFIGURAZIONI HARDCODED
        $this->seasonWeights = config('projection_settings.season_weights', ['default' => 0.25, 0 => 1.0, 1 => 0.75, 2 => 0.50]);
        $this->tierFactors = config('projection_settings.team_tier_factors', [1 => 1.05, 2 => 1.0, 3 => 0.95, 4 => 0.90]);
        $this->leagueConversionFactors = ['Serie A' => ['avg_rating' => 1.0, 'goals_scored' => 1.0, 'assists' => 1.0], 'Serie B' => ['avg_rating' => 0.95, 'goals_scored' => 0.6, 'assists' => 0.65], 'default' => ['avg_rating' => 0.9, 'goals_scored' => 0.5, 'assists' => 0.5]];
        $this->regressionMeans = ['regression_factor' => 0.3, 'means_by_role' => ['P' => ['avg_rating' => 6.20, 'fanta_mv_proj' => 5.5],'D' => ['avg_rating' => 6.05, 'fanta_mv_proj' => 6.0],'C' => ['avg_rating' => 6.10, 'fanta_mv_proj' => 6.5],'A' => ['avg_rating' => 6.15, 'fanta_mv_proj' => 7.0],],'default_means' => ['avg_rating' => 6.10, 'fanta_mv_proj' => 6.2]];
        $this->ageCurves = ['P' => ['17-21' => 0.75, '22-24' => 0.90, '25-33' => 1.05, '34-36' => 0.95, '37-45' => 0.80],'D' => ['17-20' => 0.80, '21-23' => 0.95, '24-31' => 1.05, '32-34' => 0.90, '35-45' => 0.75],'C' => ['17-19' => 0.75, '20-22' => 0.90, '23-29' => 1.05, '30-32' => 0.95, '33-45' => 0.80],'A' => ['17-19' => 0.70, '20-22' => 0.90, '23-29' => 1.05, '30-32' => 0.90, '33-45' => 0.75],'default' => ['17-19' => 0.75, '20-22' => 0.90, '23-29' => 1.00, '30-32' => 0.95, '33-45' => 0.80]];
        
        Log::info("ProjectionEngineService initializzato con configurazioni HARDCODED.");
    }
    
    public function generatePlayerProjection(Player $player, UserLeagueProfile $leagueProfile): array
    {
        Log::info("Inizio proiezioni per giocatore ID {$player->fanta_platform_id} ({$player->name})");
        $weightedStats = $this->calculateWeightedAverageStats($player);
        if (empty($weightedStats) || $weightedStats['avg_games_played'] == 0) {
            Log::warning("Nessuno storico valido trovato per {$player->name}, impossibile generare proiezioni.");
            return [];
        }
        $expectedGames = $this->estimateGamesPlayed($player, $weightedStats['avg_games_played']);
        $adjustedStats = [];
        foreach ($weightedStats as $key => $value) {
            if ($key !== 'avg_games_played') {
                $adjustedStats[$key] = $this->applyRegressionToMean($value, $player->role, $key);
            }
        }
        $projectedFantaMediaPerGame = $this->fantasyPointCalculator->calculateFantasyPoints($this->mapStatsForFantasyPoints($adjustedStats), $leagueProfile->scoring_rules, $player->role);
        $projectedAvgRating = $this->applyRegressionToMean($adjustedStats['avg_rating'], $player->role, 'avg_rating');
        $totalFantaPoints = $projectedFantaMediaPerGame * $expectedGames;
        return [
            'avg_rating_proj' => round($projectedAvgRating, 2),
            'fanta_mv_proj' => round($projectedFantaMediaPerGame, 2),
            'games_played_proj' => round($expectedGames),
            'total_fanta_points_proj' => round($totalFantaPoints, 2),
        ];
    }
    
    protected function calculateWeightedAverageStats(Player $player): array
    {
        $historicalStats = HistoricalPlayerStat::where('player_fanta_platform_id', $player->fanta_platform_id)->get();
        if ($historicalStats->isEmpty()) return [];
        $totalWeight = 0;
        $statsTotals = array_fill_keys(['avg_rating', 'goals_scored', 'assists', 'yellow_cards', 'red_cards', 'own_goals', 'penalties_taken', 'penalties_scored', 'penalties_missed', 'penalties_saved', 'goals_conceded'], 0);
        $totalGamesPlayed = 0; $seasonsInCalculation = 0;
        foreach ($historicalStats as $stat) {
            $yearDiff = date('Y') - intval(substr($stat->season_year, 0, 4));
            $weight = $this->seasonWeights[$yearDiff] ?? $this->seasonWeights['default'];
            if (($stat->games_played ?? 0) < 5) $weight *= 0.5;
            if ($weight === 0) continue;
            $conversionFactors = $this->leagueConversionFactors[$stat->league_name] ?? ($this->leagueConversionFactors['default'] ?? []);
            if ($stat->games_played > 0) {
                foreach (array_keys($statsTotals) as $key) {
                    $conversionFactor = $conversionFactors[$key] ?? 1.0;
                    $convertedValue = ($stat->{$key} ?? 0) * $conversionFactor;
                    $statsTotals[$key] += ($convertedValue / $stat->games_played) * $weight;
                }
            }
            $totalGamesPlayed += $stat->games_played * $weight; $seasonsInCalculation++; $totalWeight += $weight;
        }
        if ($totalWeight == 0) return [];
        $weightedAverages = [];
        foreach ($statsTotals as $key => $total) { $weightedAverages[$key] = $total / $totalWeight; }
        $weightedAverages['avg_games_played'] = $seasonsInCalculation > 0 ? $totalGamesPlayed / $seasonsInCalculation : 0;
        return $weightedAverages;
    }
    
    protected function estimateGamesPlayed(Player $player, float $historicalAvgGames): float
    {
        $age = $player->date_of_birth ? Carbon::parse($player->date_of_birth)->age : 26;
        $tier = $player->team->tier ?? 3;
        $tierFactor = $this->tierFactors[$tier] ?? 1.0;
        $ageFactor = $this->getAgeFactor($age, $player->role);
        $expectedGames = $historicalAvgGames * $tierFactor * $ageFactor;
        return min($expectedGames, 38.0);
    }
    
    protected function getAgeFactor(int $age, string $role): float
    {
        $roleCurves = $this->ageCurves[$role] ?? $this->ageCurves['default'];
        foreach ($roleCurves as $ageBracket => $curveFactor) {
            list($min, $max) = explode('-', $ageBracket);
            if ($age >= $min && $age <= $max) return $curveFactor;
        }
        return 1.0;
    }
    
    protected function applyRegressionToMean(float $value, string $role, string $statKey): float
    {
        $mean = $this->regressionMeans['means_by_role'][$role][$statKey] ?? $this->regressionMeans['default_means'][$statKey] ?? $value;
        $regressionFactor = $this->regressionMeans['regression_factor'];
        return ($value * (1 - $regressionFactor)) + ($mean * $regressionFactor);
    }
    
    protected function mapStatsForFantasyPoints(array $stats): array
    {
        return [
            'mv' => $stats['avg_rating'], 'gol_fatti' => $stats['goals_scored'], 'assist' => $stats['assists'],
            'ammonizioni' => $stats['yellow_cards'], 'espulsioni' => $stats['red_cards'], 'autogol' => $stats['own_goals'],
            'rigori_segnati' => $stats['penalties_scored'], 'rigori_sbagliati' => $stats['penalties_missed'],
            'rigori_parati' => $stats['penalties_saved'], 'gol_subiti' => $stats['goals_conceded'],
        ];
    }
}