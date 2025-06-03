**Documento di Flusso e Ragionamento: Integrazione Dati FBRef con Storico Giocatori e Proiezioni**

Versione: 1.0  
Data: 3 Giugno 2025  
Scopo: Definire il processo logico e i passaggi operativi per l'acquisizione delle statistiche grezze da FBRef, la loro integrazione con i dati anagrafici dei giocatori, e la successiva "traduzione" per alimentare il motore di proiezioni. Questo documento chiarirà la relazione tra le tabelle teams, players, player\_fbref\_stats, e historical\_player\_stats.

---

**1\. Contesto e Obiettivo Generale**

Il sistema mira a fornire proiezioni accurate per i giocatori, anche quelli con storico limitato o proveniente da leghe diverse dalla Serie A. Per fare ciò, si utilizzano dati provenienti da diverse fonti:

* **Listone Ufficiale (via MainRosterImport):** Fonte primaria per l'anagrafica base dei giocatori (players table) e le quotazioni iniziali.  
* **Storico Ufficiale (via TuttiHistoricalStatsImport):** Dati storici di Fantacalcio standard (historical\_player\_stats table).  
* **Dati FBRef (via FbrefScrapingService e ScrapeFbrefTeamStatsCommand):** Statistiche dettagliate e grezze provenienti da una fonte esterna, utili per arricchire lo storico, specialmente per neopromossi o giocatori con storico in leghe minori.  
* **Dati Custom/Manuali (via PlayerSeasonStatsImport):** Integrazione di statistiche arricchite manualmente, che possono includere dati Fbref o altre fonti.

L'obiettivo è creare un flusso che permetta di sfruttare i dati Fbref per migliorare le proiezioni, gestendo le specificità di lega e i giocatori non ancora anagrafati.

---

**2\. Le Tabelle Coinvolte e il loro Ruolo**

* **teams (Squadre):** Contiene l'anagrafica delle squadre. È il punto di riferimento per collegare giocatori e statistiche alla squadra di appartenenza. Ogni squadra deve esistere qui con un ID univoco prima di poterle associare a giocatori o statistiche.  
  * **Popolamento:** Tipicamente tramite seeders, import manuale, o API dedicate.  
* **players (Giocatori):** Contiene l'anagrafica dei giocatori (nome, ruolo fantacalcio, squadra corrente, fanta\_platform\_id, initial\_quotation, etc.). È la tabella master per i giocatori nel nostro sistema.  
  * **Popolamento:** MainRosterImport.php (dal listone ufficiale). Il comando fbref:scrape-team può anche creare un record Player se non trova un giocatore con il nome raschiato.  
* **player\_fbref\_stats (Statistiche FBRef Grezze):** Questa è la **nuova tabella** dove vengono salvate le statistiche direttamente raschiate da FBRef, così come appaiono sul sito. Contiene statistiche dettagliate per stagione e lega, collegate tramite player\_id e team\_id.  
  * **Popolamento:** ScrapeFbrefTeamStatsCommand.php.  
  * **Natura:** Dati grezzi, non ancora normalizzati o "tradotti" per il motore di proiezioni. Servono come archivio fedele della fonte.  
* **historical\_player\_stats (Storico Giocatori Tradotto/Ponderato):** Contiene statistiche storiche dei giocatori, **già elaborate o "tradotte"** per essere direttamente utilizzabili dal ProjectionEngineService. Include la colonna league\_name per tracciare la lega di origine e applicare i fattori di conversione.  
  * **Popolamento:** TuttiHistoricalStatsImport.php (storico ufficiale), PlayerSeasonStatsImport.php (import avanzato/manuale).  
  * **Natura:** Dati pronti per il consumo da parte del Projection Engine.

---

**3\. Flusso di Lavoro Dettagliato: Dall'Anagrafica alla Proiezione**

