Okay, capisco. Vuoi un documento che descriva la **metodologia** per
confrontare i giocatori e calibrare il sistema, utilizzando i dati di
FBRef come fonte esterna di \"verità\" o benchmark, e i dati del
Fantacalcio (quotazioni, MV/FM reali quando disponibili) come ulteriore
riferimento.

Questo documento non sarà l\'aggiornamento del \"Fanta-Project Tecnico
v1.3\" generale, ma un **documento specifico su questa metodologia di
analisi e calibrazione per i giocatori, con un focus sulle
neopromosse.**

**Documento Metodologico: Analisi Comparativa e Calibrazione Proiezioni
Giocatori (con focus su Neopromosse) Utilizzando Dati FBRef e
Fantacalcio**

Versione: 1.0

Data: 1 giugno 2025

**1. Obiettivo**

Questo documento descrive la metodologia per:

1.  Utilizzare i dati statistici dettagliati disponibili su FBRef.com
    per analizzare le performance dei giocatori, in particolare quelli
    che transitano dalla Serie B alla Serie A (neopromossi) e quelli già
    militanti in Serie A con diversi livelli di performance e
    appartenenza a squadre di tier differenti.

2.  Confrontare queste performance \"reali\" (o \"attese\" secondo
    metriche avanzate come xG, xAG) con le proiezioni generate dal
    sistema FantaProject.

3.  Utilizzare i dati di Fantacalcio (come Quotazioni di Riferimento -
    CRd, e Medie Voto/FantaMedie reali a fine stagione) come ulteriore
    benchmark di mercato e di risultato effettivo.

4.  Guidare la calibrazione dei parametri chiave del
    ProjectionEngineService e del TeamTieringService per migliorare
    l\'accuratezza delle proiezioni, specialmente per i giocatori delle
    squadre neopromosse.

**2. Fonti Dati per l\'Analisi**

- **Dati Interni al Sistema FantaProject:**

  - players: Anagrafica giocatori, include fanta_platform_id, role,
    team_id, CRd (quotazione iniziale).

  - teams: Informazioni sulle squadre, include name, short_name, e il
    tier calcolato dinamicamente.

  - historical_player_stats: Statistiche storiche dei giocatori
    importate (sia da file standard XLSX sia da file arricchiti CSV/XLSX
    che includono league_name e dati potenzialmente presi da FBRef).

  - team_historical_standings: Classifiche storiche delle squadre (Serie
    A e Serie B).

  - **Output del ProjectionEngineService**: Proiezioni individuali per
    giocatore (mv_proj_per_game, fanta_media_proj_per_game,
    goals_scored_proj, assists_proj, presenze_proj, etc.).

- **Dati Esterni (Riferimento e Benchmark):**

  - **FBRef.com (consultazione manuale):**

    - Statistiche stagionali dettagliate per giocatore e squadra (PG,
      Min, Reti, Assist, xG, npxG, xAG, PrgC, PrgP, etc.), sia totali
      che \"Per 90 minuti\".

    - Dati disponibili per Serie A, Serie B, e altri campionati.

    - *Utilizzo:* Per popolare i file CSV/XLSX di importazione dello
      storico giocatori (specialmente per la Serie B e per arricchire i
      dati) e per l\'analisi comparativa qualitativa.

  - **Dati Ufficiali Fantacalcio (es. Fantacalcio.it, Leghe FC):**

    - Quotazioni di Riferimento (CRd) all\'inizio della stagione.

    - Medie Voto (MV) e FantaMedie (FM) reali a fine stagione.

    - *Utilizzo:* Come benchmark di mercato (CRd) e di performance
      effettiva nel gioco (MV/FM reali).

**3. Metodologia di Analisi e Confronto**

Il processo è iterativo e si concentra sulla calibrazione dei seguenti
parametri di configurazione:

- config/projection_settings.php:

  - player_stats_league_conversion_factors: Fattori per \"tradurre\" le
    stats giocatore da Serie B (o altra lega) a Serie A.

  - team_tier_multipliers_offensive/defensive/presence: Impatto del tier
    squadra sulle stats e presenze.

  - default_stats_per_role e default_presences_map: Valori base per
    giocatori senza storico.

  - Parametri della logica rigoristi.

