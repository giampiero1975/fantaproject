Questo documento descrive l'architettura dei dati e il flusso di esecuzione dei comandi per la gestione delle statistiche dei giocatori e la generazione delle proiezioni nel tuo sistema Fantacalcio.

### 1\. Architettura dei Dati (Tabelle del Database)

Comprendere il ruolo di ogni tabella è fondamentale per un flusso di dati coerente.

* **players (Tabella Principale Giocatori)**  
  * **Scopo:** Contiene i dati anagrafici **attuali** dei giocatori, la loro quotazione attuale e le **proiezioni finali per la stagione successiva**.  
  * **Chiavi Importanti:**  
    * id (BIGINT UNSIGNED, PK): ID interno univoco del giocatore nel tuo database.  
    * fanta\_platform\_id (INT, NULLABLE, UNIQUE): ID univoco del giocatore sulla piattaforma Fantacalcio ufficiale (es. Lega Serie A). Se un giocatore è importato solo da Fbref e non ha un ID ufficiale, verrà popolato con il suo id interno per mantenere l'univocità e la collegabilità.  
  * **Campi di Proiezione (OUTPUT del motore di proiezioni):**  
    * avg\_rating\_proj (FLOAT, NULLABLE): Media Voto proiettata per la stagione futura.  
    * fanta\_mv\_proj (FLOAT, NULLABLE): Fantamedia proiettata per la stagione futura.  
    * games\_played\_proj (INT, NULLABLE): Partite giocate proiettate per la stagione futura.  
    * total\_fanta\_points\_proj (FLOAT, NULLABLE): Punti Fantacalcio totali proiettati per la stagione futura.  
  * **NON contiene** proiezioni dettagliate per gol, assist, cartellini, ecc. (quelle sono calcoli intermedi).  
* **teams (Tabella Squadre)**  
  * **Scopo:** Contiene i dati delle squadre di calcio.  
  * **Chiavi Importanti:**  
    * id (BIGINT UNSIGNED, PK): ID interno univoco della squadra.  
    * api\_football\_data\_team\_id (INT, NULLABLE, UNIQUE): ID della squadra sull'API Football-Data.org.  
* **player\_fbref\_stats (Tabella Dati Grezzi FBRef)**  
  * **Scopo:** Memorizza le statistiche grezze dei giocatori così come vengono raschiate da FBRef, per ogni stagione. Questi dati sono ancora nel formato originale di FBRef (es. "90 min", "Reti").  
  * **Chiavi Importanti:**  
    * player\_id (BIGINT UNSIGNED): FK a players.id. Collega la statistica grezza al giocatore nel tuo database.  
    * team\_id (BIGINT UNSIGNED): FK a teams.id. Collega la statistica grezza alla squadra nel tuo database.  
    * season\_year, league\_name, data\_source, ecc.  
* **historical\_player\_stats (Tabella Storico Giocatori Elaborato)**  
  * **Scopo:** Contiene le statistiche storiche dei giocatori, elaborate e convertite in un formato standardizzato, pronte per essere utilizzate dal motore di proiezioni. Questa è la **fonte di dati storica** per le proiezioni.  
  * **Chiavi Importanti:**  
    * player\_fanta\_platform\_id (INT, NULLABLE, MUL): FK a players.fanta\_platform\_id. Questa è la chiave per collegare lo storico al giocatore.  
    * season\_year (INT): Anno della stagione storica.  
  * **Contiene:** games\_played, avg\_rating, fanta\_avg\_rating, goals\_scored, assists, yellow\_cards, red\_cards, own\_goals, penalties\_saved, penalties\_taken, penalties\_scored, penalties\_missed, goals\_conceded, ecc.

### 2\. Componenti del Sistema (Comandi Artisan e Servizi)

* **FbrefScrapingService (Servizio)**  
  * **Funzione:** Si connette a FBRef, raschia le tabelle delle statistiche e restituisce i dati grezzi. **NON normalizza i nomi delle colonne interne dei dati raschiati** (es. "Giocatore", "Età" rimangono così).  
* **ScrapeFbrefTeamStatsCommand (Comando Artisan: fbref:scrape-team)**  
  * **Funzione:** Utilizza FbrefScrapingService per ottenere i dati grezzi.  
  * **Processo:**  
    1. Crea/aggiorna il record del Player nella tabella players.  
    2. **Cruciale:** Se il Player non ha un fanta\_platform\_id (cioè non è stato importato da un listone ufficiale), popola players.fanta\_platform\_id con l'id interno del Player appena creato/trovato. Questo assicura che ogni giocatore abbia un fanta\_platform\_id valido per il linking.  
    3. Salva i dati grezzi raschiati nella tabella player\_fbref\_stats.  