1. **Popolamento Anagrafica Base (Players & Teams)**  
   * **Obiettivo:** Avere un database di squadre e giocatori con le informazioni fondamentali (id, name, role, team\_id, fanta\_platform\_id per i giocatori già nel listone ufficiale).  
   * **Processo:**  
     * **php artisan db:seed \--class=TeamSeeder** (o simile): Popola la tabella teams con i nomi e gli ID delle squadre.  
     * **php artisan import:main-roster "percorso/listone.xlsx"**: Popola la tabella players con i dati anagrafici dal listone ufficiale, inclusi fanta\_platform\_id e initial\_quotation. Questo è il passo più importante per anagrafare i giocatori principali.  
   * **Considerazioni:** I giocatori "nuovi" (es. neopromossi, nuovi acquisti dall'estero) potrebbero non essere ancora presenti o non avere un fanta\_platform\_id ufficiale se non sono stati inclusi nel listone più recente. Il comando di scraping dovrà gestire questo.  
2. **Scraping Dati FBRef e Salvataggio Grezzo**  
   * **Comando:** php artisan fbref:scrape-team {url} \--team\_id={ID} \--season={ANNO} \--league={LEGA}  
   * **Logica:**  
     * Riceve l'URL della squadra Fbref, l'ID della squadra nel DB (teams.id), l'anno di inizio stagione e il nome della lega.  
     * Utilizza FbrefScrapingService per scaricare e parsare le tabelle (es. statistiche\_ordinarie, statistiche\_portiere).  
     * Per ogni riga di statistica (ogni giocatore):  
       * **Ricerca/Creazione Giocatore:** Cerca il giocatore nella tabella players per name. Se non trovato, lo crea. Questo garantisce che ogni statistica sia collegata a un player\_id valido. **Nota:** Se il nome di Fbref è diverso da quello nel listone, verrà creato un nuovo record Player, potenzialmente duplicando il giocatore. Questo è un punto di affinamento futuro (es. matching per data di nascita/nazionalità o un fbref\_player\_id dedicato).  
       * **Salvaggio Statistiche Grezze:** Mappa i dati raschiati alle colonne della tabella player\_fbref\_stats e usa PlayerFbrefStat::updateOrCreate() per salvare o aggiornare il record.  
   * **Output:** Dati dettagliati (gol, assist, xG, tackles, ecc.) per ogni giocatore, squadra, stagione e lega, archiviati fedelmente in player\_fbref\_stats.  
3. **Processo di "Traduzione" e Popolamento dello Storico per Proiezioni**  
   * **Obiettivo:** Trasformare le statistiche grezze da player\_fbref\_stats (e altre fonti) in un formato omogeneo e "tradotto" (historical\_player\_stats) che il ProjectionEngineService possa consumare direttamente, applicando i fattori di conversione di lega.  
   * **Approcci Possibili (come discusso):**  
     * **Opzione A (Consigliata: Comando Dedicato di Trasformazione):**  
       * **Comando Proposto:** php artisan stats:process-fbref-to-historical \--season={ANNO} (nuovo comando da creare).  
       * **Logica:**  
         * Questo comando itererebbe sui record di player\_fbref\_stats per una data stagione/lega.  
         * Per ogni record, estrarrebbe le statistiche rilevanti (es. goals, assists, minutes\_played, ecc.).  
         * Identificherebbe la league\_name del record.  
         * Recupererebbe i player\_stats\_league\_conversion\_factors da config/projection\_settings.php.  
         * Applicherebbe i fattori di conversione a *ogni singola statistica* (es. goals\_fbref \* factor\_goals\_serie\_b\_to\_a).  
         * Creerebbe o aggiornerebbe un record in historical\_player\_stats, riassumendo le statistiche "tradotte" e convertendole in valori per partita (goals\_per\_game, avg\_rating, ecc.).  
         * Questo record in historical\_player\_stats sarebbe collegato allo stesso player\_id e team\_id.  
       * **Vantaggi:** historical\_player\_stats rimane la singola fonte aggregata e pre-elaborata per le proiezioni. La logica di conversione è centralizzata e riutilizzabile. player\_fbref\_stats mantiene la sua funzione di archivio grezzo.  
     * **Opzione B (Alternativa: Integrazione Diretta nel ProjectionEngineService):**  
       * **Modifica:** App\\Services\\ProjectionEngineService.php.  
       * **Logica:** Il ProjectionEngineService sarebbe modificato per:  
         * Prima tentare di recuperare le statistiche da historical\_player\_stats (che potrebbero provenire da TuttiHistoricalStatsImport o PlayerSeasonStatsImport).  
         * Se lo storico è insufficiente o non esiste, allora andrebbe a leggere direttamente da player\_fbref\_stats (per la stagione e lega target).  
         * A quel punto, il ProjectionEngineService dovrebbe anche recuperare i fattori di conversione e applicarli al momento della ponderazione delle statistiche di Fbref.  
       * **Svantaggi:** Aumenta la complessità del ProjectionEngineService, che dovrebbe gestire logiche di acquisizione e conversione di dati grezzi da diverse fonti. Rischia di duplicare la logica.  
   * **Raccomandazione:** Si consiglia fortemente l'**Opzione A**. Mantiene una chiara separazione delle responsabilità: lo scraping grezzo va in player\_fbref\_stats, la trasformazione e normalizzazione (con fattori di lega) avviene in un passo intermedio dedicato, che popola historical\_player\_stats (la "verità" per le proiezioni).  
4. **Generazione delle Proiezioni**  
   * **Comando:** php artisan test:projection {playerId}  
   * **Logica:**  
     * Il ProjectionEngineService accede ai dati da historical\_player\_stats.  
     * Questi dati sono già "tradotti" per lega e pronti per essere ponderati per l'età, il ruolo e il tier della squadra.  
     * La logica per la quotazione e il tier della squadra (come descritto nel "Memo Tecnico: Implementazione Proiezioni Giocatori Basate su Quotazione e Tier Squadra") verrà applicata *dopo* aver calcolato le medie ponderate dallo storico (o come fallback se lo storico è assente).

---

**4\. Riepilogo dei Dati e Flussi**

\+--------------------------+    \+------------------------+  
|    Fonte Dati Listone    |    |   Fonte Dati FBRef     |  
| (es. listone Fantacalcio)|    | (fbref.com web scraping)|  
\+--------------------------+    \+------------------------+  
             |                           |  
             V                           V  
\+---------------------------------------------------+  
| 1\. Popolamento Anagrafica Base e Dati Grezzi      |  
|    \- \`php artisan import:main-roster\`             |  
|    \- \`php artisan fbref:scrape-team\`              |  
\+---------------------------------------------------+  
             |                                    |  
             V                                    V  
\+--------------------------+         \+--------------------------+  
|      \`players\` Table     |         |  \`player\_fbref\_stats\`    |  
| (Anagrafica giocatori)   |         | (Statistiche FBRef Grezze)|  
| \- ID, Nome, Ruolo, TeamID|         | \- Collegate a players.id |  
| \- initial\_quotation      |         | \- Dettaglio stagione/lega |  
\+--------------------------+         \+--------------------------+  
             |                                    |  
             |  (Opzionale: Import manuale / PlayerSeasonStatsImport per altri storici)  
             |                                    |  
             V                                    V  
\+---------------------------------------------------+  
| 2\. Trasformazione e Normalizzazione Storico       |  
|    \- \`php artisan stats:process-fbref-to-historical\` (NUOVO COMANDO) |  
|    \- Applica \`player\_stats\_league\_conversion\_factors\` (da config) |  
\+---------------------------------------------------+  
             |  
             V  
\+--------------------------+  
| \`historical\_player\_stats\`|  
| (Storico Tradotto/Ponderato per Proiezioni)      |  
| \- Statistiche PER PARTITA, MV, ecc.             |  
| \- league\_name (per fattore di conversione)      |  
\+--------------------------+  
             |  
             V  
\+--------------------------+  
| 3\. Motore di Proiezione  |  
|    \`App\\Services\\ProjectionEngineService.php\`    |  
|    \- Legge da \`historical\_player\_stats\`          |  
|    \- Applica curve età, tier squadra, quotazione  |  
\+--------------------------+  
             |  
             V  
\+--------------------------+  
|    Proiezioni Finali     |  
\+--------------------------+

---

Questo flusso di ragionamento ti fornisce una roadmap chiara per l'implementazione e la gestione dei dati.

Il prossimo passo pratico sarebbe creare il nuovo comando stats:process-fbref-to-historical (o un nome simile) e implementare la logica di trasformazione e salvataggio in historical\_player\_stats, tenendo conto dei fattori di conversione per lega.

Ti trovi d'accordo con questo flusso?