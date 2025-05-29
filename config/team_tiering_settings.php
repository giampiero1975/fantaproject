<?php

return [
    // Quante stagioni storiche considerare per calcolare la forza di una squadra
    // Deve corrispondere al numero di pesi definiti in 'season_weights'
    'lookback_seasons_for_tiering' => 4,
    
    // Pesi da assegnare alle stagioni storiche (0: la più recente (t-1), 1: t-2, etc.)
    // La somma dei pesi dovrebbe idealmente essere 1, ma il servizio normalizzerà se non lo è.
    'season_weights' => [0 => 0.4, 1 => 0.3, 2 => 0.2, 3 => 0.1],

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
    'tier_thresholds_source' => 'config',
    
    // Soglie per i tier se tier_thresholds_source è 'config'
    // Basate sul punteggio normalizzato (0-100), dove 100 è il migliore.
    // La chiave è il Tier, il valore è il punteggio MINIMO normalizzato per rientrare in quel tier.
    // Assicurati che siano in ordine decrescente di punteggio.
    'tier_thresholds_config' => [
        1 => 85, // Punteggio >= 85  => Tier 1
        2 => 70, // Punteggio >= 70  => Tier 2
        3 => 50, // Punteggio >= 50  => Tier 3
        4 => 30, // Punteggio >= 30  => Tier 4
        5 => 0,  // Punteggio >= 0   => Tier 5 (tutte le altre)
    ],
    
    // Configurazione dei percentili se tier_thresholds_source è 'dynamic_percentiles'
    // La chiave è il Tier, il valore è il percentile INFERIORE del punteggio grezzo per quel tier.
    // Es. Tier 1: top X% (es. 80° percentile in su), Tier 2: successivo Y% (es. tra 60° e 80° percentile)
    'tier_percentiles_config' => [
        1 => 0.80, // Le squadre sopra l'80° percentile dei punteggi sono Tier 1
        2 => 0.60, // Tra il 60° e l'80° percentile sono Tier 2
        3 => 0.40, // Tra il 40° e il 60° percentile sono Tier 3
        4 => 0.20, // Tra il 20° e il 40° percentile sono Tier 4
        5 => 0.0,  // Sotto il 20° percentile sono Tier 5
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
        'Serie B' => 0.75, // Esempio: una stagione in B vale il 70% di una in A a parità di metriche
        // Aggiungi altre leghe se necessario
    ],
];