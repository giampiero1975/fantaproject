Hai perfettamente ragione! Gli esempi pratici rendono la documentazione
molto più chiara e utile.

Integriamo subito degli esempi pratici nella documentazione dei file di
configurazione.

## **Documentazione Aggiornata (con Esempi Pratici):**

### **Documentazione: config/player_age_curves.php**

**File:** config/player_age_curves.php

1.  Scopo Generale\
    Questo file di configurazione definisce come l\'età di un giocatore
    influenza le proiezioni delle sue performance. Specifica le diverse
    fasi della carriera di un calciatore (sviluppo, picco, mantenimento,
    declino) per differenti ruoli o tipologie di ruolo e i modificatori
    quantitativi da applicare alle statistiche di base.

2.  Utilizzo Principale\
    Il file player_age_curves.php è utilizzato principalmente dal
    ProjectionEngineService per:

    - Calcolare un ageModifier generale per un giocatore in base alla
      sua età e al suo ruolo specifico per la curva di età.

    - Applicare questo modificatore (o sue variazioni) a diverse
      statistiche proiettate, come la Media Voto (MV), i gol, gli
      assist, la probabilità di clean sheet e le presenze attese.

    - L\'obiettivo è rendere le proiezioni più realistiche, tenendo
      conto che le prestazioni dei giocatori tendono a seguire una curva
      durante la loro carriera.

