@extends('layouts.app')

@section('title', 'Dashboard FantaProject')

@section('content')
    <div class="status-panel">
        <h2 class="dashboard-title display-6">Stato Popolamento Dati</h2>
        <p class="lead mb-4">Segui i passi in ordine per preparare il tuo sistema per le proiezioni dell'asta.</p>

        @php
            // Definizione delle stagioni dinamiche per i comandi e i titoli
            $currentYear = date('Y');
            $lastCompletedSeasonStartYear = $currentYear - 1; // Ultima stagione completata (es. per storico)
            $currentSeasonStartYear = $currentYear;     // Stagione corrente o che sta per iniziare (es. per roster)
            $nextAuctionSeasonStartYear = $currentYear +1; // Prossima stagione d'asta

            $lastCompletedSeasonDisplay = $lastCompletedSeasonStartYear . '-' . substr($lastCompletedSeasonStartYear + 1, -2);
            $currentSeasonDisplay = $currentSeasonStartYear . '-' . substr($currentSeasonStartYear + 1, -2);
            $nextAuctionSeasonDisplay = $nextAuctionSeasonStartYear . '-' . substr($nextAuctionSeasonStartYear + 2, -2); // Es. 2025-26

            // Array che definisce tutte le fasi della dashboard
            $phases = [
                1 => [ // NUOVA FASE 1 (Ex Fase 2, ma più generale)
                    'data' => $activeTeamsStatus, // Assumiamo che $activeTeamsStatus possa riflettere lo stato generale del popolamento squadre via API
                    'title_prefix' => '1.',
                    'title' => 'Popolamento Squadre Base e ID API (da football-data.org)',
                    'action_text' => "Popola/aggiorna le squadre e i loro ID API da football-data.org. È cruciale per poter scaricare le classifiche storiche via API.",
                    'artisan_commands' => [
                        "php artisan teams:set-active-league --target-season-start-year={$lastCompletedSeasonStartYear} --league-code=SA",
                        "php artisan teams:set-active-league --target-season-start-year={$currentSeasonStartYear} --league-code=SA"
                        ],
                    'artisan_notes' => "Esegui per la stagione corrente e per le stagioni passate di cui vuoi lo storico (es. {$lastCompletedSeasonStartYear}, " . ($lastCompletedSeasonStartYear - 1) . ", ecc.). Questo comando crea le squadre se non esistono e popola `api_football_data_id`.",
                    'artisan_tooltip' => "<strong>teams:set-active-league:</strong> Popola/aggiorna le squadre per una data stagione da football-data.org, impostando il loro ID API.<br>Opzioni:<br> - <code>--target-season-start-year=YYYY</code> (Obbligatorio): Anno inizio stagione target.<br> - <code>--league-code=CODICE</code> (Default: SA): Codice della lega.<br> - <code>--set-inactive-first=true|false</code> (Default: true): Disattiva tutte le squadre prima di attivare quelle della stagione target."
                ],
                2 => [ // EX FASE 1
                    'data' => $standingsStatus,
                    'title_prefix' => '2.',
                    'title' => 'Popolamento Dati Storici Classifiche (API/CSV)',
                    'action_text' => 'Importa le classifiche storiche. Se usi l\'API, assicurati che le squadre siano state popolate con gli ID API (Fase 1).',
                    'artisan_commands' => [
                        "php artisan teams:fetch-historical-standings --season={$lastCompletedSeasonStartYear} --competition=SA",
                        "php artisan teams:import-standings-file \"storage/app/import/classifica_serie_a_2022-23.csv\" --season-start-year=2022 --league-name=\"Serie A\" --create-missing-teams=true --is-serie-a-league=true"
                    ],
                    'artisan_notes' => "Il primo comando scarica via API (es. per stagione {$lastCompletedSeasonDisplay}). Ripeti per altre stagioni/leghe. Il secondo importa da CSV (sostituisci placeholder).",
                    'artisan_tooltip' => "<strong>teams:fetch-historical-standings:</strong> Scarica classifiche storiche via API.<br>Opzioni:<br> - <code>--season=YYYY</code>: Anno inizio stagione specifico.<br> - <code>--all-recent=N</code>: Ultime N stagioni.<br> - <code>--competition=CODICE</code> (Default: SA): Codice lega.<br><br><strong>teams:import-standings-file:</strong> Importa classifiche da CSV.<br>Args: <code>filepath</code>, <code>--season-start-year</code>, etc."
                ],
                3 => [ // EX FASE 4
                    'data' => $rosterStatus,
                    'title_prefix' => '3.',
                    'title' => 'Importazione Roster Ufficiale (Stagione ' . $currentSeasonDisplay . ')',
                    'action_text' => 'Carica il file XLSX del roster ufficiale per la stagione corrente (es. Fantacalcio.it). Questo popola giocatori e squadre con i loro ID piattaforma.',
                    'route_name' => 'roster.show',
                    'route_text' => 'Vai a Caricamento Roster',
                    'icon_action_route' => 'fa-upload',
                    'artisan_tooltip' => "Operazione via UI. Crea/aggiorna giocatori e squadre, mappa 'fanta_platform_id'."
                ],
                4 => [ // EX FASE 2, ma ora specifico per l'ASTA
                    'data' => $activeTeamsStatus, // Potrebbe servire uno status dedicato se questo si riferisce a un diverso target year
                    'title_prefix' => '4.',
                    'title' => 'Definizione Squadre Serie A Attive (per Asta ' . $nextAuctionSeasonDisplay . ')',
                    'action_text' => 'Definisci le squadre che parteciperanno specificamente alla prossima asta di Serie A.',
                    'artisan_commands' => ["php artisan teams:set-active-league --target-season-start-year=" . $nextAuctionSeasonStartYear . " --league-code=SA --set-inactive-first=true"],
                    'artisan_tooltip' => "<strong>teams:set-active-league:</strong> Imposta le squadre per la stagione dell'asta, marcandole come 'serie_a_team = true'."
                ],
                5 => [ // EX FASE 3
                    'data' => $tiersStatus,
                    'title_prefix' => '5.',
                    'title' => 'Calcolo Tier Squadre (per Proiezioni ' . $currentSeasonDisplay . ')',
                    'action_text' => 'Calcola i tier di forza delle squadre attive, usati nelle proiezioni.',
                    'artisan_commands' => ["php artisan teams:update-tiers " . $currentSeasonDisplay],
                    'artisan_tooltip' => "<strong>teams:update-tiers:</strong> Ricalcola i tier per la stagione specificata.<br>Args: <code>targetSeasonYear</code> (es. " . $currentSeasonDisplay .")."
                ],
                6 => [ // EX FASE 5
                    'data' => $enrichmentStatus,
                    'title_prefix' => '6.',
                    'title' => 'Dettagli Giocatori da API',
                    'action_text' => 'Recupera dati anagrafici (data di nascita, posizione dettagliata) e ID API per i giocatori presenti nel roster.',
                    'artisan_commands' => ["php artisan players:enrich-data"],
                    'artisan_tooltip' => "<strong>players:enrich-data:</strong> Arricchisce i dati dei giocatori da API esterna (football-data.org).<br>Opzioni:<br> - <code>--player_id=all|ID_DB</code> (Default: all).<br> - <code>--player_name=NOME</code>.<br> - <code>--delay=SECONDI</code> (Default: 6)."
                ],
                7 => [ // EX FASE 6
                    'data' => $fbrefScrapingStatus,
                    'title_prefix' => '7.',
                    'title' => 'Scraping Dati FBRef Grezzi',
                    'action_text' => 'Raschia le statistiche dettagliate da FBRef per squadre/stagioni di interesse.',
                    'artisan_commands' => ["php artisan fbref:scrape-team \"URL_FBREF_SQUADRA\" --team_id=ID_SQUADRA_DB --season=YYYY --league=\"Nome Lega\""],
                    'artisan_notes' => "Sostituisci i placeholder. Utile per dati storici dettagliati.",
                    'artisan_tooltip' => "<strong>fbref:scrape-team:</strong> Scarica statistiche da una pagina squadra di FBRef.<br>Args: <code>url</code>.<br>Opzioni: <code>--team_id</code>, <code>--season</code>, <code>--league</code> (tutti obbligatori per un corretto salvataggio)."
                ],
                8 => [ // EX FASE 7
                    'data' => $fbrefProcessingStatus,
                    'title_prefix' => '8.',
                    'title' => 'Processamento Dati FBRef -> Storico Elaborato',
                    'action_text' => 'Trasforma i dati grezzi di FBRef in uno storico utilizzabile per le proiezioni.',
                    'artisan_commands' => ["php artisan stats:process-fbref-to-historical --season=" . $lastCompletedSeasonStartYear ],
                    'artisan_notes' => "Questo comando è DA IMPLEMENTARE. Processerà i dati FBRef.",
                    'artisan_tooltip' => "<strong>stats:process-fbref-to-historical:</strong> (DA IMPLEMENTARE) Processa dati FBRef grezzi e li salva in 'historical_player_stats'.<br>Opzioni: <code>--season</code>, <code>--player_id</code>, <code>--overwrite</code>."
                ],
                9 => [ // EX FASE 8
                    'data' => $otherHistoricalStatsStatus,
                    'title_prefix' => '9.',
                    'title' => 'Importazione Altre Statistiche Storiche',
                    'action_text' => 'Carica eventuali altri file di statistiche storiche (es. XLSX standard o CSV avanzati).',
                    'route_name' => 'historical_stats.show_upload_form',
                    'route_text' => 'Vai a Caricamento Statistiche (XLSX Standard)',
                    'icon_action_route' => 'fa-upload',
                    'artisan_commands' => ["php artisan players:import-advanced-stats path/to/your/advanced_stats.csv --league=\"Serie B\""],
                    'artisan_notes' => "Per file CSV/XLSX con colonna 'NomeLega', usa il comando Artisan.",
                    'artisan_tooltip' => "<strong>players:import-advanced-stats:</strong> Importa storico giocatori da file CSV/XLSX con lega specificata per stagione.<br>Args: <code>filepath</code>.<br>Opzioni: <code>--league</code> (fallback)."
                ],
                10 => [ // EX FASE 9
                    'data' => $projectionsStatus,
                    'title_prefix' => '10.',
                    'title' => 'Generazione Proiezioni Finali (Stagione ' . $currentSeasonDisplay . ')',
                    'action_text' => 'Calcola e salva le proiezioni finali per tutti i giocatori.',
                    'artisan_commands' => ["php artisan players:generate-projections"],
                    'artisan_notes' => "Questo comando è DA IMPLEMENTARE.",
                    'artisan_tooltip' => "<strong>players:generate-projections:</strong> (DA IMPLEMENTARE) Genera proiezioni finali e le salva in 'players'."
                ],
                11 => [ // EX FASE 10
                    'data' => $projectionsStatus, // Usa lo stesso status delle proiezioni
                    'title_prefix' => '11.',
                    'title' => 'Verifica Proiezioni',
                    'action_text' => "Testa una proiezione per un singolo giocatore via Artisan:",
                    'artisan_commands' => ["php artisan test:projection ID_GIOCATORE_FANTACALCIO"],
                    'artisan_notes' => "Sostituisci ID_GIOCATORE_FANTACALCIO con il fanta_platform_id del giocatore.",
                    'artisan_tooltip' => "<strong>test:projection:</strong> Testa il motore di proiezione per un singolo giocatore.<br>Args: <code>playerId</code> (fanta_platform_id)."
                ],
            ];
        @endphp

        <div class="row">
        @foreach($phases as $phaseNumber => $phase)
            @php
                // Determina se l'azione deve essere mostrata per la card corrente
                $showActionCard = $phase['show_action_override'] ?? ($phase['data']['showAction'] ?? true);

                // Logiche specifiche per sovrascrivere $showActionCard
                if ($phaseNumber == 6) { // Arricchimento Dati Giocatori (Nuova numerazione)
                     $showActionCard = (($phase['data']['missing_count'] ?? 0) > 0 && (\App\Models\Player::count() > 0) );
                } elseif ($phaseNumber == 8) { // Processamento Dati FBRef (Nuova numerazione)
                    $showActionCard = (\App\Models\PlayerFbrefStat::count() > 0) &&
                                      isset($phase['data']['status_title']) &&
                                      !in_array($phase['data']['status_title'], ['Processato', 'Processato Completamente', 'Non applicabile']);
                } elseif ($phaseNumber == 10) { // Generazione Proiezioni (Nuova numerazione)
                    $showActionCard = isset($phase['data']['status_title']) &&
                                      $phase['data']['status_title'] !== 'Generate' &&
                                      \App\Models\Player::count() > 0 &&
                                      \App\Models\HistoricalPlayerStat::count() > 0;
                    if (\App\Models\Player::count() === 0 || \App\Models\HistoricalPlayerStat::count() === 0) {
                         $showActionCard = false;
                    }
                } elseif ($phaseNumber == 11) { // Verifica Proiezioni (Nuova numerazione)
                    $showActionCard = isset($phase['data']['status_title']) &&
                                     ($phase['data']['status_title'] === 'Generate' || $phase['data']['status_title'] === 'Parzialmente generate');
                }
            @endphp

            <div class="col-lg-6 col-md-12">
                <div class="card phase-card {{ $phase['data']['cardBorderClass'] ?? 'border-secondary' }} mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center {{ $phase['data']['headerBgClass'] ?? 'bg-light' }}">
                        <h5 class="mb-0 card-title">
                            <i class="{{ $phase['data']['iconClass'] ?? 'fas fa-question-circle text-secondary' }} me-2"></i>{{ $phase['title_prefix'] }} {{ $phase['title'] }}
                        </h5>
                        <span class="badge rounded-pill {{ $phase['data']['badgeClass'] ?? 'bg-secondary' }}">{{ $phase['data']['status_title'] ?? 'N/D' }}</span>
                    </div>
                    <div class="card-body">
                        @if(isset($phase['data']['details']))
                            <p class="mb-1"><strong>Dettagli:</strong> {{ $phase['data']['details'] }}</p>
                        @endif

                        @if(isset($phase['data']['last_import_date']) && $phase['data']['last_import_date'] !== 'N/A')
                            <p class="mb-2"><small class="text-muted">Ultimo Import: {{ $phase['data']['last_import_date'] }}</small></p>
                        @elseif(isset($phase['data']['last_run_date']) && $phase['data']['last_run_date'] !== 'N/A')
                            <p class="mb-2"><small class="text-muted">Ultima Esecuzione: {{ $phase['data']['last_run_date'] }}</small></p>
                        @elseif(isset($phase['data']['last_scrape_date']) && $phase['data']['last_scrape_date'] !== 'N/A')
                             <p class="mb-2"><small class="text-muted">Ultimo Scrape: {{ $phase['data']['last_scrape_date'] }}</small></p>
                        @endif

                        @if($showActionCard)
                            <div class="action-suggestion mt-2 pt-2 {{ (isset($phase['data']['last_import_date']) && $phase['data']['last_import_date'] !== 'N/A') || (isset($phase['data']['last_run_date']) && $phase['data']['last_run_date'] !== 'N/A') || (isset($phase['data']['last_scrape_date']) && $phase['data']['last_scrape_date'] !== 'N/A') || (isset($phase['data']['details']) && !empty($phase['data']['details'])) ? 'border-top' : '' }}">
                                <p class="mb-1"><strong>Azione Suggerita:</strong></p>
                                <p>{{ $phase['action_text'] }}</p>
                                @if(isset($phase['route_name']))
                                    <a href="{{ route($phase['route_name']) }}" class="btn btn-primary btn-sm">
                                        <i class="fas {{ $phase['icon_action_route'] ?? 'fa-arrow-right' }} me-1"></i> {{ $phase['route_text'] }}
                                    </a>
                                @endif
                                @if(isset($phase['artisan_commands']))
                                    <button type="button" class="btn btn-outline-info btn-sm" data-bs-toggle="collapse" data-bs-target="#artisanCollapse{{$phaseNumber}}">
                                        <i class="fas fa-terminal me-1"></i> Comandi Artisan
                                    </button>
                                    @if(isset($phase['artisan_tooltip']) && !empty($phase['artisan_tooltip']))
                                    <span class="ms-1" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true" title="{!! $phase['artisan_tooltip'] !!}">
                                        <i class="fas fa-question-circle text-muted"></i>
                                    </span>
                                    @endif
                                    <div id="artisanCollapse{{$phaseNumber}}" class="collapse mt-2">
                                        @foreach($phase['artisan_commands'] as $command)
                                        <pre class="mb-1 p-2 rounded" style="background-color: #f8f9fa; border: 1px solid #dee2e6; font-size: 0.8rem;"><code>{{ $command }}</code></pre>
                                        @endforeach
                                        @if(isset($phase['artisan_notes']) && !empty($phase['artisan_notes']))
                                            <small class="d-block mt-1 fst-italic text-muted">{!! $phase['artisan_notes'] !!}</small>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @elseif ($phaseNumber == 11 && isset($phase['data']['status_title']) && ($phase['data']['status_title'] === 'Non applicabile' || $phase['data']['status_title'] === 'Non generate'))
                             <p class="text-muted fst-italic mt-2 pt-2 {{ (isset($phase['data']['last_import_date']) && $phase['data']['last_import_date'] !== 'N/A') || (isset($phase['data']['last_run_date']) && $phase['data']['last_run_date'] !== 'N/A') || (isset($phase['data']['last_scrape_date']) && $phase['data']['last_scrape_date'] !== 'N/A') || (isset($phase['data']['details']) && !empty($phase['data']['details'])) ? 'border-top' : '' }}"><i class="fas fa-info-circle me-1"></i>{{ $phase['data']['details'] ?? 'Impossibile testare le proiezioni al momento.'}}</p>
                        @elseif ($phaseNumber != 11 && !($phase['data']['showAction'] ?? true) )
                             <p class="text-muted fst-italic mt-2 pt-2 {{ (isset($phase['data']['last_import_date']) && $phase['data']['last_import_date'] !== 'N/A') || (isset($phase['data']['last_run_date']) && $phase['data']['last_run_date'] !== 'N/A') || (isset($phase['data']['last_scrape_date']) && $phase['data']['last_scrape_date'] !== 'N/A') || (isset($phase['data']['details']) && !empty($phase['data']['details'])) ? 'border-top' : '' }}"><i class="fas fa-info-circle me-1"></i>Nessuna azione specifica richiesta al momento.</p>
                        @endif
                    </div>
                </div>
            </div> @if($loop->iteration % 2 == 0 && !$loop->last)
                </div><div class="row">
            @endif
        @endforeach
        </div> </div> @endsection