- config/player_age_curves.php: Impatto dell\'età.

- config/team_tiering_settings.php: Parametri che influenzano il calcolo
  del tier squadra (pesi metriche, pesi stagionali, soglie tier,
  league_strength_multipliers per le squadre).

**Passi Operativi:**

1.  **Preparazione dei Dati di Input per il Sistema FantaProject:**

    - **Storico Squadre (team_historical_standings):** Assicurarsi che
      sia popolato per diverse stagioni di Serie A e Serie B (via API
      con teams:fetch-historical-standings o via CSV con
      teams:import-standings-file). I dati per i CSV possono essere
      presi da FBRef.

    - **Storico Giocatori (historical_player_stats):**

      - Utilizzare il comando players:import-advanced-stats con un file
        CSV/XLSX preparato ad hoc.

      - Questo file deve contenere per i giocatori selezionati
        (specialmente neopromossi e benchmark):

        - PlayerFantaPlatformID (corretto e esistente nella tabella
          players).

        - NomeLega (es. \"Serie B\" per le stagioni precedenti delle
          neopromosse, \"Serie A\" per i benchmark).

        - Statistiche base (PG, Minuti, Reti, Assist, Cartellini, Rigori
          T/S).

        - MediaVotoOriginale: **Cruciale per i giocatori di Serie B.**
          Poiché FBRef non fornisce la MV fantacalcistica, l\'utente
          deve stimare una MV per la stagione in Serie B basandosi sulla
          sua analisi qualitativa delle performance del giocatore su
          FBRef (confrontando xG, xAG, etc. con giocatori di Serie A di
          cui conosce la MV). *Questo è un input soggettivo ma
          informato*.

      - *Esempio:* Per un giocatore del Pisa (neopromossa per la
        stagione di proiezione 2025-26), si importeranno le sue
        statistiche della stagione 2024-25 in Serie B, con
        NomeLega=\"Serie B\" e una MediaVotoOriginale stimata.

2.  **Esecuzione dei Processi di Sistema FantaProject:**

    - php artisan teams:set-active-league
      \--target-season-start-year=YYYY \--league-code=SA \...: Per
      definire le 20 squadre della Serie A per la stagione di
      proiezione.

    - php artisan teams:map-api-ids \--competition=SA: Per mappare gli
      ID API.

    - php artisan teams:update-tiers YYYY-YY: Per calcolare i tier delle
      squadre attive.

    - php artisan test:projection {playerId}: Per generare le proiezioni
      per i giocatori selezionati (neopromossi e benchmark Serie A).

3.  **Analisi Comparativa degli Output:**

    - **Per ogni giocatore neopromosso testato:**

      - **Log del ProjectionEngineService:** Verificare che:

        - Lo storico corretto (con league_name=\"Serie B\") sia stato
          letto.

        - I player_stats_league_conversion_factors (per gol, assist, MV)
          siano stati applicati alle sue statistiche di Serie B.

        - La MediaVotoOriginale (stimata dall\'utente per la Serie B)
          sia stata usata come base per la avg_rating prima della
          conversione.

      - **Output JSON della Proiezione:**

        - mv_proj_per_game: Dovrebbe essere il risultato della MV
          stimata per la B, convertita per la Serie A, e poi modulata da
          età e tier squadra.

        - goals_scored_proj / assists_proj: Dovrebbero riflettere i
          gol/assist di Serie B, convertiti per la Serie A, e modulati
          da età e tier squadra.

        - fanta_media_proj_per_game.

    - **Confronto con Giocatori Benchmark di Serie A (stesso ruolo/età
      simile):**

      - Il giocatore neopromosso, che milita in una squadra con tier
        probabilmente più basso (es. Tier 4 o 5), dovrebbe avere
        proiezioni (MV, FM, gol, assist) generalmente inferiori a un
        giocatore simile in una squadra di Serie A di Tier 3, ma
        potenzialmente paragonabili (o leggermente inferiori/superiori a
        seconda del talento individuale) a un giocatore di un\'altra
        squadra di Serie A di Tier 4 o 5.

      - **Utilizzo Dati FBRef per il Confronto Qualitativo:**

        - L\'utente consulta i dati FBRef \"Per 90 minuti\" (npxG/90,
          xAG/90, PrgP/90, etc.) sia per il giocatore neopromosso (nella
          sua stagione in B) sia per i benchmark di Serie A.

        - Se il modello FantaProject proietta l\'attaccante neopromosso
          a 0.25 gol/partita in A, e questo valore è (ad esempio) il 50%
          dei suoi gol/partita in B, l\'utente può confrontare questo
          \"tasso di traduzione\" con come altri giocatori simili
          (analizzati su FBRef) si sono comportati nel passaggio B -\>
          A.

        - Se un difensore neopromosso con eccellenti stats di
          progressione palla in B (da FBRef) ottiene una MV proiettata
          molto bassa, si potrebbe riconsiderare il fattore di
          conversione della MV o l\'impatto del tier sulla MV.

    - **Confronto con Quotazioni di Riferimento (CRd) Fantacalcio:**

      - Quando disponibili, le CRd forniscono un benchmark di mercato.

      - Se le proiezioni FantaMedia del sistema per i giocatori
        neopromossi sono sistematicamente molto più basse (o alte) di
        quanto le loro CRd suggerirebbero (relativamente ad altri
        giocatori), è un segnale per ricalibrare i parametri (fattori di
        conversione, moltiplicatori di tier, o i default_stats_per_role
        se il giocatore non aveva storico).

4.  **Ciclo di Calibrazione:**

    - Se l\'analisi comparativa rivela discrepanze significative o
      proiezioni irrealistiche:

      - Modificare i parametri in config/projection_settings.php
        (principalmente player_stats_league_conversion_factors,
        team_tier_multipliers\_\* per i tier bassi,
        default_stats_per_role).

      - Rieseguire php artisan teams:update-tiers (se si modificano
        parametri che influenzano il tiering stesso, come i
        league_strength_multipliers per le squadre).

      - Rieseguire php artisan test:projection per i giocatori campione.

      - Ripetere l\'analisi.

**Esempio Pratico di Analisi (Ipotetico):**

- **Giocatore Neopromosso (GN):** Attaccante, 20 gol in Serie B (0.53
  gol/partita), MV stimata per la B: 6.3. Squadra Neopromossa: Tier 4.

- **Benchmark Serie A (BSA):** Attaccante simile per età/ruolo, in
  squadra di Tier 4, con storico di 0.3 gol/partita e MV 6.0 in Serie A.

- **Parametri Iniziali:**
  player_stats_league_conversion_factors\[\'Serie
  B\'\]\[\'goals_scored\'\] = 0.5, \[\'avg_rating\'\] = 0.95.
  team_tier_multipliers_offensive\[4\] = 0.9.

- **Proiezione Sistema per GN:**

  - Gol base tradotti: 0.53 \* 0.5 = 0.265 gol/partita.

  - Gol modulati per tier: 0.265 \* 0.9 = 0.2385 gol/partita.

  - MV base tradotta: 6.3 \* 0.95 = 5.985.

  - MV modulata per tier (supponiamo un piccolo impatto): \~5.95.

- **Confronto:**

  - La proiezione gol (0.2385) è inferiore a quella del BSA (0.3). È
    troppo bassa? Forse il fattore 0.5 è troppo penalizzante, o il
    moltiplicatore 0.9 del tier è troppo forte.

  - La MV proiettata (5.95) è simile a quella del BSA (6.0). Sembra
    ragionevole.

- **Azione:** Potrei provare ad aumentare leggermente il fattore di
  conversione gol per la Serie B a 0.55 o 0.6 e vedere l\'impatto.

5\. Conclusione dell\'Analisi

L\'analisi si conclude quando le proiezioni per i giocatori campione
(neopromossi e benchmark) appaiono ragionevoli, coerenti tra loro e con
le aspettative esterne (CRd, conoscenza calcistica informata da FBRef).
Il sistema non sarà mai perfetto, ma l\'obiettivo è una calibrazione che
minimizzi distorsioni evidenti.

Questo documento metodologico dovrebbe guidare il processo di utilizzo
dei dati FBRef per informare e calibrare il sistema di proiezione
FantaProject.