3.  **Struttura e Configurazione Dettagliata**\
    Il file restituisce un array associativo con le seguenti chiavi
    principali:

    - descrizione: Una breve descrizione testuale dello scopo del file.

    - disclaimer: Un avviso che sottolinea come le curve siano
      generalizzazioni.

    - dati_ruoli: Questa è la sezione chiave. È un array associativo
      dove ogni chiave rappresenta un raggruppamento di ruolo specifico
      per la curva di età (es. \'P\' per portieri, \'D_CENTRALE\',
      \'D_ESTERNO\', \'C\' per centrocampisti, \'A\' per attaccanti).\
      Ogni ruolo ha la seguente struttura:

      - fasi_carriera: Un array che definisce le età di transizione tra
        le fasi:

        - sviluppo_fino_a: Età fino alla quale il giocatore è
          considerato in fase di sviluppo.

        - picco_inizio: Età in cui inizia la fase di picco.

        - picco_fine: Età in cui termina la fase di picco.

        - mantenimento_fino_a: Età fino alla quale il giocatore potrebbe
          mantenere un buon livello prima di un declino più marcato
          (opzionale, la logica attuale si basa principalmente su picco
          e declino).

        - declino_da: Età da cui inizia la fase di declino più evidente.

      - note_picco_declino: Note testuali descrittive sulla tipica
        evoluzione del ruolo.

      - growth_factor: Fattore numerico (es. 0.015) che determina
        l\'incremento percentuale annuo delle prestazioni durante la
        fase di sviluppo, per ogni anno prima del picco_inizio.

      - decline_factor: Fattore numerico (es. 0.025) che determina il
        decremento percentuale annuo delle prestazioni durante la fase
        di declino, per ogni anno dopo il picco_fine.

      - young_cap: Limite massimo del modificatore età per i giocatori
        giovani (es. 1.12 significa che un giovane non può avere un
        moltiplicatore superiore al +12% rispetto al suo picco teorico
        dovuto solo all\'età).

      - old_cap: Limite minimo del modificatore età per i giocatori
        anziani (es. 0.78 significa che un giocatore anziano non può
        avere un moltiplicatore inferiore al -22% rispetto al suo picco
        teorico dovuto solo all\'età).

    - age_modifier_params: Un array che definisce come l\' ageModifier
      generale (calcolato dalle fasi di carriera) viene specificamente
      applicato a diverse categorie di statistiche o aspetti della
      proiezione.

      - mv_effect_ratio: (es. 0.4) Specifica quale frazione
        dell\'effetto dell\'età (differenza da 1.0 del ageModifier) si
        applica alla Media Voto.

      - cs_age_effect_ratio: (es. 0.2) Simile a mv_effect_ratio, ma per
        l\'impatto sulla probabilità di Clean Sheet.

      - presenze_growth_effect_ratio: (es. 0.4) Specifica quale frazione
        dell\'effetto *positivo* dell\'età (giocatore giovane in
        crescita) si applica alle presenze attese.

      - presenze_decline_effect_ratio: (es. 1.1) Specifica quale
        frazione (o amplificazione, se \>1) dell\'effetto *negativo*
        dell\'età (giocatore anziano in declino) si applica alle
        presenze attese.

      - presenze_growth_cap: (es. 1.12) Limite massimo all\'incremento
        delle presenze dovuto al fattore età per giocatori giovani.

      - presenze_decline_cap: (es. 0.65) Limite minimo alla riduzione
        delle presenze dovuto al fattore età per giocatori anziani.

> **Come Configurare/Modificare (con Esempi):**

- **Ruoli e Fasi Carriera:**

  - **Esempio:** Supponiamo di voler definire che gli attaccanti (A)
    abbiano un picco più breve ma intenso.\
    PHP\
    \'A\' =\> \[\
    \'fasi_carriera\' =\> \[\
    \'sviluppo_fino_a\' =\> 22, // Sviluppo più breve\
    \'picco_inizio\' =\> 23, // Picco inizia prima\
    \'picco_fine\' =\> 28, // Picco finisce prima\
    \'declino_da\' =\> 29, // Declino inizia prima\
    \],\
    \'growth_factor\' =\> 0.030, // Crescita più rapida\
    \'decline_factor\' =\> 0.040, // Declino più rapido\
    \'young_cap\' =\> 1.25,\
    \'old_cap\' =\> 0.60,\
    // \...\
    \],

- **Fattori e Cap:**

  - **Esempio:** Per i portieri (P), se si ritiene che l\'esperienza
    compensi maggiormente il calo fisico, si potrebbe ridurre il
    decline_factor e aumentare l\'old_cap.\
    PHP\
    \'P\' =\> \[\
    // \... fasi_carriera \...\
    \'decline_factor\' =\> 0.010, // Declino più lento\
    \'old_cap\' =\> 0.85, // Mantengono un livello più alto più a lungo\
    // \...\
    \],

- **Parametri di Applicazione (age_modifier_params):**

  - **Esempio:** Se vuoi che l\'età influenzi di più la Media Voto (MV)
    ma meno le presenze per i giocatori in crescita:\
    PHP\
    \'age_modifier_params\' =\> \[\
    \'mv_effect_ratio\' =\> 0.6, // 60% dell\'effetto età sulla MV\
    \'presenze_growth_effect_ratio\' =\> 0.3, // 30% dell\'effetto
    crescita età sulle presenze\
    // \... altri parametri \...\
    \]

  - Un ageModifier di 1.10 (giocatore giovane in crescita) con
    mv_effect_ratio: 0.6 porterebbe a un moltiplicatore MV di 1 + (0.10
    \* 0.6) = 1.06 (+6%).

  - Con presenze_growth_effect_ratio: 0.3, lo stesso ageModifier
    porterebbe a un moltiplicatore presenze di 1 + (0.10 \* 0.3) = 1.03
    (+3%), limitato poi da presenze_growth_cap.

### **Documentazione: config/projection_settings.php**

**File:** config/projection_settings.php

1.  Scopo Generale\
    Questo file centrale contiene una vasta gamma di parametri che
    governano il funzionamento del ProjectionEngineService. Definisce
    come vengono recuperati e pesati i dati storici, come vengono
    applicati gli aggiustamenti (diversi da quelli puramente legati
    all\'età), come viene gestita la logica dei rigoristi, i valori di
    default e fallback, e la configurazione dei campi di output delle
    proiezioni.

2.  Utilizzo Principale\
    Il file projection_settings.php è il cuore della configurazione del
    ProjectionEngineService. Ogni aspetto del calcolo delle proiezioni,
    dalla selezione dei dati storici alla definizione delle statistiche
    finali, fa riferimento a parametri definiti in questo file. Permette
    una calibrazione fine del modello predittivo senza modificare
    direttamente il codice del servizio.

3.  **Struttura e Configurazione Dettagliata (con Esempi dove
    rilevante)**\
    Il file restituisce un array associativo con numerose chiavi:

    - **Parametri Rigoristi:**

      - penalty_taker_lookback_seasons: (es. 2) Numero di stagioni
        passate da analizzare per identificare un rigorista.

      - min_penalties_taken_threshold: (es. 3) Numero minimo di rigori
        calciati nel periodo di lookback perché un giocatore sia
        considerato un potenziale rigorista primario.

      - league_avg_penalties_awarded: (es. 0.20) Media di rigori
        assegnati per squadra a partita nel campionato.

      - penalty_taker_share: (es. 0.85) Quota percentuale dei rigori di
        squadra che si stima il rigorista designato calci.

      - default_penalty_conversion_rate: (es. 0.75) Tasso di conversione
        dei rigori usato come fallback.

      - min_penalties_taken_for_reliable_conversion_rate: (es. 5) Numero
        minimo di rigori calciati storicamente dal giocatore perché il
        suo tasso di conversione personale sia considerato affidabile.

    - **Gestione Dati Storici e Medie:**

      - lookback_seasons: (es. 4) Numero di stagioni storiche da
        considerare per calcolare le medie ponderate delle statistiche
        generali.

      - season_decay_factor: (es. 0.75) Fattore di decadimento applicato
        ai pesi delle stagioni più vecchie. Se per 3 stagioni, i pesi
        potrebbero essere (1), (1 \* 0.75), (1 \* 0.75 \* 0.75), poi
        normalizzati.

      - fields_to_project: Array di stringhe che elenca i nomi delle
        colonne delle statistiche da HistoricalPlayerStat che devono
        essere proiettate (es. \'avg_rating\', \'goals_scored\',
        \'assists\').

      - min_games_for_reliable_avg_rating: (es. 10) Numero minimo di
        partite giocate in una stagione perché la Media Voto di quella
        stagione sia considerata affidabile nel calcolo della media
        ponderata.

    - **Impatto del Tier Squadra:**

      - default_team_tier: (es. 3) Tier di squadra da usare come
        fallback se una squadra non ha un tier definito.

      - team_tier_multipliers_offensive: Array associativo (Tier =\>
        Moltiplicatore) per modulare le statistiche offensive.

        - **Esempio:** \'1\' =\> 1.20 (un giocatore offensivo in una
          squadra di Tier 1 vedrà le sue stats offensive di base
          aumentate del 20%).

      - team_tier_multipliers_defensive: Simile, ma per statistiche
        difensive.

        - **Esempio:** \'1\' =\> 0.80 (un portiere/difensore in una
          squadra di Tier 1 vedrà i suoi gol subiti di base ridotti del
          20%, o la sua probabilità di CS aumentata).

      - team_tier_presence_factor: Array associativo (Tier =\>
        Moltiplicatore) per modulare le presenze attese.

        - **Esempio:** \'5\' =\> 0.95 (un giocatore in una squadra di
          Tier 5 potrebbe vedere le sue presenze leggermente ridotte
          rispetto alla sua media storica a causa della debolezza della
          squadra).

      - offensive_stats_fields: Campi influenzati dal
        team_tier_multipliers_offensive.

      - defensive_stats_fields_goalkeeper: Campi (per portieri)
        influenzati dal team_tier_multipliers_defensive.

    - **Clean Sheet:**

      - league_average_clean_sheet_rate_per_game: (es. 0.28) Tasso medio
        di clean sheet per partita osservato nel campionato.

      - clean_sheet_probabilities_by_tier: (Non presente nel file
        caricato, ma dedotto e implementato nel servizio) Array (Tier
        =\> Probabilità CS).

        - **Esempio (da aggiungere):**\
          PHP\
          \'clean_sheet_probabilities_by_tier\' =\> \[\
          1 =\> 0.40, // Squadra Top Tier ha 40% prob. base di CS\
          2 =\> 0.33,\
          3 =\> 0.28, // Media lega\
          4 =\> 0.22,\
          5 =\> 0.18, // Squadra debole ha 18% prob. base di CS\
          \],

      - max_clean_sheet_probability: (Non presente nel file caricato)
        Limite massimo per la probabilità di clean sheet proiettata (es.
        0.8).

    - **Valori di Default e Fallback:**

      - default_player_age: (es. 25) Età da usare se date_of_birth non è
        disponibile.

      - fallback_mv_if_no_history: (es. 5.5) Media Voto di default se un
        giocatore non ha storico.

      - fallback_fm_if_no_history: (es. 5.5) FantaMedia di default se un
        giocatore non ha storico.

      - fallback_gp_if_no_history: (es. 0) Presenze di default se un
        giocatore non ha storico.

      - min_projected_presences / max_projected_presences: (Non presenti
        nel file caricato, ma usati nel codice, es. 5 / 38) Limiti per
        le presenze proiettate.

    - **Configurazione Output Proiezioni:**

      - fields_to_project_output: Array associativo che mappa le chiavi
        dell\'output finale (es. goals_scored_proj) a come calcolarle.

        - **Esempio:**\
          PHP\
          \'goals_scored_proj\' =\> \[\'type\' =\> \'sum\',
          \'source_per_game\' =\> \'goals_scored\', \'default_value\'
          =\> 0\],\
          Significa che output\[\'goals_scored_proj\'\] sarà
          proiezione_goals_scored_per_partita \* presenze_proiettate.

> **Come Configurare/Modificare (con Esempi):**

- **Logica Rigoristi:**

  - Se nel tuo campionato i rigori sono rari, potresti abbassare
    league_avg_penalties_awarded.

  - Se hai osservato che il rigorista designato calcia quasi tutti i
    rigori, aumenta penalty_taker_share (es. a 0.90).

- **Ponderazione Storico:**

  - Se vuoi dare molto più peso alla stagione più recente, aumenta
    season_decay_factor verso 1.0 per le stagioni più vecchie, oppure
    implementa una logica di pesi espliciti (es. \[0.5, 0.3, 0.2\] per 3
    stagioni). L\'attuale season_decay_factor nel file caricato è 0.75,
    ma la logica del servizio calculateWeightedAverageStats usa un
    sistema di pesi decrescenti (N, N-1, \..., 1) normalizzati. Potrebbe
    essere necessario allineare il nome della chiave alla logica
    effettiva o viceversa.

- **Impatto Tier:**

  - Se le squadre di Tier 1 nel tuo campionato dominano segnando il 30%
    in più della media, imposta \'1\' =\> 1.30 in
    team_tier_multipliers_offensive.

  - Se le squadre di Tier 5 subiscono il 25% in più di gol, imposta
    \'5\' =\> 1.25 in team_tier_multipliers_defensive.

- **Valori di Default per Giocatori Nuovi/Sconosciuti:**

  - Se un nuovo attaccante arriva in Serie A senza storico, il sistema
    userà fallback_mv_if_no_history, fallback_fm_if_no_history e i
    default da getDefaultStatsPerGameForRole. Assicurati che questi
    valori siano una stima prudente ma ragionevole.

Spero che questa documentazione aggiornata con esempi sia più chiara e
ti aiuti a calibrare al meglio il tuo motore di proiezioni!
