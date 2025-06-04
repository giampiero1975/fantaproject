Assolutamente! Creare un documento \"memo\" o una guida su come
implementare la proiezione basata su Quotazione (CRD) e Tier Squadra,
specialmente per i giocatori con storico limitato come quelli delle
neopromosse, è un\'ottima idea. Questo ti servirà come traccia quando
deciderai di implementare questa funzionalità in futuro.

Ecco una bozza di documento che puoi conservare:

**Memo Tecnico: Implementazione Proiezioni Giocatori Basate su
Quotazione e Tier Squadra**

Versione: 1.0

Data: 30 maggio 2025

Scopo: Descrivere l\'approccio e i passaggi per implementare un metodo
alternativo di proiezione delle performance dei giocatori, basato sulla
loro quotazione iniziale (CRD) e sul tier della squadra di appartenenza.
Questo metodo è particolarmente utile per giocatori con storico limitato
o assente in Serie A (es. neopromossi, nuovi acquisti dall\'estero).

**1. Motivazione e Obiettivi**

- **Problema:** Il sistema di proiezione attuale
  (ProjectionEngineService) si basa pesantemente sulle statistiche
  storiche dei giocatori (Media Voto, FantaMedia, gol, assist, etc.).
  Per i giocatori delle squadre neopromosse o per nuovi acquisti senza
  uno storico significativo in Serie A, i dati possono essere scarsi o
  non direttamente comparabili (es. statistiche da Serie B o campionati
  esteri). L\'attuale fallback a default_stats_per_role potrebbe essere
  troppo generico.

- **Obiettivo:** Introdurre un meccanismo di proiezione che utilizzi la
  **quotazione iniziale (CRD)** del giocatore (che riflette le
  aspettative del mercato/esperti) e il **tier della sua squadra** (che
  riflette la forza del contesto in cui giocherà) per generare una stima
  di base delle sue performance, specialmente per Media Voto (MV) e un
  potenziale \"contributo netto alla FantaMedia\" (bonus/malus).

**2. Dati di Input Necessari**

- **Da Tabella players:**

  - id (o fanta_platform_id): Identificativo giocatore.

  - name: Nome giocatore.

  - role: Ruolo Fantacalcio (P, D, C, A).

  - initial_quotation: Quotazione iniziale del giocatore per la stagione
    target (campo da assicurarsi esista e sia popolato).

  - team_id: ID della squadra di appartenenza.

  - date_of_birth: Per l\'aggiustamento per età.

- **Da Tabella teams:**

  - tier: Tier della squadra (calcolato dal TeamTieringService).

- **Da File di Configurazione (config/projection_settings.php):**

  - Nuove sezioni di mapping Quotazione -\> Statistiche Base e
    Modificatori Tier.

**3. Modifiche e Implementazioni Proposte**

**3.1. Aggiornamento config/projection_settings.php**

Aggiungere due nuove sezioni di configurazione:

- **quotation_to_base_stats_per_game**: Una mappa che associa range di
  quotazione, per ruolo, a statistiche base *per partita*.

  - **Struttura Esempio:**\
    PHP\
    \'quotation_to_base_stats_per_game\' =\> \[\
    \'P\' =\> \[ // Portieri\
    // Quotazione \[min, max\], MV base, GolSubiti/pg base, %
    RigParati/stagione, Amm/pg base, % CS base\
    \[\'range\' =\> \[20, 50\], \'mv\' =\> 6.25, \'gs_pg\' =\> 0.8,
    \'rp_season_perc\' =\> 0.15, \'yc_pg\' =\> 0.05, \'cs_base_prob\'
    =\> 0.40\],\
    \[\'range\' =\> \[10, 19\], \'mv\' =\> 6.10, \'gs_pg\' =\> 1.1,
    \'rp_season_perc\' =\> 0.10, \'yc_pg\' =\> 0.08, \'cs_base_prob\'
    =\> 0.30\],\
    \[\'range\' =\> \[1, 9\], \'mv\' =\> 6.00, \'gs_pg\' =\> 1.4,
    \'rp_season_perc\' =\> 0.05, \'yc_pg\' =\> 0.10, \'cs_base_prob\'
    =\> 0.20\],\
    \],\
    \'D\' =\> \[ // Difensori\
    // Quotazione, MV base, GolFatti/stagione base, Assist/stagione
    base, Amm/pg base, % CS base\
    \[\'range\' =\> \[15, 40\], \'mv\' =\> 6.10, \'gf_season_exp\' =\>
    1.5, \'as_season_exp\' =\> 1, \'yc_pg\' =\> 0.15, \'cs_base_prob\'
    =\> 0.35\],\
    \[\'range\' =\> \[5, 14\], \'mv\' =\> 6.00, \'gf_season_exp\' =\>
    0.5, \'as_season_exp\' =\> 0.5, \'yc_pg\' =\> 0.12, \'cs_base_prob\'
    =\> 0.25\],\
    \[\'range\' =\> \[1, 4\], \'mv\' =\> 5.90, \'gf_season_exp\' =\> 0,
    \'as_season_exp\' =\> 0, \'yc_pg\' =\> 0.10, \'cs_base_prob\' =\>
    0.15\],\
    \],\
    \'C\' =\> \[ /\* \... Mappatura per Centrocampisti \... \*/ \],\
    \'A\' =\> \[ /\* \... Mappatura per Attaccanti \... \*/ \],\
    \],\
    // Numero di presenze stimate su cui sono basate le gf_season_exp,
    as_season_exp\
    \'assumed_games_for_seasonal_quotation_stats\' =\> 30,

  - **Calibrazione:** Questa mappa è il cuore del nuovo metodo e
    richiede un\'attenta analisi dei dati storici di giocatori con
    quotazioni simili per stimare valori base sensati.

