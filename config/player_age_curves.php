<?php // config/player_age_curves.php

return [
    'descrizione' => 'Fasi della curva di rendimento approssimativa dei calciatori per ruolo ed età.',
    'disclaimer' => 'Queste sono generalizzazioni e le carriere individuali possono variare significativamente in base a numerosi fattori.',
    'dati_ruoli' => [
        // Mappiamo i tuoi ruoli ai ruoli 'P', 'D', 'C', 'A' usati nel modello Player
        'P' => [ // Corrisponde a "Portieri"
            'fasi_carriera' => [
                // Convertiamo le stringhe in valori numerici per l'inizio del picco e del declino
                // "sviluppo": "Fino a ~27 anni" -> peak_start: 28
                // "picco": "28 - 33 anni"      -> peak_start: 28, peak_end: 33
                // "declino_inizio_da": "Dai 38+ anni" -> decline_start: 38
                'sviluppo_fino_a' => 27,
                'picco_inizio' => 28,
                'picco_fine' => 33,
                'mantenimento_fino_a' => 37, // Mantenimento/Declino Graduale
                'declino_da' => 38,
            ],
            'note_picco_declino' => 'Maturazione tardiva; esperienza e posizionamento cruciali; possono mantenere prestazioni elevate più a lungo.',
            // Aggiungiamo i fattori di crescita/declino e i cap che avevamo prima,
            // potrai calibrarli basandoti sulle tue nuove fasi.
            'growth_factor' => 0.010, 'decline_factor' => 0.015, 'young_cap' => 1.10, 'old_cap' => 0.80
        ],
        'D_CENTRALE' => [ // Per "Difensori Centrali"
            'fasi_carriera' => [
                'sviluppo_fino_a' => 26,
                'picco_inizio' => 27,
                'picco_fine' => 31,
                'mantenimento_fino_a' => 35,
                'declino_da' => 36,
            ],
            'note_picco_declino' => 'Esperienza, senso della posizione e leadership si affinano, compensando il calo fisico iniziale.',
            'growth_factor' => 0.015, 'decline_factor' => 0.025, 'young_cap' => 1.12, 'old_cap' => 0.78 // Valori leggermente diversi
        ],
        'D_ESTERNO' => [ // Per "Terzini e Ali (Esterni)"
            'fasi_carriera' => [
                'sviluppo_fino_a' => 23,
                'picco_inizio' => 24,
                'picco_fine' => 28,
                'mantenimento_fino_a' => 31,
                'declino_da' => 32,
            ],
            'note_picco_declino' => 'Ruoli atletici; il picco è spesso legato alla massima condizione fisica; declino più rapido per chi si basa sulla velocità.',
            'growth_factor' => 0.020, 'decline_factor' => 0.030, 'young_cap' => 1.18, 'old_cap' => 0.70
        ],
        'C' => [ // Per "Centrocampisti"
            'fasi_carriera' => [
                'sviluppo_fino_a' => 24,
                'picco_inizio' => 25,
                'picco_fine' => 30,
                'mantenimento_fino_a' => 34,
                'declino_da' => 35,
            ],
            'note_picco_declino' => 'Varia molto: i tecnici/registi possono avere longevità maggiore grazie a visione di gioco e intelligenza tattica.',
            'growth_factor' => 0.020, 'decline_factor' => 0.030, 'young_cap' => 1.20, 'old_cap' => 0.70
        ],
        'A' => [ // Per "Attaccanti"
            'fasi_carriera' => [
                'sviluppo_fino_a' => 24, // Il tuo JSON dice 23 per Terzini/Ali, qui ho usato 24
                'picco_inizio' => 25,    // Il tuo JSON dice 24 per Terzini/Ali, qui ho usato 25
                'picco_fine' => 30,
                'mantenimento_fino_a' => 33,
                'declino_da' => 34,
            ],
            'note_picco_declino' => 'Combinazione di maturità fisica e tecnica; il declino può essere più rapido per attaccanti esplosivi/veloci.',
            'growth_factor' => 0.025, 'decline_factor' => 0.035, 'young_cap' => 1.25, 'old_cap' => 0.65
        ],
    ],
    
    // Parametri generali per l'applicazione dell'ageModifier
    'age_modifier_params' => [
        'mv_effect_ratio' => 0.4,       // Quanto del modificatore età influenza la MV (0.4 = 40%)
        'cs_age_effect_ratio' => 0.2,   // Quanto del modificatore età influenza la prob. Clean Sheet
        'presenze_growth_effect_ratio' => 0.4, // Quanto del modificatore crescita influenza le presenze
        'presenze_decline_effect_ratio' => 1.1, // Quanto del modificatore declino influenza le presenze (amplificato)
        'presenze_growth_cap' => 1.12,  // Max +X% presenze per crescita età
        'presenze_decline_cap' => 0.65, // Min X% presenze per declino età
    ]
];