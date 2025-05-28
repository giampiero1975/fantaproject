<?php

return [
    // Chiavi esistenti:
    'penalty_taker_lookback_seasons' => 2,       // Usato specificamente per identificare un rigorista
    'min_penalties_taken_threshold' => 3,        // Minimo rigori calciati per essere considerato rigorista con tasso affidabile
    'league_avg_penalties_awarded' => 0.20,      // Rigori medi per squadra a partita (rinominato da 'league_average_penalties_per_game' per coerenza)
    'penalty_taker_share' => 0.85,               // Quota rigori del rigorista designato (rinominato da 'default_penalty_taker_share')
    'default_penalty_conversion_rate' => 0.75,
    
    // --- CHIAVI MANCANTI DA AGGIUNGERE ---
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
        // 'avg_games_played' // Questo viene calcolato a parte
    ],
    
    'offensive_stats_fields' => ['goals_scored', 'assists'], // Campi influenzati dal tier offensivo
    'defensive_stats_fields_goalkeeper' => ['goals_conceded'], // Campi influenzati dal tier difensivo (solo per portieri)
    
    'min_games_for_reliable_avg_rating' => 10,         // Minimo partite per considerare affidabile un avg_rating stagionale
    'default_team_tier' => 3,                          // Tier di default per squadre senza tier specificato
    
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
    
    'league_average_clean_sheet_rate_per_game' => 0.28, // Tasso medio di clean sheet per partita nella lega
    
    'default_player_age' => 25,                        // Età di default se non disponibile
    'default_games_played_for_penalty_check' => 25,    // Usato se avg_games_played non è disponibile per isLikelyPenaltyTaker
    
    // Usato per determinare se il tasso di conversione storico di un rigorista è affidabile
    'min_penalties_taken_for_reliable_conversion_rate' => 5,
    // Specifica per quanti anni guardare indietro per lo status di rigorista (diverso da lookback_seasons per le medie)
    'lookback_seasons_penalty_taker_check' => 3,
    
    // Configurazione per i campi di output delle proiezioni (usato in generateBaseProjections)
    'fields_to_project_output' => [
        'avg_rating_proj'       => ['type' => 'avg', 'default_value' => 6.0],
        'fanta_mv_proj'         => ['type' => 'avg', 'default_value' => 6.0],
        'games_played_proj'     => ['type' => 'sum', 'source_per_game' => null, 'default_value' => 0], // Gestito a parte
        'total_fanta_points_proj' => ['type' => 'sum', 'source_per_game' => null, 'default_value' => 0], // Gestito a parte
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
    'fallback_mv_if_no_history' => 5.5,
    'fallback_fm_if_no_history' => 5.5,
    'fallback_gp_if_no_history' => 0,
    
];