- **tier_stat_modifiers_for_quotation_projection**: Moltiplicatori da
  applicare alle statistiche base (derivate dalla mappa sopra) in base
  al tier della squadra.

  - **Struttura Esempio:**\
    PHP\
    \'tier_stat_modifiers_for_quotation_projection\' =\> \[\
    \'mv\' =\> \[1 =\> 1.05, 2 =\> 1.02, 3 =\> 1.00, 4 =\> 0.97, 5 =\>
    0.94\],\
    \'goals_scored_pg\' =\> \[1 =\> 1.20, 2 =\> 1.10, 3 =\> 1.00, 4 =\>
    0.85, 5 =\> 0.70\],\
    \'assists_pg\' =\> \[1 =\> 1.15, 2 =\> 1.07, 3 =\> 1.00, 4 =\> 0.90,
    5 =\> 0.80\],\
    \'goals_conceded_pg\' =\> \[1 =\> 0.80, 2 =\> 0.90, 3 =\> 1.00, 4
    =\> 1.15, 5 =\> 1.30\], // Per Portieri\
    \'clean_sheet_prob\' =\> \[1 =\> 1.20, 2 =\> 1.10, 3 =\> 1.00, 4 =\>
    0.85, 5 =\> 0.70\], // Moltiplicatore sulla cs_base_prob\
    \'yellow_cards_pg\' =\> \[1 =\> 0.90, 2 =\> 0.95, 3 =\> 1.00, 4 =\>
    1.05, 5 =\> 1.10\], // Giocatori in squadre scarse potrebbero
    prenderne di più\
    // \... altri modificatori per red_cards_pg, own_goals_pg,
    penalties_saved_pg etc.\
    \],

**3.2. Modifiche a app/Services/ProjectionEngineService.php**

1.  **Nuova Funzione Helper playerLacksKeyHistoricalStats(?array
    \$weightedStatsPerGame): bool**:

    - Determina se lo storico ponderato di un giocatore è insufficiente
      (es. avg_rating troppo basso o non presente).

