# FantaProject - Documentazione del Lavoro di Sviluppo e Test

Questo documento riassume i principali miglioramenti, le nuove funzionalità e le logiche implementate per rendere il flusso di dati di FantaProject robusto, efficiente e affidabile.

## 1. Architettura del Flusso Dati (Versione Finale)

Abbiamo definito un'architettura a più fasi, chiara e sequenziale, per la preparazione dei dati. Ogni fase ha uno scopo preciso e prepara il terreno per quella successiva.

1.  **Setup Squadre di Base (`teams:set-active-league`)**: Popola il database con le squadre di una data lega/stagione, arricchendole con l'ID API corretto. Questo è il prerequisito per tutte le operazioni basate sulle squadre.
2.  **Popolamento Storico (`teams:fetch-historical-standings`)**: Scarica le classifiche storiche, necessarie per il calcolo della forza delle squadre.
3.  **Caricamento Roster Ufficiale (via UI)**: È la **fonte di verità primaria** per i giocatori. Importa il "listone" ufficiale con ruoli e quotazioni, creando la base dei giocatori per l'asta.
4.  **Definizione Squadre Asta (`teams:set-active-league`)**: Imposta il flag `serie_a_team=true` per le 20 squadre che parteciperanno all'asta della nuova stagione.
5.  **Calcolo Tier Squadre (`teams:update-tiers`)**: Assegna un livello di forza (Tier 1-5) a ogni squadra di Serie A, basandosi sui dati storici.
6.  **Sincronizzazione Rose API (`players:sync-from-active-teams`)**: **(NUOVA FUNZIONALITÀ CHIAVE)**. Questo comando scorre le rose delle squadre di Serie A come riportate dall'API per:
    * **Creare** giocatori mancanti (es. dalle neopromosse).
    * **Aggiornare** i giocatori esistenti, correggendo la squadra di appartenenza in caso di trasferimenti.
7.  **Arricchimento Dati (`players:enrich-data`)**: Esegue un'operazione di "pulizia finale" su tutti i giocatori, cercando di riempire dati mancanti come `api_football_data_id` e `date_of_birth`.

## 2. Riepilogo dei Comandi Principali e Logiche Implementate

### Comando: `teams:set-active-league`

* **Scopo**: Popolare e aggiornare le squadre nel database usando l'API come fonte.
* **Logica Chiave Implementata**: È stata implementata una robusta **logica di matching a cascata** per prevenire la creazione di squadre duplicate:
    1.  Il sistema cerca prima una corrispondenza esatta tramite `api_football_data_id`.
    2.  Se fallisce, cerca una corrispondenza esatta tramite `tla` (Three Letter Acronym).
    3.  Se fallisce ancora, esegue una ricerca "fuzzy" (`LIKE`) sui campi `name` e `short_name`.
    4.  Solo se tutti i tentativi falliscono, viene creata una nuova squadra.
* **Caso d'Uso**: `php artisan teams:set-active-league --target-season-start-year=2025` per preparare le squadre per la stagione 2025/26.

### Comando: `players:sync-from-active-teams` (Nuovo)

* **Scopo**: Sincronizzare le rose delle squadre di Serie A attive, agendo come complemento al roster ufficiale. È fondamentale per aggiungere giocatori delle neopromosse e per gestire i trasferimenti di mercato.
* **Logica Chiave Implementata**:
    * **Matching Avanzato**: Utilizza una logica a cascata (simile a quella delle squadre) per trovare i giocatori ed evitare duplicati: cerca per `api_football_data_id`, poi per nome esatto, e infine per cognome (`LIKE`).
    * **Aggiornamento Efficiente**: Esegue una query di `UPDATE` sul database solo se i dati del giocatore sono effettivamente cambiati, grazie all'uso dei metodi `fill()` e `isDirty()` di Eloquent.
    * **Gestione Rate Limit**: Include una pausa di 7 secondi (`sleep(7)`) tra una squadra e l'altra per rispettare i limiti dell'API.
    * **Coerenza Dati**: Inserisce lo `short_name` della squadra nel campo `team_name` del giocatore, per uniformità con l'import del roster.
* **Caso d'Uso**: `php artisan players:sync-from-active-teams` per sincronizzare le rose della stagione corrente. `php artisan players:sync-from-active-teams --season=2024` per testare la logica su dati storici certi.

## 3. Modifiche Strutturali al Database

Per supportare le nuove logiche, sono state apportate modifiche cruciali allo schema del database.

* **Aggiunta di `team_id` a `players`**: È stata aggiunta una chiave esterna `team_id` alla tabella `players`, che la collega alla tabella `teams`. Questa è una modifica fondamentale che stabilisce una corretta relazione "uno a molti" (un team ha molti giocatori) e abilita query più semplici e potenti.
* **Campi Resi `nullable`**: Le colonne `role` e `initial_quotation` nella tabella `players` sono state rese `nullable`. Questo permette al sistema di creare giocatori tramite API (che non hanno questi dati) senza generare errori. I valori verranno poi popolati dall'import del roster ufficiale.

## 4. Dashboard di Progetto

Il `DashboardController` e la vista `dashboard.blade.php` sono stati aggiornati per:
* Risolvere numerosi bug e incongruenze logiche.
* Aggiungere la card per la nuova **Fase 6: Sincronizzazione Rose Serie A**.
* Riorganizzare le fasi in un ordine più logico e sequenziale.