@extends('layouts.app')

@section('title', 'Dashboard FantaProject')

@section('content')
    <div class="status-panel">
        <h2>Stato del Popolamento Dati</h2>
        <p>Segui i passi in ordine per preparare il tuo sistema per le proiezioni dell'asta.</p>

        <div class="phase-card">
            <h3>1. Popolamento Classifiche Storiche Squadre</h3>
            <p>Stato: {{ $standingsStatus['status'] }} ({{ $standingsStatus['details'] }})</p>
            @if($standingsStatus['status'] !== 'Completato')
                <p>Azione: Importa i file CSV delle classifiche storiche per Serie A e Serie B per almeno 4-5 stagioni. Esegui i comandi Artisan specifici.</p>
                <pre><code>php artisan teams:import-standings-file docs/import\ csv.xlsx\ -\ 2023-24\ A.csv --season-start-year=2023 --league-name="Serie A" --create-missing-teams=true --is-serie-a-league=true</code></pre>
                <p>Ripeti per tutti i file CSV di Serie A e Serie B che possiedi.</p>
            @endif
        </div>

        <div class="phase-card">
            <h3>2. Definizione Squadre Serie A Attive (Stagione 2025-26)</h3>
            <p>Stato: {{ $activeTeamsStatus['status'] }}</p>
            @if($activeTeamsStatus['status'] !== 'Aggiornato')
                <p>Azione: Definisci le squadre che parteciperanno alla Serie A per la prossima stagione.</p>
                <pre><code>php artisan teams:set-active-league --target-season-start-year=2025 --league-code=SA --set-inactive-first=true</code></pre>
            @endif
        </div>

        <div class="phase-card">
            <h3>3. Calcolo Tier Squadre (Stagione 2024-25 per le proiezioni)</h3>
            <p>Stato: {{ $tiersStatus['status'] }}</p>
            @if($tiersStatus['status'] !== 'Calcolato')
                <p>Azione: Calcola i tier di forza delle squadre basandoti sullo storico.</p>
                <pre><code>php artisan teams:update-tiers 2024-25</code></pre>
            @endif
        </div>

        <div class="phase-card">
            <h3>4. Importazione Roster Ufficiale</h3>
            <p>Stato: {{ $rosterStatus['status'] }} (Ultimo import: {{ $rosterStatus['last_import_date'] }})</p>
            @if($rosterStatus['status'] !== 'Importato')
                <p>Azione: Carica il file XLSX del roster ufficiale della prossima stagione (es. Fantacalcio.it).</p>
                <p><a href="{{ route('roster.show') }}">Vai a Caricamento Roster</a></p>
            @endif
        </div>

        <div class="phase-card">
            <h3>5. Arricchimento Dati Giocatori (Età, ID API)</h3>
            <p>Stato: {{ $enrichmentStatus['status'] }} (Giocatori mancanti: {{ $enrichmentStatus['missing_count'] }})</p>
            @if($enrichmentStatus['missing_count'] > 0)
                <p>Azione: Recupera dati anagrafici e ID API per i giocatori.</p>
                <pre><code>php artisan players:enrich-data</code></pre>
            @endif
        </div>

        <div class="phase-card">
            <h3>6. Scraping Dati FBRef Grezzi</h3>
            <p>Stato: {{ $fbrefScrapingStatus['status'] }} (Squadre/stagioni raschiate: {{ $fbrefScrapingStatus['scraped_count'] }})</p>
            @if($fbrefScrapingStatus['status'] !== 'Completato')
                <p>Azione: Raschia le statistiche dettagliate da FBRef per le squadre e stagioni di interesse (es. neopromosse).</p>
                <pre><code>php artisan fbref:scrape-team "URL_FBREF" --team_id=ID_SQUADRA --season=YYYY --league="Nome Lega"</code></pre>
                <p>Ripeti per ogni squadra e stagione.</p>
            @endif
        </div>

        <div class="phase-card">
            <h3>7. Processamento Dati FBRef -> Storico Elaborato</h3>
            <p>Stato: {{ $fbrefProcessingStatus['status'] }}</p>
            @if($fbrefProcessingStatus['status'] !== 'Processato')
                <p>Azione: Trasforma i dati grezzi di FBRef in uno storico utilizzabile per le proiezioni. (Comando da implementare)</p>
                <pre><code>php artisan stats:process-fbref-to-historical --season=2025</code></pre>
            @endif
        </div>

        <div class="phase-card">
            <h3>8. Importazione Altre Statistiche Storiche</h3>
            <p>Stato: {{ $otherHistoricalStatsStatus['status'] }} (Ultimo import: {{ $otherHistoricalStatsStatus['last_import_date'] }})</p>
            @if($otherHistoricalStatsStatus['status'] !== 'Completato')
                <p>Azione: Carica eventuali altri file di statistiche storiche.</p>
                <p><a href="{{ route('historical_stats.show_upload_form') }}">Vai a Caricamento Statistiche Storiche</a></p>
            @endif
        </div>

        <div class="phase-card">
            <h3>9. Generazione Proiezioni Finali</h3>
            <p>Stato: {{ $projectionsStatus['status'] }}</p>
            @if($projectionsStatus['status'] !== 'Generate')
                <p>Azione: Calcola e salva le proiezioni finali per tutti i giocatori. (Comando da implementare)</p>
                <pre><code>php artisan players:generate-projections</code></pre>
            @endif
        </div>

        {{-- Qui potresti aggiungere un link per testare una proiezione --}}
        @if($projectionsStatus['status'] === 'Generate')
            <div class="phase-card">
                <h3>10. Verifica Proiezioni</h3>
                <p>Le proiezioni finali sono pronte nella tabella 'players'.</p>
                <p>Azione: Testa una proiezione per un singolo giocatore via Artisan:</p>
                <pre><code>php artisan test:projection ID_GIOCATORE</code></pre>
            </div>
        @endif

    </div>
@endsection