> PHP\
> private function playerLacksKeyHistoricalStats(?array
> \$weightedStatsPerGame): bool\
> {\
> if (empty(\$weightedStatsPerGame)) {\
> return true;\
> }\
> // Considera lo storico \"chiave\" mancante se avg_rating non è
> significativo o assente\
> // Puoi aggiungere altre condizioni (es. poche partite giocate nello
> storico totale)\
> return !isset(\$weightedStatsPerGame\[\'avg_rating\'\]) \|\|
> \$weightedStatsPerGame\[\'avg_rating\'\] \< 5.0; // Esempio\
> }

2.  **Nuova Funzione Principale
    getProjectedStatsFromQuotationAndTier(Player \$player, ?int
    \$teamTier): ?array**:

    - Recupera la quotazione del giocatore e il suo ruolo.

    - Usa quotation_to_base_stats_per_game per trovare le statistiche
      base corrispondenti.

    - Applica i tier_stat_modifiers_for_quotation_projection a queste
      statistiche base.

    - Converte le stime stagionali (es. gf_season_exp) in stime per
      partita usando assumed_games_for_seasonal_quotation_stats.

    - Popola e restituisce un array di statistiche per partita (simile a
      quello restituito da getDefaultStatsPerGameForRole ma più
      specifico).

    - La stima della probabilità di Clean Sheet
      (clean_sheet_per_game_proj) per P/D potrebbe essere calcolata qui
      basandosi sulla cs_base_prob dalla mappa e sul modificatore tier,
      oppure continuare a usare la logica centralizzata di
      applyAdjustmentsAndEstimatePresences (ma quest\'ultima agisce
      sulle medie storiche). Per coerenza, è meglio calcolarla qui se si
      usa questo metodo.

3.  **Modifica a generatePlayerProjection()**:

    - All\'inizio, dopo aver calcolato \$weightedStatsPerGame, controlla
      se usare il nuovo metodo:\
      PHP\
      if (\$historicalStatsForAverages-\>isEmpty() \|\|
      \$this-\>playerLacksKeyHistoricalStats(\$weightedStatsPerGame)) {\
      Log::info(\"ProjectionEngineService: Storico mancante o incompleto
      per giocatore ID {\$player-\>fanta_platform_id}. Tentativo
      proiezione da quotazione e tier.\");\
      \
      \$currentTeamTier = \$player-\>team?-\>tier ??
      Config::get(\'projection_settings.default_team_tier\', 3);\
      \$quotationBasedStats =
      \$this-\>getProjectedStatsFromQuotationAndTier(\$player,
      \$currentTeamTier);\
      \
      if (\$quotationBasedStats) {\
      \$adjustedStatsPerGame = \$quotationBasedStats; // Queste sono già
      per partita e modulate per tier e età (se integrato)\
      // Le presenze devono ancora essere stimate, magari con un metodo
      dedicato che consideri la quotazione\
      \$age = \$player-\>date_of_birth ? \$player-\>date_of_birth-\>age
      : null;\
      // Potremmo passare \$player-\>initial_quotation a
      estimateDefaultPresences per affinarla\
      \$presenzeAttese =
      \$this-\>estimateDefaultPresences(\$player-\>role,
      \$currentTeamTier, \$age, \$player-\>initial_quotation);\
      \
      Log::debug(\"ProjectionEngineService: Statistiche PER PARTITA (da
      quotazione/tier) per {\$player-\>name}: \" .
      json_encode(\$adjustedStatsPerGame));\
      Log::debug(\"ProjectionEngineService: Presenze attese (da
      quotazione/tier) per {\$player-\>name}: \" . \$presenzeAttese);\
      \
      // Salta la logica di applyAdjustmentsAndEstimatePresences se si
      usano queste stats\
      // \... (procedi direttamente al calcolo FM con queste stats)
      \...\
      } else {\
      // Fallback al metodo di default originale se anche la proiezione
      da quotazione fallisce\
      Log::warning(\"ProjectionEngineService: Proiezione da
      quotazione/tier fallita per {\$player-\>name}. Fallback a default
      generico.\");\
      \$age = \$player-\>date_of_birth ? \$player-\>date_of_birth-\>age
      : null;\
      \$adjustedStatsPerGame =
      \$this-\>getDefaultStatsPerGameForRole(\$player-\>role,
      \$currentTeamTier, \$age);\
      \$presenzeAttese =
      \$this-\>estimateDefaultPresences(\$player-\>role,
      \$currentTeamTier, \$age);\
      }\
      // La variabile \$allHistoricalStatsForPenaltyAnalysis potrebbe
      non essere rilevante per questo path\
      // La logica dei rigori andrebbe adattata o si userebbe un default
      per questi giocatori\
      \$adjustedStatsPerGame\[\'penalties_taken\'\] =
      \$adjustedStatsPerGame\[\'penalties_taken\'\] ?? 0;\
      \$adjustedStatsPerGame\[\'penalties_scored\'\] =
      \$adjustedStatsPerGame\[\'penalties_scored\'\] ?? 0;\
      \$adjustedStatsPerGame\[\'penalties_missed\'\] =
      \$adjustedStatsPerGame\[\'penalties_missed\'\] ?? 0;\
      \
      \
      } else {\
      // \... logica esistente per quando lo storico c\'è e viene usato
      \...\
      \$adjustmentResult =
      \$this-\>applyAdjustmentsAndEstimatePresences(\$weightedStatsPerGame,
      \$player, \$leagueProfile,
      \$allHistoricalStatsForPenaltyAnalysis);\
      \$adjustedStatsPerGame =
      \$adjustmentResult\[\'adjusted_stats_per_game\'\];\
      \$presenzeAttese = \$adjustmentResult\[\'presenze_attese\'\];\
      }\
      \
      // Preparazione per FantasyPointCalculatorService\
      // \... (come prima, assicurandosi che \$adjustedStatsPerGame
      contenga tutte le chiavi necessarie) \...

4.  **Modifica a estimateDefaultPresences() (Opzionale):**

    - Potrebbe accettare \$initialQuotation come parametro opzionale. Se
      fornita, potrebbe modulare ulteriormente la stima delle presenze
      (giocatori con quotazione più alta potrebbero avere aspettative di
      presenze maggiori, anche se in squadre scarse).

**3.3. Logica Rigoristi per Giocatori Proiettati da Quotazione**

- La logica rigoristi attuale si basa sull\'analisi dello storico
  (\$allHistoricalStatsForPenaltyAnalysis). Per i giocatori senza
  storico, questa analisi non è possibile.

- **Soluzione:**

  - La mappa quotation_to_base_stats_per_game potrebbe includere una
    stima base di penalties_taken_per_season_exp o
    is_likely_penalty_taker_base_prob (probabilità base di essere
    rigorista) basata su ruolo e quotazione (es. un attaccante quotato
    25 è più probabile sia rigorista di uno quotato 5).

  - Il ProjectionEngineService, nel path della proiezione da quotazione,
    userebbe questa stima base, modulata dal tier squadra, per
    proiettare i rigori.

**4. Test e Calibrazione**

- Questo è il passo più lungo e importante.

- Popola config/projection_settings.php con le tue prime stime per le
  mappe.

- Usa il comando php artisan test:projection {playerId} su giocatori
  neopromossi o nuovi acquisti con quotazioni note.

- Analizza le proiezioni generate (MV, FM, gol, assist).

- Confrontale con le tue aspettative e con le performance reali di
  giocatori simili in passato.

- Affina iterativamente i valori nelle mappe di configurazione finché le
  proiezioni non ti sembrano ragionevoli e utili.

**Vantaggi di Questo Approccio:**

- Fornisce proiezioni più \"intelligenti\" e specifiche rispetto ai
  default generici per giocatori senza storico Serie A.

- Sfrutta l\'informazione della quotazione, che è un indicatore di
  aspettativa di mercato.

- Mantiene la modulazione per la forza della squadra (tier).

**Svantaggi:**

- Richiede un lavoro iniziale significativo di analisi e calibrazione
  per definire le mappe in config/projection_settings.php.

- Le mappe potrebbero necessitare di aggiornamenti stagionali se le
  dinamiche delle quotazioni o delle performance cambiano.

Questo approccio rappresenta un significativo miglioramento per gestire
i casi più difficili nelle proiezioni.

Conserva questo memo. Quando sarai pronto a implementarlo, avrai una
traccia chiara dei passaggi e delle considerazioni.
