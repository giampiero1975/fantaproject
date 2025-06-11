<?php

return [
    // Quante stagioni storiche considerare per calcolare la forza di una squadra
    // Deve corrispondere al numero di pesi definiti in 'season_weights'
    'lookback_seasons_for_tiering' => 4,
    
    // Pesi da assegnare alle stagioni storiche (0: la più recente (t-1), 1: t-2, etc.)
    // La somma dei pesi dovrebbe idealmente essere 1, ma il servizio normalizzerà se non lo è.
    // conservativo -> [0.30, 0.25, 0.25, 0.20]
    // neutrale -> [0.25, 0.25, 0.25, 0.25]
    // Premia la Forma Recente -> [0 => 0.4, 1 => 0.3, 2 => 0.2, 3 => 0.1]
    'season_weights' => [0.30, 0.25, 0.25, 0.20],

    // Pesi per le diverse metriche usate per calcolare il "punteggio forza" di una squadra in una singola stagione
    // La somma dei pesi dovrebbe idealmente essere 1.
    'metric_weights' => [
        'points' => 0.5,            // Punti in classifica
        'goal_difference' => 0.25,   // Differenza reti
        'goals_for' => 0.15,         // Gol fatti
        'position' => 0.1,          // Posizione (verrà invertita nel calcolo, 1° è meglio)
    ],
    
    // Metodo per normalizzare i punteggi di forza grezzi prima di applicare le soglie
    // Opzioni: 'min_max' (scala 0-100), 'none' (usa punteggi grezzi, richiede soglie adatte)
    'normalization_method' => 'min_max',
    
    // Sorgente per le soglie dei tier:
    // 'config': usa le soglie definite in 'tier_thresholds_config'
    // 'dynamic_percentiles': calcola soglie basate sui percentili dei punteggi di tutte le squadre (vedi 'tier_percentiles_config')
    // 'tier_thresholds_source' => 'config',
    
    // Soglie per i tier se tier_thresholds_source è 'config'
    // Basate sul punteggio normalizzato (0-100), dove 100 è il migliore.
    // La chiave è il Tier, il valore è il punteggio MINIMO normalizzato per rientrare in quel tier.
    // Assicurati che siano in ordine decrescente di punteggio.
    'tier_thresholds_source' => 'dynamic_percentiles',
    'tier_thresholds_config' => [
        1 => 73,  // Per le prime 3 (Inter, Napoli, Atalanta)
        2 => 58,  // Per le successive 3 (Milan, Juventus, Roma)
        3 => 47,  // Per le successive 4 (Lazio, Fiorentina, Bologna, e la prossima più alta)
        4 => 18,  // Per le successive 4 (es. Como, Torino, e altre due neopromosse)
        5 => 0,   // Le rimanenti 6
    ],
    
    // Configurazione dei percentili se tier_thresholds_source è 'dynamic_percentiles'
    // La chiave è il Tier, il valore è il percentile INFERIORE del punteggio grezzo per quel tier.
    // Es. Tier 1: top X% (es. 80° percentile in su), Tier 2: successivo Y% (es. tra 60° e 80° percentile)
    // 'tier_thresholds_source' => 'config',
    'tier_percentiles_config' => [
        1 => 0.90, 
        2 => 0.70,
        3 => 0.50,
        4 => 0.30,
        5 => 0.0,
    ],
    
    
    // Tier di default per le squadre neopromosse o per cui non si trovano dati storici sufficienti
    'newly_promoted_tier_default' => 5,
    // Punteggio grezzo da assegnare a neopromosse per farle ricadere nel tier di default
    // Deve essere calibrato in base alle tue soglie e alla normalizzazione
    'newly_promoted_raw_score_target' => 15.0, // Es. se la soglia per Tier 4 è 30
    
    // Config per API (usata da TeamDataService ma centralizzata qui per il tiering)
    'api_football_data' => [
        'serie_a_competition_id' => 'SA',
        'serie_b_competition_id' => 'SB', // Aggiungi se vuoi gestire la Serie B
        'standings_endpoint' => 'competitions/{competitionId}/standings?season={year}',
    ],
    
    'league_strength_multipliers' => [
        'Serie A' => 1.0,
        'Serie B' => 0.45, // Esempio: una stagione in B vale il 70% di una in A a parità di metriche
        // Aggiungi altre leghe se necessario
    ],
];