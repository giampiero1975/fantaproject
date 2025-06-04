

Plaintext

\# Documento Tecnico Consolidato: FantaProject \- Applicazione Laravel per Proiezioni e Strategia Asta Fantacalcio

Versione: 1.4 (Consolidata con Flusso Command e Proiezioni)

Data: 4 Giugno 2025

\*\*Storico Documenti Sorgente Integrati:\*\*

\* \`Fanta-asta v1.0.md\`  
\* \`Fanta-asta v1.1.md\`  
\* \`Fanta-asta v1.2.md\`  
\* \`Fanta-asta v1.2.1.md\`  
\* \`Fanta-asta v1.2.2.md\` (Concettualmente Fanta-asta v1.3)  
\* \`Fanta-asta v1.3.md\` (Consolidato precedente)  
\* \`Configurazione modulazione.md\`  
\* \`Import Avanzato Storico Giocatori e Conversione Statistiche Lega v1.0.md\`  
\* \`Integrazione Dati FBRef con Storico Giocatori e Proiezioni 1.1.md\`  
\* \`Web Scraper per FBRef.md\`  
\* \`memo per statistiche neopromosse.md\`  
\* \`metodologia e calibrazione sistema.md\`  
\* \`command 1.0.md\`  
\* \`Flusso command e proiezioni.md\`  
\* File CSV di esempio (,,,,,,,,,,)

\*\*Indice:\*\*

1\.  Obiettivo del Progetto  
2\.  Architettura di Sistema e Tecnologie  
3\.  Gestione dei Dati  
    3.1. Architettura dei Dati (Tabelle del Database)  
    3.2. Fonti Dati Primarie (Input Utente)  
    3.3. Fonti Dati per Arricchimento (API Esterne e Web Scraping)  
4\.  Modulo di Tiering Dinamico delle Squadre  
    4.1. Scopo e Obiettivi del Tiering Dinamico  
    4.2. Fonte Dati per il Tiering  
    4.3. Logica di Calcolo del Punteggio Forza e Assegnazione Tier  
    4.4. File di Configurazione: \`config/team\_tiering\_settings.php\`  
5\.  Modulo di Proiezione Performance Calciatori  
    5.1. Dati di Input per le Proiezioni  
    5.2. Logica di Calcolo delle Proiezioni  
    5.3. Proiezione per Giocatori con Storico Limitato/Assente (Neopromosse)  
    5.4. Output delle Proiezioni  
    5.5. File di Configurazione: \`config/projection\_settings.php\`  
    5.6. File di Configurazione: \`config/player\_age\_curves.php\`  
    5.7. Metodologia di Calibrazione del Sistema  
6\.  Modulo di Valutazione e Identificazione Talenti (Futuro)  
7\.  Modulo Strategia d'Asta (Futuro)  
8\.  Struttura Applicativa Laravel (Alto Livello)  
    8.1. Modelli Principali (Eloquent)  
    8.2. Servizi Chiave  
    8.3. Controller e Viste Principali  
    8.4. Processi in Background (Jobs)  
    8.5. Comandi Artisan Personalizzati (Flusso Operativo e Dettagli)  
    8.6. Flusso di Esecuzione dei Comandi (Ordine e Casi d'Uso)  
9\.  Considerazioni Aggiuntive e Sviluppi Futuri

\---

\#\#\# \*\*1. Obiettivo del Progetto\*\*

L'obiettivo primario del FantaProject è sviluppare un'applicazione web basata su Laravel che assista l'utente nella preparazione e nella conduzione dell'asta del Fantacalcio (Serie A). L'applicazione mira a fornire:  
\* Proiezioni personalizzate sulle performance dei calciatori.  
\* Identificazione di giocatori sottovalutati (in futuro).  
\* Supporto per definire una strategia d'asta ottimale (in futuro).

Il sistema tiene conto delle regole specifiche della lega dell'utente, della forza dinamicamente calcolata delle squadre e di dinamiche di mercato complesse.

\#\#\# \*\*2. Architettura di Sistema e Tecnologie\*\*

\* \*\*Piattaforma:\*\* Applicazione Web.  
\* \*\*Framework Backend:\*\* Laravel (PHP).  
\* \*\*Database:\*\* Relazionale (es. MySQL, PostgreSQL), configurato in \`config/database.php\`.  
\* \*\*Frontend:\*\* Blade templates (\`resources/views/\`), JavaScript con \`resources/js/app.js\` e \`bootstrap.js\` (come da \`webpack.mix.js\`). Possibile utilizzo di JavaScript interattivo (es. Livewire, Vue.js o Alpine.js).  
\* \*\*Librerie Chiave:\*\*  
    \* \`Maatwebsite/Laravel-Excel\` (per importazione/esportazione XLSX).  
    \* \`League/Csv\` (per importazione CSV).  
    \* Laravel HTTP Client (basato su Guzzle) per chiamate API esterne.  
    \* \`Carbon\` (per manipolazione date/età).  
    \* \`fabpot/goutte\` (per web scraping).  
\* \*\*Ambiente di Sviluppo Locale:\*\* Laragon.

\#\#\# \*\*3. Gestione dei Dati\*\*

\#\#\#\# \*\*3.1. Architettura dei Dati (Tabelle del Database)\*\*

Comprendere il ruolo di ogni tabella è fondamentale per un flusso di dati coerente.

\* \*\*\`players\` (\`App\\Models\\Player\`): Tabella Principale Giocatori\*\*  
    \* \*\*Scopo:\*\* Contiene i dati anagrafici \*\*attuali\*\* dei giocatori, la loro quotazione attuale e le \*\*proiezioni finali per la stagione successiva\*\*.  
    \* \*\*Chiavi Importanti:\*\*  
        \* \`id\` (BIGINT UNSIGNED, PK): ID interno univoco del giocatore nel tuo database.  
        \* \`fanta\_platform\_id\` (INT, NULLABLE, UNIQUE): ID univoco del giocatore sulla piattaforma Fantacalcio ufficiale (es. Lega Serie A). Se un giocatore è importato solo da Fbref e non ha un ID ufficiale, verrà popolato con il suo \`id\` interno per mantenere l'univocità e la collegabilità.  
    \* \*\*Campi di Proiezione (OUTPUT del motore di proiezioni):\*\*  
        \* \`avg\_rating\_proj\` (FLOAT, NULLABLE): Media Voto proiettata per la stagione futura.  
        \* \`fanta\_mv\_proj\` (FLOAT, NULLABLE): Fantamedia proiettata per la stagione futura.  
        \* \`games\_played\_proj\` (INT, NULLABLE): Partite giocate proiettate per la stagione futura.  
        \* \`total\_fanta\_points\_proj\` (FLOAT, NULLABLE): Punti Fantacalcio totali proiettati per la stagione futura.  
    \* \*\*NON contiene\*\* proiezioni dettagliate per gol, assist, cartellini, ecc. (quelle sono calcoli intermedi).  
    \* Supporta SoftDeletes.  
\* \*\*\`teams\` (\`App\\Models\\Team\`): Tabella Squadre\*\*  
    \* \*\*Scopo:\*\* Contiene i dati delle squadre di calcio.  
    \* \*\*Chiavi Importanti:\*\*  
        \* \`id\` (BIGINT UNSIGNED, PK): ID interno univoco della squadra.  
        \* \`api\_football\_data\_team\_id\` (INT, NULLABLE, UNIQUE): ID della squadra sull'API Football-Data.org.  
    \* \`serie\_a\_team\` (boolean), per indicare partecipazione alla Serie A nella stagione target, gestito da \`teams:set-active-league\`.  
    \* \`tier\` (integer), rappresenta una proiezione della forza della squadra per la stagione corrente, inizialmente da \`TeamSeeder\`, poi calcolato dinamicamente da \`TeamTieringService\`.  
\* \*\*\`player\_fbref\_stats\` (\`App\\Models\\PlayerFbrefStat\`): Tabella Dati Grezzi FBRef\*\*  
    \* \*\*Scopo:\*\* Memorizza le statistiche grezze dei giocatori così come vengono raschiate da FBRef, per ogni stagione. Questi dati sono ancora nel formato originale di FBRef (es. "90 min", "Reti").  
    \* \*\*Chiavi Importanti:\*\*  
        \* \`player\_id\` (BIGINT UNSIGNED): FK a \`players.id\`. Collega la statistica grezza al giocatore nel tuo database.  
        \* \`team\_id\` (BIGINT UNSIGNED): FK a \`teams.id\`. Collega la statistica grezza alla squadra nel tuo database.  
        \* \`season\_year\`, \`league\_name\`, \`data\_source\`, ecc.  
    \* Le colonne numeriche sono state alterate per supportare valori decimali/float.  
\* \*\*\`historical\_player\_stats\` (\`App\\Models\\HistoricalPlayerStat\`): Tabella Storico Giocatori Elaborato\*\*  
    \* \*\*Scopo:\*\* Contiene le statistiche storiche dei giocatori, elaborate e convertite in un formato standardizzato, pronte per essere utilizzate dal motore di proiezioni. Questa è la \*\*fonte di dati storica\*\* per le proiezioni.  
    \* \*\*Chiavi Importanti:\*\*  
        \* \`player\_fanta\_platform\_id\` (INT, NULLABLE, MUL): FK a \`players.fanta\_platform\_id\`. Questa è la chiave per collegare lo storico al giocatore.  
        \* \`season\_year\` (INT): Anno della stagione storica.  
    \* \*\*Contiene:\*\* \`games\_played\`, \`avg\_rating\`, \`fanta\_avg\_rating\`, \`goals\_scored\`, \`assists\`, \`yellow\_cards\`, \`red\_cards\`, \`own\_goals\`, \`penalties\_saved\`, \`penalties\_taken\`, \`penalties\_scored\`, \`penalties\_missed\`, \`goals\_conceded\`, ecc.  
    \* La colonna \`league\_name\` è stata aggiunta a questa tabella per tracciare la lega di origine.  
\* \*\*\`user\_league\_profiles\` (\`App\\Models\\UserLeagueProfile\`):\*\*  
    \* Memorizza le configurazioni della lega dell'utente, inclusi nome lega, budget totale, numero di giocatori per ruolo, numero partecipanti, e \`scoring\_rules\` (JSON).  
\* \*\*\`import\_logs\` (\`App\\Models\\ImportLog\`):\*\*  
    \* Traccia le operazioni di importazione file (roster, storico). Include nome file, tipo, stato, dettagli, conteggi righe.  
\* \*\*\`team\_historical\_standings\` (\`App\\Models\\TeamHistoricalStanding\`):\*\*  
    \* Memorizza dati storici delle classifiche delle squadre (posizione, punti, gol, etc.) per stagione e lega.  
    \* Popolata tramite API (\`TeamDataService\` / \`teams:fetch-historical-standings\`) o import CSV (\`teams:import-standings-file\`).

\#\#\#\# \*\*3.2. Fonti Dati Primarie (Input Utente)\*\*

\* \*\*File XLSX Roster Ufficiale:\*\* L'applicazione permette l'upload di file XLSX (es. da Fantacalcio.it) tramite interfaccia web (gestito da \`RosterImportController\`).  
    \* Contenuto: Lista ufficiale dei calciatori per la stagione, ruoli ufficiali (P, D, C, A) e ruoli Mantra (Rm), quotazioni iniziali di riferimento (CRD), ID piattaforma Fantacalcio (\`fanta\_platform\_id\`).  
    \* Un tag/titolo dalla prima riga del foglio "Tutti" viene estratto e salvato in \`ImportLog\`.  
    \* Le CRD ufficiali sono un valore di riferimento/benchmark, non la base d'asta (che parte da 1 credito per ogni giocatore).  
\* \*\*File XLSX Statistiche Storiche Giocatori:\*\* L'applicazione permette l'upload di file XLSX contenenti le statistiche dei giocatori delle stagioni precedenti (formato atteso: Riga 1 come titolo/tag, Riga 2 con intestazioni Id, R, Rm, Nome, Squadra, Pv, Mv, Fm, Gf, Gs, Rp, Rc, R+, R-, Ass, Amm, Esp, Au). Il caricamento avviene tramite interfaccia web (gestito da \`HistoricalStatsImportController\`).  
    \* La stagione viene derivata dal nome del file. L'ID giocatore nel file viene usato come \`player\_fanta\_platform\_id\`.  
\* \*\*File CSV Classifiche Storiche Squadre (Opzionale/Fallback):\*\* Importazione tramite comando Artisan (\`teams:import-standings-file\`) per stagioni/leghe non coperte dall'API.  
    \* Contenuto: Posizione, punti, GF, GS, etc. per squadra e stagione. Esempi di file CSV includono dati per Serie A e Serie B di diverse stagioni,,,,,,,,,,.  
    \* Permette di creare automaticamente nella tabella \`teams\` le squadre presenti nel CSV ma non nel database.

\#\#\#\# \*\*3.3. Fonti Dati per Arricchimento (API Esterne e Web Scraping)\*\*

\* \*\*API Football-Data.org (v4):\*\*  
    \* \*\*Configurazione API:\*\* Chiave API memorizzata in \`.env\` (\`FOOTBALL\_DATA\_API\_KEY\`) e acceduta tramite \`config/services.php\`. URI Base e codici lega (\`SA\`, \`SB\`) sono configurati in \`config/services.php\`.  
    \* \*\*Dati Giocatore Recuperati (\`DataEnrichmentService\`):\*\* Data di nascita (\`date\_of\_birth\`), ID del giocatore sull'API esterna (\`api\_football\_data\_id\`), posizione/ruolo dettagliato (\`detailed\_position\`). La logica include la normalizzazione dei nomi per il matching e la gestione della cache e del rate limit.  
    \* \*\*Dati Squadre Recuperati (\`TeamDataService\`):\*\* Liste squadre per competizione e stagione (usate da \`teams:map-api-ids\` e \`teams:set-active-league\`), classifiche storiche per competizione e stagione (usate da \`teams:fetch-historical-standings\` per popolare \`team\_historical\_standings\`). La logica di matching include la priorità per ID API e la normalizzazione dei nomi.  
\* \*\*Web Scraping da FBRef.com (\`FbrefScrapingService\` e \`ScrapeFbrefTeamStatsCommand\`):\*\*  
    \* Obiettivo: Estrarre dati statistici tabellari grezzi dalle pagine delle squadre di FBRef, specialmente per giocatori con storico limitato o provenienti da leghe diverse (es. Serie B),.  
    \* Il servizio estrae tabelle con ID che iniziano con "stats\_", gestisce intestazioni e righe.  
    \* Il comando salva i dati grezzi in \`player\_fbref\_stats\` e può creare nuovi record \`Player\` se non esistono. Calcola e salva anche la \`date\_of\_birth\` del giocatore basandosi sulla stringa dell'età di FBRef e sull'anno della stagione. Inoltre, se il Player non ha un \`fanta\_platform\_id\` (non da listone ufficiale), popola \`players.fanta\_platform\_id\` con l'ID interno del Player per garantirne la collegabilità.  
\* \*\*Dati Custom/Manuali (Importazione Avanzata):\*\* L'applicazione supporta l'importazione flessibile di dati storici da file CSV/XLSX (tramite \`PlayerSeasonStatsImport\` e \`players:import-advanced-stats\`) che possono includere \`league\_name\` e dati da FBRef o altre fonti, omogeneizzati per essere direttamente utilizzabili dal motore di proiezioni.  
\* \*\*Dati Qualitativi Curati:\*\* (Manualmente o Futuri Servizi): Probabili rigoristi, ruoli tattici specifici, gerarchie (titolari/riserve).

\#\#\# \*\*4. Modulo di Tiering Dinamico delle Squadre\*\*

\#\#\#\# \*\*4.1. Scopo e Obiettivi del Tiering Dinamico\*\*

Lo scopo è superare un tiering statico delle squadre, fornendo una valutazione della forza di una squadra (tier) che sia:  
\* \*\*Basata su Dati:\*\* Calcolata analizzando le performance storiche.  
\* \*\*Adattiva:\*\* Riflette l'andamento recente e la forza relativa delle squadre.  
\* \*\*Configurabile:\*\* Permette di pesare diversamente le stagioni storiche e le metriche di performance.  
\* \*\*Modulabile:\*\* Considera la differenza di competitività tra diverse leghe (es. Serie A vs Serie B).

Questo tier dinamico è poi utilizzato dal \`ProjectionEngineService\` per modulare le proiezioni dei singoli giocatori.

\#\#\#\# \*\*4.2. Fonte Dati per il Tiering\*\*

\* Tabella \`team\_historical\_standings\`: Contiene i piazzamenti, punti, GF, GS delle squadre nelle stagioni precedenti (Serie A e, opzionalmente, Serie B).  
\* File di configurazione \`config/team\_tiering\_settings.php\`: Definisce tutti i parametri per il calcolo.

\#\#\#\# \*\*4.3. Logica di Calcolo del Punteggio Forza e Assegnazione Tier (gestita da \`TeamTieringService\`)\*\*

1\.  \*\*Selezione Squadre Attive:\*\* Il servizio considera le squadre marcate come attive per la lega e la stagione target (\`serie\_a\_team \= true\` per la Serie A).  
2\.  \*\*Lookback Storico:\*\* Per ogni squadra attiva, vengono recuperati i dati da \`team\_historical\_standings\` per un numero configurabile di stagioni precedenti (\`lookback\_seasons\_for\_tiering\`).  
3\.  \*\*Calcolo Punteggio Stagione Individuale:\*\* Per ogni stagione storica recuperata, si calcola un "punteggio stagione grezzo" basato su metriche definite (\`metric\_weights\` in config), come punti, differenza reti, gol fatti, e posizione in classifica (invertita).  
    \* \*\*Moltiplicatore di Lega:\*\* Il punteggio stagione grezzo viene moltiplicato per un fattore che riflette la forza relativa della lega in cui è stato ottenuto (es. performance in Serie B "valgono meno" di quelle in Serie A, definito in \`league\_strength\_multipliers\`).  
4\.  \*\*Calcolo Punteggio Forza Complessivo:\*\* I punteggi stagione (aggiustati per lega) vengono combinati in un punteggio forza complessivo tramite una media pesata, dove le stagioni più recenti hanno un peso maggiore (\`season\_weights\`).  
    \* \*\*Gestione Neopromosse/Dati Mancanti:\*\* Se una squadra ha dati storici insufficienti, le viene assegnato un punteggio grezzo di default (\`newly\_promoted\_raw\_score\_target\`), pensato per collocarla in un tier di partenza predefinito (\`newly\_promoted\_tier\_default\`).  
5\.  \*\*Normalizzazione Punteggi (Opzionale):\*\* Se configurato (\`normalization\_method \= 'min\_max'\`), i punteggi forza grezzi di tutte le squadre attive vengono scalati in un range comune (es. 0-100) per facilitare l'applicazione di soglie assolute.  
6\.  \*\*Assegnazione Tier:\*\* Il tier finale (da 1 a 5\) viene assegnato in base al punteggio (normalizzato o grezzo) confrontandolo con soglie fisse predefinite (\`tier\_thresholds\_config\`) o soglie calcolate dinamicamente basate su percentili (\`tier\_percentiles\_config\`).  
7\.  \*\*Aggiornamento Database:\*\* Il campo \`tier\` nella tabella \`teams\` viene aggiornato con il nuovo valore calcolato.

\#\#\#\# \*\*4.4. File di Configurazione: \`config/team\_tiering\_settings.php\`\*\*

Questo file definisce tutti i parametri per il \`TeamTieringService\`:  
\* \`lookback\_seasons\_for\_tiering\`: Quante stagioni storiche considerare.  
\* \`season\_weights\`: Pesi da assegnare alle stagioni storiche.  
\* \`metric\_weights\`: Pesi per le diverse metriche (\`points\`, \`goal\_difference\`, \`goals\_for\`, \`position\`).  
\* \`normalization\_method\`: Metodo per normalizzare i punteggi (\`'min\_max'\` o \`'none'\`).  
\* \`tier\_thresholds\_source\`: Sorgente per le soglie dei tier (\`'config'\` o \`'dynamic\_percentiles'\`).  
\* \`tier\_thresholds\_config\`: Soglie fisse per i tier se \`tier\_thresholds\_source\` è \`'config'\`.  
\* \`tier\_percentiles\_config\`: Percentili se \`tier\_thresholds\_source\` è \`'dynamic\_percentiles'\`.  
\* \`newly\_promoted\_tier\_default\`: Tier di default per le squadre neopromosse o con dati insufficienti.  
\* \`newly\_promoted\_raw\_score\_target\`: Punteggio grezzo assegnato alle neopromosse.  
\* \`api\_football\_data\`: ID delle competizioni API (\`SA\`, \`SB\`) e endpoint per le classifiche.  
\* \`league\_strength\_multipliers\`: Fattori per riflettere la forza relativa delle diverse leghe (es. Serie B vale 0.65 rispetto alla Serie A).

\#\#\# \*\*5. Modulo di Proiezione Performance Calciatori\*\*

\#\#\#\# \*\*5.1. Dati di Input per le Proiezioni\*\*

\* Medie e FantaMedie storiche ponderate (da \`HistoricalPlayerStat\`).  
\* Età del giocatore (calcolata da \`date\_of\_birth\` recuperata via API e memorizzata in \`Players\`).  
\* Ruolo ufficiale Fantacalcio (da \`Players.role\`).  
\* Forza/fascia della squadra di appartenenza (\`Team.tier\`), calcolato dinamicamente dal \`TeamTieringService\`.  
\* Minutaggio/Presenze attese (stima interna basata su storico, età e tier squadra).  
\* Status di rigorista/specialista calci piazzati (da \`PlayerTacticalNotes\` \- futuro).  
\* Ruolo tattico reale (se diverso e identificato, da \`PlayerTacticalNotes\` o dedotto da \`detailed\_position\` API \- futuro).

\#\#\#\# \*\*5.2. Logica di Calcolo delle Proiezioni (gestita da \`ProjectionEngineService\`)\*\*

\* \*\*Recupero Dati Storici e Ponderazione Stagionale:\*\* Il servizio carica le statistiche delle ultime N stagioni (default 4\) da \`HistoricalPlayerStat\`,. Vengono applicati pesi decrescenti per le stagioni più vecchie (\`calculateDefaultSeasonWeights\`).  
    \* Le statistiche da leghe diverse dalla Serie A (es. Serie B) vengono "tradotte" al livello atteso della Serie A utilizzando \`player\_stats\_league\_conversion\_factors\` definiti in \`config/projection\_settings.php\`.  
\* \*\*Aggiustamento per Età ("Curva di Rendimento"):\*\* Viene calcolato un \`ageModifier\` basato sull'età del giocatore e su curve di rendimento definite per ruolo in \`config/player\_age\_curves.php\`. Questo modificatore influenza MV, gol/partita, assist/partita, probabilità di clean sheet e presenze attese.  
\* \*\*Aggiustamento Statistiche per Contesto Squadra (Tier):\*\* Le statistiche offensive (gol, assist) vengono moltiplicate per un \`team\_tier\_multipliers\_offensive\`, e le statistiche difensive (gol subiti per portieri/difensori) sono modulate da un \`team\_tier\_multipliers\_defensive\`. Questi moltiplicatori dipendono dal \`Team.tier\` calcolato dinamicamente.  
\* \*\*Logica Rigoristi:\*\* Identifica probabili rigoristi e proietta i loro rigori calciati/segnati basandosi su parametri in \`config/projection\_settings.php\` (es. \`penalty\_taker\_lookback\_seasons\`, \`min\_penalties\_taken\_threshold\`, \`league\_avg\_penalties\_awarded\`, \`penalty\_taker\_share\`, \`default\_penalty\_conversion\_rate\`).  
\* \*\*Stima Presenze Attese:\*\* Influenzata da storico (\`avg\_games\_played\`), età (\`presenzeAgeFactor\`), e tier squadra (\`team\_tier\_presence\_factor\`). Limitate a un range realistico (es. min 5, max 38 partite).  
\* \*\*Proiezione Clean Sheet per Partita (Difensori/Portieri):\*\* La probabilità di clean sheet (\`clean\_sheet\_per\_game\_proj\`) è calcolata considerando il tier squadra (\`clean\_sheet\_probabilities\_by\_tier\` in config) e l'età del giocatore (\`cs\_age\_effect\_ratio\` in \`player\_age\_curves.php\`). Il \`ProjectionEngineService\` calcola poi il contributo medio atteso del bonus clean sheet (probabilità\_CS \* bonus\_CS\_da\_regole\_lega) e lo aggiunge alla FantaMedia base.  
\* \*\*Calcolo FantaMedia Proiettata per Partita:\*\* Le statistiche medie per partita aggiustate vengono passate al \`FantasyPointCalculatorService\`. Il servizio calcola la FantaMedia per partita attesa (\`fanta\_media\_proj\_per\_game\`), basata sulle \`scoring\_rules\` della lega dell'utente.

\#\#\#\# \*\*5.3. Proiezione per Giocatori con Storico Limitato/Assente (Neopromosse)\*\*

Per i giocatori delle squadre neopromosse o per nuovi acquisti senza uno storico significativo in Serie A, il sistema utilizza un metodo di proiezione alternativo basato sulla loro quotazione iniziale (\`initial\_quotation\`) e sul tier della loro squadra.  
\* \*\*Input:\*\* \`initial\_quotation\` (dalla tabella \`players\`), \`tier\` della squadra (dalla tabella \`teams\`), \`role\` e \`date\_of\_birth\` del giocatore.  
\* \*\*Logica:\*\*  
    1\.  \`quotation\_to\_base\_stats\_per\_game\`: Una mappa in \`config/projection\_settings.php\` associa range di quotazione, per ruolo, a statistiche base per partita (es. MV, GolFatti/stagione, Assist/stagione, etc.).  
    2\.  \`tier\_stat\_modifiers\_for\_quotation\_projection\`: Moltiplicatori applicati alle statistiche base in base al tier della squadra.  
    3\.  La funzione \`getProjectedStatsFromQuotationAndTier\` (da implementare) nel \`ProjectionEngineService\` gestirà questa logica.  
    4\.  Se \`initial\_quotation\` è NULL (giocatore non importato dal listone ufficiale), il sistema può usare \`default\_stats\_per\_role\` e il tier della squadra per modulare le proiezioni.

\#\#\#\# \*\*5.4. Output delle Proiezioni\*\*

\* FantaMedia Proiettata per Partita (\`fanta\_media\_proj\_per\_game\`).  
\* Fantapunti Totali Stagionali Proiettati (\`total\_fantasy\_points\_proj\`).  
\* Media Voto Proiettata per Partita (\`mv\_proj\_per\_game\`).  
\* Presenze Proiettate (\`presenze\_proj\`).  
\* Proiezioni delle singole statistiche totali per la stagione (gol, assist, ecc. in \`seasonal\_totals\_proj\`).  
\* Statistiche medie per partita usate per il calcolo della FantaMedia (\`stats\_per\_game\_for\_fm\_calc\`), che include il contributo medio del clean sheet aggiunto (\`avg\_cs\_bonus\_added\`) e la probabilità CS utilizzata (\`clean\_sheet\_probability\_used\`).

\#\#\#\# \*\*5.5. File di Configurazione: \`config/projection\_settings.php\`\*\*

Contiene parametri che governano il \`ProjectionEngineService\`:  
\* \*\*Parametri Rigoristi:\*\* \`penalty\_taker\_lookback\_seasons\`, \`min\_penalties\_taken\_threshold\`, \`league\_avg\_penalties\_awarded\`, \`penalty\_taker\_share\`, \`default\_penalty\_conversion\_rate\`, \`min\_penalties\_taken\_for\_reliable\_conversion\_rate\`.  
\* \*\*Gestione Dati Storici e Medie:\*\* \`lookback\_seasons\`, \`season\_decay\_factor\`, \`fields\_to\_project\`, \`min\_games\_for\_reliable\_avg\_rating\`. (Nota: la logica effettiva dei pesi può usare un sistema N, N-1...1 normalizzato, come implementato in \`calculateDefaultSeasonWeights\` in \`ProjectionEngineService.php\`).  
\* \*\*Impatto del Tier Squadra:\*\* \`default\_team\_tier\`, \`team\_tier\_multipliers\_offensive\`, \`team\_tier\_multipliers\_defensive\`, \`team\_tier\_presence\_factor\`, \`offensive\_stats\_fields\`, \`defensive\_stats\_fields\_goalkeeper\`.  
\* \*\*Clean Sheet:\*\* \`league\_average\_clean\_sheet\_rate\_per\_game\`, \`clean\_sheet\_probabilities\_by\_tier\`, \`max\_clean\_sheet\_probability\`.  
\* \*\*Valori di Default e Fallback:\*\* \`default\_player\_age\`, \`fallback\_mv\_if\_no\_history\`, \`fallback\_fm\_if\_no\_history\`, \`fallback\_gp\_if\_no\_history\`, \`min\_projected\_presences\`, \`max\_projected\_presences\`.  
\* \*\*Configurazione Output Proiezioni:\*\* \`fields\_to\_project\_output\`.  
\* \*\*Fattori di Conversione Statistiche Lega (\`player\_stats\_league\_conversion\_factors\`):\*\* Definisce come le statistiche da una lega di origine (es. Serie B) vengono "tradotte" al livello atteso della Serie A (es. \`goals\_scored\`, \`assists\`, \`avg\_rating\`).

\#\#\#\# \*\*5.6. File di Configurazione: \`config/player\_age\_curves.php\`\*\*

Definisce come l'età di un giocatore influenza le proiezioni delle sue performance:  
\* \`dati\_ruoli\`: Mappa per ogni ruolo (P, D\_CENTRALE, D\_ESTERNO, C, A) le \`fasi\_carriera\` (età di sviluppo, picco, declino), \`growth\_factor\`, \`decline\_factor\`, \`young\_cap\`, \`old\_cap\`.  
\* \`age\_modifier\_params\`: Definisce come l'\`ageModifier\` generale viene applicato a diverse statistiche (\`mv\_effect\_ratio\`, \`cs\_age\_effect\_ratio\`, \`presenze\_growth\_effect\_ratio\`, \`presenze\_decline\_effect\_ratio\`, \`presenze\_growth\_cap\`, \`presenze\_decline\_cap\`).

\#\#\#\# \*\*5.7. Metodologia di Calibrazione del Sistema\*\*

Il processo di calibrazione è iterativo e si concentra sulla calibrazione dei parametri in \`config/projection\_settings.php\`, \`config/player\_age\_curves.php\`, e \`config/team\_tiering\_settings.php\`.  
1\.  \*\*Preparazione Dati di Input:\*\* Assicurarsi che \`team\_historical\_standings\` e \`historical\_player\_stats\` (inclusi i dati da Serie B con \`league\_name\` e \`MediaVotoOriginale\` stimata) siano popolati correttamente.  
2\.  \*\*Esecuzione Processi:\*\* Eseguire \`teams:set-active-league\`, \`teams:map-api-ids\`, \`teams:update-tiers\` e \`test:projection {playerId}\` per generare proiezioni.  
3\.  \*\*Analisi Comparativa:\*\* Confrontare le proiezioni del sistema con dati di benchmark esterni (FBRef per xG, xAG, ecc., Quotazioni di Riferimento (CRd) e Medie Voto/FantaMedie reali a fine stagione da Fantacalcio.it).  
4\.  \*\*Ciclo di Calibrazione:\*\* Modificare i parametri di configurazione e rieseguire i processi fino a quando le proiezioni appaiono ragionevoli e coerenti.

\#\#\# \*\*6. Modulo di Valutazione e Identificazione Talenti (Futuro)\*\*

\* \*\*Calcolo del "Valore d'Asta Interno" (Target Price):\*\* Basato sulla FantaMedia Proiettata e sulla scarsità del ruolo, rapportato al budget totale della lega. Questo valore rappresenta quanto l'utente dovrebbe essere disposto a pagare per un giocatore.  
\* \*\*Identificazione Giocatori Sottovalutati ("Scommesse"):\*\* Confronto tra il "Valore d'Asta Interno" e la CRD ufficiale o la percezione del mercato per evidenziare opportunità o giocatori sopravvalutati.

\#\#\# \*\*7. Modulo Strategia d'Asta (Futuro)\*\*

\* \*\*Configurazione Lega Fantacalcistica Utente:\*\* L'utente potrà inserire (tramite \`UserLeagueProfiles\`) budget totale, numero di giocatori per ruolo, regole di punteggio specifiche e numero di partecipanti.  
\* \*\*Suddivisione Giocatori in Fasce (Tiering):\*\* I giocatori verranno classificati in fasce (es. Top Player, Scommesse) basandosi sul loro "Valore d'Asta Interno" e/o FantaMedia Proiettata.  
\* \*\*Pianificazione Budget per Reparto:\*\* L'utente potrà definire percentuali o importi fissi del budget da allocare per i diversi ruoli.  
\* \*\*Gestione "Coppie" Titolare/Riserva:\*\* Identificazione e suggerimento di potenziali "coppie" (es. portiere titolare \+ riserva) per ottimizzare costo e copertura.  
\* \*\*Gestione Diversificazione/Concentrazione per Squadra:\*\* Monitoraggio del numero di giocatori per squadra di Serie A nel piano d'asta, con possibilità di impostare limiti per mitigare il rischio "annata no".  
\* \*\*Generazione Lista d'Asta Finale:\*\* Output di una lista stampabile/esportabile con Nome, Squadra, Ruolo, CRD Ufficiale, Valore Obiettivo, Max Bid Consigliato, Fascia e Note strategiche.

\#\#\# \*\*8. Struttura Applicativa Laravel (Alto Livello)\*\*

\#\#\#\# \*\*8.1. Modelli Principali (Eloquent)\*\*

\* \`App\\Models\\Player\`  
\* \`App\\Models\\Team\`  
\* \`App\\Models\\HistoricalPlayerStat\`  
\* \`App\\Models\\UserLeagueProfile\`  
\* \`App\\Models\\ImportLog\`  
\* \`App\\Models\\TeamHistoricalStanding\`  
\* \`App\\Models\\PlayerFbrefStat\`  
\* (Futuri) \`App\\Models\\PlayerTacticalNote\`, \`App\\Models\\AuctionPlan\`, \`App\\Models\\AuctionPlanTarget\`.

\#\#\#\# \*\*8.2. Servizi Chiave\*\*

\* \*\*Logica di Importazione:\*\*  
    \* \`App\\Imports\\MainRosterImport\` (usa \`TuttiSheetImport\`, \`FirstRowOnlyImport\`).  
    \* \`App\\Imports\\HistoricalStatsFileImport\` (usa \`TuttiHistoricalStatsImport\`, \`FirstRowOnlyImport\`).  
    \* \`App\\Imports\\PlayerSeasonStatsImport\` (per import avanzato storico da file CSV/XLSX con \`league\_name\` e altre statistiche).  
\* \`App\\Services\\DataEnrichmentService\`: Si connette a Football-Data.org API, recupera \`date\_of\_birth\`, \`detailed\_position\`, \`api\_football\_data\_id\` per i giocatori, implementa logica di matching nomi e cache.  
\* \`App\\Services\\TeamDataService\`: Recupero dati squadre e classifiche storiche da API Football-Data.org.  
\* \`App\\Services\\FbrefScrapingService\`: Logica per lo scraping di dati tabellari da FBRef.  
\* \`App\\Services\\ProjectionEngineService\`: Cuore del sistema, implementa la logica di proiezione (storico ponderato, età, tier squadra, rigoristi, clean sheet).  
\* \`App\\Services\\FantasyPointCalculatorService\`: Converte statistiche (per partita) in FantaMedia (per partita) basata sulle \`scoring\_rules\` della lega.  
\* \`App\\Services\\TeamTieringService\`: Calcola dinamicamente il tier delle squadre basandosi su dati storici e configurazioni.  
\* (Futuri) \`AuctionValueCalculatorService\`, \`PlayerTieringService\`, \`AuctionStrategyBuilderService\`, \`PairAnalyzerService\`, \`TeamConcentrationService\`.

\#\#\#\# \*\*8.3. Controller e Viste Principali\*\*

\* \*\*Controller Implementati:\*\*  
    \* \`App\\Http\\Controllers\\RosterImportController.php\`.  
    \* \`App\\Http\\Controllers\\HistoricalStatsImportController.php\`.  
    \* \`App\\Http\\Controllers\\UserLeagueProfileController.php\`.  
\* \*\*Viste Implementate (in \`resources/views/\`):\*\*  
    \* \`uploads/roster.blade.php\`.  
    \* \`uploads/historical\_stats.blade.php\`.  
    \* \`league/profile\_edit.blade.php\`.  
    \* \`layouts/app.blade.php\` (layout base).  
\* Futuri: Controller e viste per visualizzazione proiezioni, costruzione piano d'asta, gestione tier, ecc.

\#\#\#\# \*\*8.4. Processi in Background (Jobs) (Consigliato/Da Implementare Come Asincroni)\*\*

\* Convertire importazioni (\`ImportFantacalcioRosterJob\`, \`ImportHistoricalStatsJob\`), arricchimento dati (\`EnrichPlayerDataJob\`) e calcolo tier/proiezioni (\`RecalculateProjectionsJob\`) in Job Laravel per migliorare la responsività e gestire meglio processi lunghi e rate limiting API.

\#\#\#\# \*\*8.5. Comandi Artisan Personalizzati (Flusso Operativo e Dettagli)\*\*

\* \*\*\`teams:set-active-league\`\*\*  
    \* \*\*Scopo:\*\* Definisce quali squadre partecipano a una lega (es. Serie A) per una stagione target. Aggiorna il flag \`teams.serie\_a\_team\`. Recupera la lista dei team partecipanti dall'API.  
    \* \*\*Utilizzo:\*\* Eseguire all'inizio della preparazione di una nuova stagione di proiezione.  
    \* \*\*Opzioni:\*\* \`--target-season-start-year=YYYY\` (obbligatorio), \`--league-code=SA\` (default), \`--set-inactive-first=true\` (default).  
    \* \*\*Esempio:\*\* \`php artisan teams:set-active-league \--target-season-start-year=2024 \--league-code=SA\`.

\* \*\*\`teams:map-api-ids\`\*\*  
    \* \*\*Scopo:\*\* Associa le squadre presenti nel database locale con i loro ID corrispondenti dall'API Football-Data.org. Popola o aggiorna il campo \`api\_football\_data\_team\_id\` nella tabella \`teams\`.  
    \* \*\*Utilizzo:\*\* Cruciale per permettere al \`TeamDataService\` di identificare correttamente le squadre. Da eseguire dopo aver popolato la tabella \`teams\` e dopo \`teams:set-active-league\`.  
    \* \*\*Opzioni:\*\* \`--season=YYYY\` (opzionale), \`--competition=SA\` (default).  
    \* \*\*Esempio:\*\* \`php artisan teams:map-api-ids \--competition=SA\`.

\* \*\*\`teams:import-standings-file\`\*\*  
    \* \*\*Scopo:\*\* Importa i dati storici delle classifiche da un file CSV locale nella tabella \`team\_historical\_standings\`. Permette di creare automaticamente nella tabella \`teams\` le squadre presenti nel CSV ma non nel database.  
    \* \*\*Utilizzo:\*\* Fondamentale per popolare lo storico delle classifiche per stagioni/leghe non accessibili tramite API o per un setup iniziale massivo.  
    \* \*\*Argomenti:\*\* \`{filepath}\` (obbligatorio).  
    \* \*\*Opzioni:\*\* \`--season-start-year=YYYY\` (obbligatorio), \`--league-name="Nome Lega"\` (default "Serie A"), \`--create-missing-teams=false\` (default), \`--default-tier-for-new=4\` (default), \`--is-serie-a-league=true\` (default).  
    \* \*\*Esempi:\*\* \`php artisan teams:import-standings-file storage/app/import/classifica\_serie\_a\_2021-22.csv \--season-start-year=2021\`. Esempi di file CSV includono: \`2023-24 A.csv\`, \`2024-25 A.csv\`, \`2024-25 B.csv\`, \`2023-24 B.csv\`, \`2022-23 B.csv\`, \`2021-22 B.csv\`, \`2020-21 B.csv\`, \`22-23 A.csv\`, \`21-22 A.csv\`, \`2020-21 A.csv\`, \`tracciato.csv\`.

\* \*\*\`teams:fetch-historical-standings\`\*\*  
    \* \*\*Scopo:\*\* Recupera i dati storici delle classifiche per una specifica competizione e stagione (o più stagioni recenti) dall'API Football-Data.org e li salva nella tabella \`team\_historical\_standings\`. Tenta di mappare le squadre API ai team locali usando \`api\_football\_data\_team\_id\` o, in fallback, il nome normalizzato.  
    \* \*\*Utilizzo:\*\* Per popolare automaticamente lo storico delle classifiche, necessario per il \`TeamTieringService\`. Da eseguire dopo \`teams:map-api-ids\` per massimizzare le corrispondenze.  
    \* \*\*Opzioni:\*\* \`--season=YYYY\` (opzionale), \`--all-recent=N\` (opzionale), \`--competition=SA\` (default).  
    \* \*\*Esempi:\*\* \`php artisan teams:fetch-historical-standings \--all-recent=3 \--competition=SA\`.

\* \*\*\`teams:update-tiers\`\*\*  
    \* \*\*Scopo:\*\* Esegue il \`TeamTieringService\` per ricalcolare e aggiornare i tier delle squadre (marcate come attive, es. \`serie\_a\_team=true\`) per la \`targetSeasonYear\` specificata.  
    \* \*\*Utilizzo:\*\* Eseguire dopo aver aggiornato i dati storici delle classifiche e definito le squadre attive per la stagione target.  
    \* \*\*Argomenti:\*\* \`{targetSeasonYear}\` (obbligatorio, formato "YYYY-YY").  
    \* \*\*Esempio:\*\* \`php artisan teams:update-tiers 2025-26\`.

\* \*\*\`players:import-advanced-stats\`\*\*  
    \* \*\*Scopo:\*\* Importa i dati storici avanzati dei giocatori da un file CSV/XLSX. Questi dati possono includere \`league\_name\` e statistiche dettagliate (es. da FBRef), che saranno poi usati per popolare \`historical\_player\_stats\` dopo conversione.  
    \* \*\*Utilizzo:\*\* Per integrare o arricchire lo storico dei giocatori, specialmente per neopromossi o giocatori con storico in leghe diverse.  
    \* \*\*Argomenti:\*\* \`{filepath}\` (obbligatorio).  
    \* \*\*Opzioni:\*\* \`--league=Serie B\` (default).  
    \* \*\*Esempio:\*\* \`php artisan players:import-advanced-stats storage/app/import/dati\_fbref\_serie\_b\_23-24.csv \--league="Serie B"\`.

\* \*\*\`fbref:scrape-team\`\*\*  
    \* \*\*Scopo:\*\* Esegue lo scraping di statistiche dettagliate di una squadra da un URL di FBRef e salva i dati grezzi nella tabella \`player\_fbref\_stats\`. Può anche creare record \`Player\` se mancanti. Inoltre, se il Player non ha un \`fanta\_platform\_id\`, lo popola con l'ID interno per garantirne la collegabilità.  
    \* \*\*Utilizzo:\*\* Per acquisire storico dettagliato da FBRef, specialmente per neopromosse e giocatori senza storico significativo nel listone.  
    \* \*\*Argomenti:\*\* \`{url}\` (obbligatorio).  
    \* \*\*Opzioni:\*\* \`--team\_id=\` (obbligatorio se non deducibile dall'URL), \`--season=YYYY\` (obbligatorio), \`--league=NomeLega\` (opzionale).  
    \* \*\*Esempio:\*\* \`php artisan fbref:scrape-team "https://fbref.com/it/squadre/4cceedfc/Statistiche-Pisa" \--team\_id=1 \--season=2024 \--league="Serie B"\`.

\* \*\*\`stats:process-fbref-to-historical\` (NUOVO COMANDO DA IMPLEMENTARE)\*\*  
    \* \*\*Scopo:\*\* Prende i dati grezzi da \`player\_fbref\_stats\`, li elabora (es. calcola statistiche per partita, applica fattori di conversione di lega) e li salva nella tabella \`historical\_player\_stats\`.  
    \* \*\*Utilizzo:\*\* Dopo aver popolato \`player\_fbref\_stats\` tramite scraping.  
    \* \*\*Argomenti:\*\* \`--season={ANNO\_TARGET}\` (o un intervallo di anni).  
    \* \*\*Processo:\*\* Legge da \`player\_fbref\_stats\`, trova il \`Player\` corrispondente, recupera il \`fanta\_platform\_id\`, applica i fattori di conversione e salva in \`historical\_player\_stats\` usando \`player\_fanta\_platform\_id\`.

\* \*\*\`players:enrich-data\`\*\*  
    \* \*\*Scopo:\*\* Arricchisce i dati dei giocatori nel database locale (es. data di nascita, posizione dettagliata, ID API) interrogando l'API esterna Football-Data.org.  
    \* \*\*Utilizzo:\*\* Fondamentale per ottenere dati anagrafici accurati (specialmente l'età). Da eseguire dopo l'importazione iniziale del roster e periodicamente.  
    \* \*\*Opzioni:\*\* \`--player\_id=all\` (default), \`--player\_name=\`, \`--delay=6\` (default).  
    \* \*\*Esempi:\*\* \`php artisan players:enrich-data\`.

\* \*\*\`players:generate-projections\` (NUOVO COMANDO DA IMPLEMENTARE)\*\*  
    \* \*\*Scopo:\*\* Inizializza il processo di generazione delle proiezioni per tutti i giocatori (o un sottoinsieme).  
    \* \*\*Utilizzo:\*\* Eseguire dopo aver popolato tutti i dati storici e i tier delle squadre.  
    \* \*\*Processo:\*\* Recupera i \`Player\`, per ognuno chiama \`ProjectionEngineService::generatePlayerProjection()\`, e salva i 4 valori di proiezione finali (\`avg\_rating\_proj\`, \`fanta\_mv\_proj\`, \`games\_played\_proj\`, \`total\_fanta\_points\_proj\`) direttamente nel record del \`Player\` nella tabella \`players\`.

\* \*\*\`test:projection\`\*\*  
    \* \*\*Scopo:\*\* Testa il \`ProjectionEngineService\` per un singolo giocatore specifico, generando e visualizzando le sue proiezioni statistiche e di FantaMedia per la stagione.  
    \* \*\*Utilizzo:\*\* Utile per il debug della logica di proiezione, per verificare l'impatto di modifiche ai parametri di configurazione su un giocatore campione, o per analizzare rapidamente le aspettative per un singolo calciatore.  
    \* \*\*Argomenti:\*\* \`{playerId}\` (obbligatorio, \`fanta\_platform\_id\` del giocatore).  
    \* \*\*Esempio:\*\* \`php artisan test:projection 2170\`.

\#\#\#\# \*\*8.6. Flusso di Esecuzione dei Comandi (Ordine e Casi d'Uso)\*\*

Questo flusso descrive l'ordine consigliato per preparare i dati e calcolare i tier per una nuova stagione di proiezione.

\*\*FASE 0: Preparazione Iniziale del Database (Migrazioni)\*\*

Queste migrazioni porteranno il tuo schema al punto di partenza corretto.

1\.  \*\*Risolvi l'errore di sintassi in \`ScrapeFbrefTeamStatsCommand.php\`:\*\*  
    \* Apri il file \`app/Console/Commands/ScrapeFbrefTeamStatsCommand.php\`.  
    \* Assicurati che la primissima cosa nel file sia esattamente \`\<?php\` (senza spazi o caratteri prima).  
    \* Assicurati che subito dopo \`\<?php\` ci sia la dichiarazione \`namespace App\\Console\\Commands;\` (senza spazi o caratteri tra di loro, a parte un singolo a capo).  
    \* Salva il file.  
2\.  \*\*Esegui il rollback di tutte le migrazioni recenti che hanno toccato \`players\` o \`historical\_player\_stats\`:\*\*  
    \* Esegui \`php artisan migrate:rollback \--step=1\` ripetutamente finché non hai annullato tutte le migrazioni che iniziano con \`2025\_06\_\`. L'obiettivo è tornare allo stato prima delle nostre modifiche complesse.  
    \* \*\*VERIFICA MANUALE:\*\* Esegui \`php artisan db:raw "DESCRIBE players;"\` e \`php artisan db:raw "DESCRIBE historical\_player\_stats;"\`.  
        \* \`players\`: NON deve avere colonne con suffisso \`\_proj\` e NON deve avere \`api\_football\_data\_player\_id\`. Deve avere \`fanta\_platform\_id\` (int, nullable, unique).  
        \* \`historical\_player\_stats\`: Deve avere \`player\_fanta\_platform\_id\` (int, nullable) e NON deve avere \`player\_id\`. Deve avere \`season\_year\` come \`varchar\`, e \`goals\_scored\`/\`assists\` come \`int\`.  
        \* \*\*Se lo schema non corrisponde, potresti dover creare migrazioni specifiche per \`dropColumn()\` o \`change()\` le colonne problematiche manualmente, oppure fare un \`php artisan migrate:fresh\` (ATTENZIONE: cancella tutti i dati\!).\*\*  
3\.  \*\*Applica la migrazione: Rendi \`fanta\_platform\_id\` nullable in \`players\` (se non lo è già)\*\*  
    \* Se il tuo \`fanta\_platform\_id\` in \`players\` è già \`int(11) YES\` (nullable), puoi saltare la creazione di questa migrazione.  
    \* Comando: \`php artisan make:migration make\_fanta\_platform\_id\_nullable\_in\_players\_table\_final \--table=players\`  
    \* Contenuto:  
        \`\`\`php  
        \<?php

        use Illuminate\\Database\\Migrations\\Migration;  
        use Illuminate\\Database\\Schema\\Blueprint;  
        use Illuminate\\Support\\Facades\\Schema;

        return new class extends Migration  
        {  
            public function up(): void  
            {  
                Schema::table('players', function (Blueprint $table) {  
                    $table-\>integer('fanta\_platform\_id')-\>nullable()-\>change();  
                });  
            }

            public function down(): void  
            {  
                $table-\>integer('fanta\_platform\_id')-\>nullable(false)-\>change();  
            }  
        };  
        \`\`\`  
         
4\.  \*\*Applica la migrazione: Aggiungi le colonne per le proiezioni e \`api\_football\_data\_id\` alla tabella \`players\`:\*\*  
    \* Comando: \`php artisan make:migration add\_projection\_fields\_and\_api\_id\_to\_players\_table\_final \--table=players\`  
    \* Contenuto:  
        \`\`\`php  
        \<?php

        use Illuminate\\Database\\Migrations\\Migration;  
        use Illuminate\\Database\\Schema\\Blueprint;  
        use Illuminate\\Support\\Facades\\Schema;

        return new class extends Migration  
        {  
            public function up(): void  
            {  
                Schema::table('players', function (Blueprint $table) {  
                    // Aggiungi le colonne di proiezione finali  
                    $table-\>float('avg\_rating\_proj')-\>nullable()-\>after('fvm');  
                    $table-\>float('fanta\_mv\_proj')-\>nullable()-\>after('avg\_rating\_proj');  
                    $table-\>integer('games\_played\_proj')-\>nullable()-\>after('fanta\_mv\_proj');  
                    $table-\>float('total\_fanta\_points\_proj')-\>nullable()-\>after('games\_played\_proj');

                    // Aggiungi api\_football\_data\_id  
                    $table-\>integer('api\_football\_data\_id')-\>nullable()-\>unique()-\>after('fanta\_platform\_id');  
                });  
            }

            public function down(): void  
            {  
                Schema::table('players', function (Blueprint $table) {  
                    $table-\>dropColumn(\[  
                        'avg\_rating\_proj',  
                        'fanta\_mv\_proj',  
                        'games\_played\_proj',  
                        'total\_fanta\_points\_proj',  
                        'api\_football\_data\_id',  
                    \]);  
                });  
            }  
        };  
        \`\`\`  
5\.  \*\*Applica la migrazione: Crea \`player\_fbref\_stats\`\*\*  
    \* Questa migrazione dovrebbe essere quella che hai già: \`2025\_06\_02\_203520\_create\_player\_fbref\_stats\_table.php\`  
    \* Assicurati che la FK \`player\_id\` punti a \`players.id\`.  
6\.  \*\*Applica la migrazione: Altera le colonne numeriche in \`player\_fbref\_stats\` (se non l'hai già fatto)\*\*  
    \* Questa migrazione dovrebbe essere quella che hai già: \`2025\_06\_03\_131121\_alter\_player\_fbref\_stats\_numeric\_columns.php\`  
7\.  \*\*Applica la migrazione: Rimuovi \`player\_id\` (se presente) e aggiungi/assicura \`player\_fanta\_platform\_id\` in \`historical\_player\_stats\` (e altri campi secondo la migrazione \`2025\_05\_26\_131451\`)\*\*  
    \* Questa è la migrazione \`2025\_05\_26\_131451\_create\_historical\_player\_stats\_table.php\`. Assicurati che non abbia \`player\_id\` come FK a \`players.id\` e che abbia \`player\_fanta\_platform\_id\`.  
    \* \*\*VERIFICA MANUALE:\*\* Esegui \`php artisan db:raw "DESCRIBE historical\_player\_stats;"\` e assicurati che \`player\_id\` non esista e \`player\_fanta\_platform\_id\` sì. Se necessario, crea una migrazione apposita per \`dropColumn('player\_id')\`.

\*\*Passi Operativi per la Stagione (dopo aver configurato le migrazioni e il database è pulito):\*\*

1\.  \*\*php artisan migrate:fresh\*\* (per pulire e riapplicare tutte le migrazioni nel nuovo ordine, \*\*ATTENZIONE: CANCELLA TUTTI I DATI\!\*\* Se hai dati importanti, devi fare un backup prima di questa operazione o applicare le migrazioni una ad una).  
2\.  \*\*php artisan db:seed\*\* (per popolare le tabelle base come \`teams\` con \`TeamSeeder\`).  
3\.  \*\*Importa Roster Ufficiale (\`/upload/roster\` UI)\*\*: Popola la tabella \`players\` con i dati base e i \`fanta\_platform\_id\` ufficiali.  
4\.  \*\*Popola Storico Classifiche Squadre:\*\*  
    \* \`php artisan teams:set-active-league \--target-season-start-year=YYYY \--league-code=SA\`  
    \* \`php artisan teams:map-api-ids \--competition=SA\`  
    \* \`php artisan teams:fetch-historical-standings \--all-recent=N \--competition=SA\` (per scaricare da API)  
    \* \`php artisan teams:import-standings-file ...\` (per importare da CSV se necessario).  
5\.  \*\*Calcola Tier Squadre:\*\* \`php artisan teams:update-tiers YYYY-YY\`  
6\.  \*\*Arricchisci Dati Giocatori (date\_of\_birth, etc.):\*\* \`php artisan players:enrich-data\`  
7\.  \*\*Scraping Dati FBRef e Salvataggio Grezzo:\*\* Per ogni squadra e stagione rilevante, esegui:  
    \* \`php artisan fbref:scrape-team "https://fbref.com/it/squadre/id/Statistiche-NomeSquadra" \--team\_id=ID\_SQUADRA\_DB \--season=YYYY \--league="Nome Lega"\`  
    \* Questo salverà in \`player\_fbref\_stats\` e popolerà/aggiornerà \`players.fanta\_platform\_id\` con l'ID interno se non già presente.  
8\.  \*\*Processa Dati FBRef nello Storico Elaborato:\*\*  
    \* \*\*\`php artisan stats:process-fbref-to-historical \--season=YYYY\`\*\* (Questo è il comando da implementare\! Leggerà da \`player\_fbref\_stats\`, applicherà conversioni e scriverà in \`historical\_player\_stats\`).  
9\.  \*\*Importa Altre Statistiche Storiche (manuali, da listoni non FBRef):\*\*  
    \* \`php artisan players:import-advanced-stats ...\` (popola \`historical\_player\_stats\` con conversione)  
    \* \`Importazione da UI (/upload/historical-stats)\` (popola \`historical\_player\_stats\` direttamente)  
10\. \*\*Genera Proiezioni Finali:\*\*  
    \* \*\*\`php artisan players:generate-projections\`\*\* (NUOVO COMANDO: chiamerà il \`ProjectionEngineService\` per tutti i giocatori e salverà i 4 campi \`\_proj\` nella tabella \`players\`).  
11\. \*\*Test Proiezione Singola:\*\* \`php artisan test:projection {playerId}\` (verificherà le proiezioni finali salvate nel player).

\---  
