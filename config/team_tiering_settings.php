<?php
return [
    'lookback_seasons_for_tiering' => 3,
    'season_weights' => [
        0 => 0.5,  // Stagione più recente (t-1)
        1 => 0.3,  // Stagione t-2
        2 => 0.2,  // Stagione t-3
    ],
    'metric_weights' => [
        'points' => 0.5, // Punti in classifica
        'goal_difference' => 0.2, // Differenza reti
        'goals_for' => 0.1, // Gol fatti
        'position' => 0.2, // Posizione (da invertire nel calcolo, 1° è meglio)
    ],
    'normalization_method' => 'min_max', // o 'z_score' o custom
    'tier_thresholds_source' => 'config', // 'config' o 'dynamic_percentiles'
    'tier_thresholds_config' => [ // Usato se tier_thresholds_source è 'config'
        // Soglie basate sul "punteggio forza" normalizzato (0-100)
        // Il punteggio più alto è migliore
        1 => 85, // Punteggio >= 85 -> Tier 1
        2 => 70, // Punteggio >= 70 -> Tier 2
        3 => 50, // Punteggio >= 50 -> Tier 3
        4 => 30, // Punteggio >= 30 -> Tier 4
        5 => 0,  // Altrimenti Tier 5
    ],
    'tier_percentiles_config' => [ // Usato se tier_thresholds_source è 'dynamic_percentiles'
        1 => 0.80, // Top 20% delle squadre -> Tier 1 (es. 100%-80%)
        2 => 0.60, // Successivo 20% -> Tier 2 (es. 80%-60%)
        3 => 0.40, // etc.
        4 => 0.20,
        5 => 0.0,
    ],
    'newly_promoted_tier_default' => 4,
    'relegated_team_tier_default' => 5, // Tier da assegnare a squadre retrocesse se rimangono nel DB
    'api_football_data' => [
        'standings_endpoint' => 'competitions/{competitionId}/standings?season={year}', // Verifica endpoint API v4
        'serie_a_competition_id' => 'SA', // Codice o ID per Serie A su Football-Data.org
    ]
];