* **ProcessFbrefStatsToHistoricalCommand (Comando Artisan: stats:process-fbref-to-historical)**  
  * **Funzione:** Prende i dati grezzi da player\_fbref\_stats, li elabora (es. calcola statistiche per partita, applica fattori di conversione di lega) e li salva nella tabella historical\_player\_stats.  
  * **Processo:**  
    1. Legge da player\_fbref\_stats.  
    2. Per ogni record, trova il Player corrispondente usando player\_id (che è l'id interno del Player nella tabella players).  
    3. Recupera il fanta\_platform\_id del Player trovato.  
    4. Applica i fattori di conversione definiti in config/projection\_settings.php.  
    5. Salva le statistiche elaborate in historical\_player\_stats, usando player\_fanta\_platform\_id come chiave di collegamento.  
* **ProjectionEngineService (Servizio)**  
  * **Funzione:** Il cuore del motore di proiezioni. Calcola le proiezioni per la stagione futura basandosi sui dati storici.  
  * **Processo:**  
    1. Prende un Player come input.  
    2. Recupera i dati storici rilevanti dalla tabella historical\_player\_stats usando player.fanta\_platform\_id.  
    3. Applica la logica di proiezione (fattori di decadimento, tier squadra, ecc.).  
    4. Calcola i **4 valori di proiezione finali**: avg\_rating\_proj, fanta\_mv\_proj, games\_played\_proj, total\_fanta\_points\_proj.  
    5. Restituisce questi 4 valori.  
* **GeneratePlayerProjectionsCommand (Comando Artisan: players:generate-projections)**  
  * **Funzione:** Inizializza il processo di generazione delle proiezioni.  
  * **Processo:**  
    1. Recupera tutti i Player (o un sottoinsieme filtrato).  
    2. Per ogni Player, chiama ProjectionEngineService::generatePlayerProjections().  
    3. Salva i **4 valori di proiezione finali** restituiti dal servizio direttamente nel record del Player nella tabella players.

### 3\. Flusso di Esecuzione dei Comandi (Ordine e Casi d'Uso)

Segui questi passaggi in ordine. Dopo ogni fase di migrazione, è **CRUCIALE** eseguire php artisan optimize:clear e **verificare manualmente lo schema del database** per assicurarti che le modifiche siano state applicate correttamente.

**FASE 0: Preparazione Iniziale del Database (Migrazioni)**

Queste migrazioni porteranno il tuo schema al punto di partenza corretto.

1. **Risolvi l'errore di sintassi in ScrapeFbrefTeamStatsCommand.php:**  
   * Apri il file app/Console/Commands/ScrapeFbrefTeamStatsCommand.php.  
   * Assicurati che la primissima cosa nel file sia esattamente \<?php (senza spazi o caratteri prima).  
   * Assicurati che subito dopo \<?php ci sia la dichiarazione namespace App\\Console\\Commands; (senza spazi o caratteri tra di loro, a parte un singolo a capo).  
   * Salva il file.  
2. **Esegui il rollback di tutte le migrazioni recenti che hanno toccato players o historical\_player\_stats:**  
   * Esegui php artisan migrate:rollback \--step=1 ripetutamente finché non hai annullato tutte le migrazioni che iniziano con 2025\_06\_. L'obiettivo è tornare allo stato prima delle nostre modifiche complesse.  
   * **VERIFICA MANUALE:** Esegui php artisan db:raw "DESCRIBE players;" e php artisan db:raw "DESCRIBE historical\_player\_stats;".  
     * players: NON deve avere colonne con suffisso \_proj e NON deve avere api\_football\_data\_player\_id. Deve avere fanta\_platform\_id (int, nullable, unique).  
     * historical\_player\_stats: Deve avere player\_fanta\_platform\_id (int, nullable) e NON deve avere player\_id. Deve avere season\_year come varchar, e goals\_scored/assists come int.  
     * **Se lo schema non corrisponde, potresti dover creare migrazioni specifiche per dropColumn() o change() le colonne problematiche manualmente, oppure fare un php artisan migrate:fresh (ATTENZIONE: cancella tutti i dati\!).**  
3. **Applica la migrazione: Rendi fanta\_platform\_id nullable in players (se non lo è già)**  
   * Se il tuo fanta\_platform\_id in players è già int(11) YES (nullable), puoi saltare la creazione di questa migrazione.  
   * Comando: php artisan make:migration make\_fanta\_platform\_id\_nullable\_in\_players\_table\_final \--table=players  
   * Contenuto:  
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
