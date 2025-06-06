<?php

return [
    // Parametri Rigoristi:
    'penalty_taker_lookback_seasons' => 2,       // Usato specificamente per identificare un rigorista
    'min_penalties_taken_threshold' => 3,        // Minimo rigori calciati per essere considerato rigorista con tasso affidabile
    'league_avg_penalties_awarded' => 0.20,      // Rigori medi per squadra a partita
    'penalty_taker_share' => 0.85,               // Quota rigori del rigorista designato
    'default_penalty_conversion_rate' => 0.75,
    'min_penalties_taken_for_reliable_conversion_rate' => 5, // Min rigori calciati per tasso affidabile
    'lookback_seasons_penalty_taker_check' => 3, // Specifica per quanti anni guardare indietro per lo status di rigorista
    
    // Gestione Dati Storici e Medie:
    'lookback_seasons' => 4,                           // Numero di stagioni storiche da considerare per le medie generali
    'season_decay_factor' => 0.75,                     // Fattore di decadimento per stagioni più vecchie (1.0 = nessun decadimento)
    
    'fields_to_project' => [                         // Campi base da cui partire per le proiezioni (dalle medie storiche)
        'avg_rating',
        'fanta_avg_rating',
        'goals_scored',
        'assists',
        'yellow_cards',
        'red_cards',
        'own_goals',
        'penalties_taken',
        'penalties_scored',
        'penalties_missed',
        'penalties_saved',
        'goals_conceded',
    ],
    
    'min_games_for_reliable_avg_rating' => 10,         // Minimo partite per considerare affidabile un avg_rating stagionale
    'default_team_tier' => 3,                          // Tier di default per squadre senza tier specificato
    
    // Impatto del Tier Squadra:
    'team_tier_multipliers_offensive' => [
        1 => 1.2,  // Tier 1 (es. top team)
        2 => 1.1,  // Tier 2
        3 => 1.0,  // Tier 3 (medio)
        4 => 0.9,  // Tier 4
        5 => 0.8,  // Tier 5 (es. neopromossa debole)
    ],
    'team_tier_multipliers_defensive' => [ // Valori inversi o specifici per la difesa
        1 => 0.8,  // Tier 1 (subisce meno)
        2 => 0.9,  // Tier 2
        3 => 1.0,  // Tier 3
        4 => 1.1,  // Tier 4
        5 => 1.2,  // Tier 5 (subisce di più)
    ],
    'team_tier_presence_factor' => [ // Impatto del tier sulle presenze attese
        1 => 1.05, // Top team potrebbero far ruotare di più ma i titolarissimi giocano
        2 => 1.02,
        3 => 1.0,
        4 => 0.98,
        5 => 0.95, // Squadre deboli potrebbero spremere i migliori
    ],
    'offensive_stats_fields' => ['goals_scored', 'assists'], // Campi influenzati dal tier offensivo
    'defensive_stats_fields_goalkeeper' => ['goals_conceded'], // Campi influenzati dal tier difensivo (solo per portieri)
    
    // Clean Sheet:
    'league_average_clean_sheet_rate_per_game' => 0.28, // Tasso medio di clean sheet per partita nella lega
    'clean_sheet_probabilities_by_tier' => [
        1 => 0.40, // Squadra Top Tier ha 40% prob. base di CS
        2 => 0.33,
        3 => 0.28, // Media lega
        4 => 0.22,
        5 => 0.18, // Squadra debole ha 18% prob. base di CS
    ],
    'max_clean_sheet_probability' => 0.80, // Limite massimo probabilità CS proiettata
    
    // Media gol subiti nella lega (per stima dei difensori)
    'league_average_goals_conceded_per_game' => 1.3, // <-- NUOVO: Valore medio di gol subiti per partita nella lega
    
    // Valori di Default e Fallback:
    'default_player_age' => 25,                        // Età di default se non disponibile
    'default_games_played_for_penalty_check' => 25,    // Usato se avg_games_played non è disponibile per isLikelyPenaltyTaker
    'fallback_mv_if_no_history' => 5.5,
    'fallback_fm_if_no_history' => 5.5,
    'fallback_gp_if_no_history' => 0,
    'min_projected_presences' => 5,
    'max_projected_presences' => 38,
    
    // Default per getDefaultStatsPerGameForRole
    'default_stats_per_role' => [
        'P' => ['mv' => 5.8, 'goals_scored' => 0.0, 'assists' => 0.0, 'yellow_cards' => 0.1, 'red_cards' => 0.005, 'own_goals' => 0.002, 'penalties_taken' => 0.0, 'penalties_saved' => 0.0, 'goals_conceded' => 1.5, 'clean_sheet' => 0.20],
        'D' => ['mv' => 5.8, 'goals_scored' => 0.02, 'assists' => 0.02, 'yellow_cards' => 0.15, 'red_cards' => 0.01, 'own_goals' => 0.005, 'penalties_taken' => 0.0, 'penalties_saved' => 0.0, 'goals_conceded' => 0.0, 'clean_sheet' => 0.15],
        'C' => ['mv' => 6.0, 'goals_scored' => 0.05, 'assists' => 0.05, 'yellow_cards' => 0.18, 'red_cards' => 0.01, 'own_goals' => 0.003, 'penalties_taken' => 0.0, 'penalties_saved' => 0.0, 'goals_conceded' => 0.0, 'clean_sheet' => 0.0],
        'A' => ['mv' => 6.0, 'goals_scored' => 0.15, 'assists' => 0.08, 'yellow_cards' => 0.1, 'red_cards' => 0.005, 'own_goals' => 0.001, 'penalties_taken' => 0.0, 'penalties_saved' => 0.0, 'goals_conceded' => 0.0, 'clean_sheet' => 0.0],
    ],
    'default_age_effect_multiplier_young' => 0.5, // Moltiplicatore per l'effetto età sui default per i giovani
    'default_age_effect_multiplier_old' => 0.8,   // Moltiplicatore per l'effetto età sui default per gli anziani
    'default_age_modifier_min_cap' => 0.7,        // Cap minimo per age modifier nei default
    'default_age_modifier_max_cap' => 1.15,       // Cap massimo per age modifier nei default
    'default_mv_min_cap' => 5.0,                  // Cap minimo per MV proiettata
    'default_mv_max_cap' => 7.5,                  // Cap massimo per MV proiettata
    
    // Default presenze (usato da estimateDefaultPresences)
    'default_presences_map' => [
        'base' => 20, // Valore base per le presenze di default
        'P' => ['default' => 25, 'tier1' => 30, 'tier2' => 28, 'tier3' => 25, 'tier4' => 22, 'tier5' => 20],
        'D' => ['default' => 28, 'tier1' => 32, 'tier2' => 30, 'tier3' => 28, 'tier4' => 25, 'tier5' => 22],
        'C' => ['default' => 28, 'tier1' => 32, 'tier2' => 30, 'tier3' => 28, 'tier4' => 25, 'tier5' => 22],
        'A' => ['default' => 25, 'tier1' => 30, 'tier2' => 28, 'tier3' => 25, 'tier4' => 22, 'tier5' => 20],
    ],
    'default_presences_low_tier_factor' => 0.9, // Fattore per ridurre le presenze in squadre di basso tier (se non ci sono tier specifici)
    
    // Configurazione Output Proiezioni:
    'fields_to_project_output' => [
        'avg_rating_proj'       => ['type' => 'avg', 'default_value' => 6.0],
        'fanta_mv_proj'         => ['type' => 'avg', 'default_value' => 6.0],
        'games_played_proj'     => ['type' => 'sum', 'source_per_game' => null, 'default_value' => 0],
        'total_fanta_points_proj' => ['type' => 'sum', 'source_per_game' => null, 'default_value' => 0],
        'goals_scored_proj'     => ['type' => 'sum', 'source_per_game' => 'goals_scored', 'default_value' => 0],
        'assists_proj'          => ['type' => 'sum', 'source_per_game' => 'assists', 'default_value' => 0],
        'yellow_cards_proj'     => ['type' => 'sum', 'source_per_game' => 'yellow_cards', 'default_value' => 0],
        'red_cards_proj'        => ['type' => 'sum', 'source_per_game' => 'red_cards', 'default_value' => 0],
        'penalties_taken_proj'  => ['type' => 'sum', 'source_per_game' => 'penalties_taken', 'default_value' => 0],
        'penalties_scored_proj' => ['type' => 'sum', 'source_per_game' => 'penalties_scored', 'default_value' => 0],
        'penalties_missed_proj' => ['type' => 'sum', 'source_per_game' => 'penalties_missed', 'default_value' => 0],
        'own_goals_proj'        => ['type' => 'sum', 'source_per_game' => 'own_goals', 'default_value' => 0],
        'goals_conceded_proj'   => ['type' => 'sum', 'source_per_game' => 'goals_conceded', 'default_value' => 0],
        'penalties_saved_proj'  => ['type' => 'sum', 'source_per_game' => 'penalties_saved', 'default_value' => 0],
    ],
    
    // Fattori di Conversione Statistiche Lega
    'player_stats_league_conversion_factors' => [
        'Serie A' => [ // Da Serie A alla Serie A (fattore 1)
            'goals_scored' => 1.0,
            'assists' => 1.0,
            'avg_rating' => 1.0,
        ],
        'Serie B' => [ // Fattori per convertire stats da Serie B a livello atteso Serie A
            'goals_scored' => 0.60,
            'assists'      => 0.65,
            'avg_rating'   => 0.95,
        ],
        'default' => [ // Fattore di fallback per leghe non mappate
            'goals_scored' => 0.50,
            'assists'      => 0.50,
            'avg_rating'   => 0.90,
        ],
    ],
    
    // Regression Means per applyRegressionToMean
    'regression_means' => [
        'regression_factor' => 0.3,
        'means_by_role' => [
            'P' => ['avg_rating' => 6.20, 'fanta_mv_proj' => 5.5],
            'D' => ['avg_rating' => 6.05, 'fanta_mv_proj' => 6.0],
            'C' => ['avg_rating' => 6.10, 'fanta_mv_proj' => 6.5],
            'A' => ['avg_rating' => 6.15, 'fanta_mv_proj' => 7.0],
        ],
        'default_means' => ['avg_rating' => 6.10, 'fanta_mv_proj' => 6.2],
    ],
];