<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\FbrefScrapingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon; // Importa Carbon per la gestione delle date
use App\Models\Player;
use App\Models\Team;
use App\Models\PlayerFbrefStat;

class ScrapeFbrefTeamStatsCommand extends Command
{
    /**
     * The name and signature of the console command.
     * Esempio di come chiamarlo:
     * php artisan fbref:scrape-team "https://fbref.com/it/squadre/4cceedfc/Statistiche-Pisa" --team_id=1 --season=2024 --league="Serie B"
     *
     * @var string
     */
    protected $signature = 'fbref:scrape-team
                            {url : L\'URL completo della pagina della squadra su FBRef (es. https://fbref.com/it/squadre/id/Statistiche-NomeSquadra)}
                            {--team_id= : ID della squadra nel tuo database per associare i dati}
                            {--season= : Anno di inizio della stagione (es. 2024 per 2024/25)}
                            {--league= : Nome della lega (es. Serie A, Serie B)}';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Esegue lo scraping delle statistiche di una squadra da un URL FBRef e salva i dati nel database.';
    
    private $scrapingService;
    
    /**
     * Create a new command instance.
     *
     * @param FbrefScrapingService $scrapingService Il servizio di scraping di Fbref.
     * @return void
     */
    public function __construct(FbrefScrapingService $scrapingService)
    {
        parent::__construct();
        $this->scrapingService = $scrapingService;
    }
    
    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        // Recupera gli argomenti e le opzioni del comando
        $url = $this->argument('url');
        $teamIdOption = $this->option('team_id');
        $seasonYear = $this->option('season');
        $leagueNameOption = $this->option('league');
        
        // Validazione dell'anno della stagione
        if (!$seasonYear || !is_numeric($seasonYear)) {
            $this->error("L'anno della stagione (--season) è obbligatorio e deve essere un numero valido (es. 2024).");
            return Command::FAILURE;
        }
        
        $this->info("Avvio scraping per l'URL: {$url}");
        
        // Determinazione dell'ID della Squadra (Team ID)
        $team = null;
        if ($teamIdOption) {
            // Cerca la squadra per ID fornito
            $team = Team::find($teamIdOption);
            if (!$team) {
                $this->error("ID Squadra specificato ({$teamIdOption}) non trovato nel database.");
                return Command::FAILURE;
            }
            $this->line("> ID Squadra (dal parametro): {$team->id} - {$team->name}");
        } else {
            // Tenta di trovare la squadra dalla basename dell'URL come fallback
            $urlPath = parse_url($url, PHP_URL_PATH);
            $teamSlugFromUrl = Str::slug(basename($urlPath));
            $team = Team::where('slug', 'like', "%{$teamSlugFromUrl}%")->first();
            if (!$team) {
                $this->error("ID Squadra non specificato e impossibile determinare la squadra dall'URL '{$url}'. Assicurati che il nome della squadra nell'URL esista nel tuo database o fornisci --team_id.");
                return Command::FAILURE;
            }
            $this->line("> ID Squadra (determinato dall'URL): {$team->id} - {$team->name}");
            $teamIdOption = $team->id;
        }
        
        // Esegue lo scraping tramite il servizio FbrefScrapingService
        $data = $this->scrapingService->setTargetUrl($url)->scrapeTeamStats();
        
        // Gestione degli errori critici di scraping (es. pagina non raggiungibile)
        if (isset($data['error'])) {
            $this->error("Errore critico durante lo scraping: " . $data['error']);
            Log::channel('stderr')->error("Errore scraping FBRef per URL {$url}: " . $data['error']);
            return Command::FAILURE;
        }
        
        $this->info('Scraping dei dati grezzi completato dal servizio.');
        $this->line('--- Inizio salvataggio nel Database ---');
        
        $statsSavedCount = 0;
        
        // MODIFICA QUI: Trova dinamicamente la chiave per la tabella 'statistiche_ordinarie'
        $ordinaryStatsKey = null;
        foreach ($data as $key => $content) {
            // Cerca una chiave che contenga "statistiche_ordinarie" (come da caption slug)
            if (Str::contains($key, 'statistiche_ordinarie')) {
                $ordinaryStatsKey = $key;
                break;
            }
        }
        
        if ($ordinaryStatsKey && isset($data[$ordinaryStatsKey]) && is_array($data[$ordinaryStatsKey])) {
            $this->info("Elaborazione '{$ordinaryStatsKey}'...");
            
            // Determina il nome della lega. Priorità: opzione --league, poi da tableKey, altrimenti 'Unknown League'.
            $leagueName = $leagueNameOption;
            if (!$leagueName) {
                // Tenta di estrarre il nome della lega dalla chiave dinamica della tabella
                if (Str::contains($ordinaryStatsKey, 'serie_a')) {
                    $leagueName = 'Serie A';
                } elseif (Str::contains($ordinaryStatsKey, 'serie_b')) {
                    $leagueName = 'Serie B';
                }
                $leagueName = $leagueName ?? 'Unknown League';
                if ($leagueName === 'Unknown League') {
                    $this->warn("Nome lega non specificato (--league) e non rilevato dalla tabella. Verrà usato '{$leagueName}'.");
                } else {
                    $this->line("Nome lega determinato da tableKey: {$leagueName}.");
                }
            }
            
            // Itera su ogni riga di statistiche del giocatore
            foreach ($data[$ordinaryStatsKey] as $playerStatRow) { // Usa la chiave dinamica qui
                // Usa le chiavi originali (con maiuscole) come da output non normalizzato del servizio
                $playerName = $playerStatRow['Giocatore'] ?? null;
                $fbrefPlayerRoleString = $playerStatRow['Ruolo'] ?? null;
                $fbrefAgeString = $playerStatRow['Età'] ?? null; // Usa 'Età' originale
                
                // Salta la riga se il nome del giocatore non è disponibile
                if (!$playerName) {
                    Log::warning("Riga dati saltata: 'Giocatore' non trovato nella riga.");
                    continue;
                }
                
                // Mappa il ruolo Fbref al ruolo Fantacalcio standard (P,D,C,A).
                $fantaRole = $this->mapFbrefRoleToFantaRole($fbrefPlayerRoleString);
                
                // Calcola la data di nascita dal campo "Età" di Fbref
                $dateOfBirth = $this->calculateDateOfBirth($fbrefAgeString, (int) $seasonYear);
                
                // --- DEBUG: LOG DEI VALORI DI ETA' E DATA DI NASCITA ---
                Log::debug("DEBUG AGE for {$playerName}: fbrefAgeString='{$fbrefAgeString}', calculatedDateOfBirth=" . ($dateOfBirth ? $dateOfBirth->toDateString() : 'NULL'));
                // --------------------------------------------------------
                
                // Trova o crea il Player nella tabella 'players'.
                $player = Player::firstOrCreate(
                    ['name' => $playerName], // Cerca per nome
                    [
                        'team_id' => $team->id,
                        'team_name' => $team->name, // Popola il team_name per il nuovo giocatore
                        'role' => $fantaRole, // Il ruolo Fantacalcio (P, D, C, A, o X)
                        'initial_quotation' => 0, // Default per la quotazione iniziale
                        'date_of_birth' => $dateOfBirth, // Popola la data di nascita
                    ]
                    );
                
                // Aggiorna il team_id, team_name e date_of_birth del giocatore se sono cambiati
                if ($player->team_id !== $team->id || $player->team_name !== $team->name || ($dateOfBirth && $player->date_of_birth != $dateOfBirth)) {
                    $player->update([
                        'team_id' => $team->id,
                        'team_name' => $team->name,
                        'date_of_birth' => $dateOfBirth,
                    ]);
                    $this->line("Aggiornato team_id, team_name e data di nascita per giocatore '{$player->name}' a {$team->name}.");
                }
                
                // Prepara i dati per il salvataggio nella tabella 'player_fbref_stats'
                $playerFbrefStatData = [
                    'player_id'          => $player->id,
                    'team_id'            => $team->id,
                    'season_year'        => (int) $seasonYear,
                    'league_name'        => $leagueName,
                    'data_source'        => 'fbref_html_import',
                    'position_fbref'     => $fbrefPlayerRoleString, // Salva il ruolo Fbref originale (es. "Cen,Att")
                    'age_string_fbref'   => $fbrefAgeString, // Salva la stringa età originale da Fbref
                    
                    // Statistiche di Gioco Base (conversione da stringa a int/float)
                    'games_played'       => $this->parseInt($playerStatRow['PG'] ?? null),
                    'games_started'      => $this->parseInt($playerStatRow['Tit'] ?? null),
                    'minutes_played'     => $this->parseInt($playerStatRow['Min'] ?? null, true),
                    'minutes_per_90'     => $this->parseFloat($playerStatRow['90 min'] ?? null),
                    
                    // Rendimento Offensivo - RIMOSSO IL CAST (int) per permettere decimali
                    'goals'              => $this->parseFloat($playerStatRow['Reti'] ?? null),
                    'assists'            => $this->parseFloat($playerStatRow['Assist'] ?? null),
                    'non_penalty_goals'  => $this->parseFloat($playerStatRow['R - Rig'] ?? null),
                    'penalties_made'     => $this->parseInt($playerStatRow['Rigori'] ?? null),
                    'penalties_attempted'=> $this->parseInt($playerStatRow['Rig T'] ?? null),
                    'yellow_cards'       => $this->parseInt($playerStatRow['Amm.'] ?? null),
                    'red_cards'          => $this->parseInt($playerStatRow['Esp.'] ?? null),
                    
                    // Expected Stats
                    'expected_goals'     => $this->parseFloat($playerStatRow['xG'] ?? null),
                    'non_penalty_expected_goals' => $this->parseFloat($playerStatRow['npxG'] ?? null),
                    'expected_assisted_goals' => $this->parseFloat($playerStatRow['xAG'] ?? null),
                    
                    // Progressione
                    'progressive_carries' => $this->parseInt($playerStatRow['PrgC'] ?? null),
                    'progressive_passes_completed' => $this->parseInt($playerStatRow['PrgP'] ?? null),
                    'progressive_passes_received' => $this->parseInt($playerStatRow['PrgR'] ?? null),
                    
                    // Campi 'per 90' (possono essere NULL se non direttamente disponibili, calcolabili in seguito)
                    'goals_per_90' => null,
                    'assists_per_90' => null,
                    'non_penalty_goals_per_90' => null,
                    'expected_goals_per_90' => null,
                    'expected_assisted_goals_per_90' => null,
                    'non_penalty_expected_goals_per_90' => null,
                ];
                
                // MODIFICA QUI: Trova dinamicamente la chiave per la tabella 'difesa_porta'
                $goalkeeperStatsKey = null;
                foreach ($data as $key => $content) {
                    if (Str::contains($key, 'difesa_della_porta') || Str::contains($key, 'difesa_porta')) {
                        $goalkeeperStatsKey = $key;
                        break;
                    }
                }
                
                // Processa le statistiche dei portieri (tabella 'difesa_porta')
                if ($goalkeeperStatsKey && isset($data[$goalkeeperStatsKey]) && is_array($data[$goalkeeperStatsKey])) {
                    $gkStatRow = collect($data[$goalkeeperStatsKey])->firstWhere('Giocatore', $playerName);
                    if ($gkStatRow) {
                        $playerFbrefStatData['gk_games_played'] = $this->parseInt($gkStatRow['PG'] ?? null);
                        $playerFbrefStatData['gk_goals_conceded'] = $this->parseInt($gkStatRow['Rs'] ?? null);
                        $playerFbrefStatData['gk_shots_on_target_against'] = $this->parseInt($gkStatRow['Tiri in porta'] ?? null);
                        $playerFbrefStatData['gk_saves'] = $this->parseInt($gkStatRow['Parate'] ?? null);
                        $playerFbrefStatData['gk_save_percentage'] = $this->parseFloat($gkStatRow['%Parate'] ?? null);
                        $playerFbrefStatData['gk_clean_sheets'] = $this->parseInt($gkStatRow['Porta Inviolata'] ?? null);
                        $playerFbrefStatData['gk_cs_percentage'] = $this->parseFloat($gkStatRow['% PI'] ?? null);
                        $playerFbrefStatData['gk_penalties_faced'] = $this->parseInt($gkStatRow['Rig T'] ?? null);
                        $playerFbrefStatData['gk_penalties_conceded_on_attempt'] = $this->parseInt($gkStatRow['Rig segnati'] ?? null);
                        $playerFbrefStatData['gk_penalties_saved'] = $this->parseInt($gkStatRow['Rig parati'] ?? null);
                    }
                }
                
                // MODIFICA QUI: Trova dinamicamente la chiave per la tabella 'tiri'
                $shootingStatsKey = null;
                foreach ($data as $key => $content) {
                    if (Str::contains($key, 'tiri')) {
                        $shootingStatsKey = $key;
                        break;
                    }
                }
                // Processa le statistiche di tiro (tabella 'tiri')
                if ($shootingStatsKey && isset($data[$shootingStatsKey]) && is_array($data[$shootingStatsKey])) {
                    $shootingStatRow = collect($data[$shootingStatsKey])->firstWhere('Giocatore', $playerName);
                    if ($shootingStatRow) {
                        $playerFbrefStatData['shots_total'] = $this->parseInt($shootingStatRow['Tiri'] ?? null);
                        $playerFbrefStatData['shots_on_target'] = null;
                    }
                }
                
                // MODIFICA QUI: Trova dinamicamente la chiave per la tabella 'difesa'
                $defenseStatsKey = null;
                foreach ($data as $key => $content) {
                    if (Str::contains($key, 'difesa')) {
                        $defenseStatsKey = $key;
                        break;
                    }
                }
                // Processa le statistiche difensive (tabella 'difesa')
                if ($defenseStatsKey && isset($data[$defenseStatsKey]) && is_array($data[$defenseStatsKey])) {
                    $defenseStatRow = collect($data[$defenseStatsKey])->firstWhere('Giocatore', $playerName);
                    if ($defenseStatRow) {
                        $playerFbrefStatData['defense_tackles_attempted'] = $this->parseInt($defenseStatRow['Cntrs'] ?? null);
                        $playerFbrefStatData['defense_tackles_won'] = $this->parseInt($defenseStatRow['Contr. vinti'] ?? null);
                        $playerFbrefStatData['defense_interceptions'] = $this->parseInt($defenseStatRow['Int'] ?? null);
                        $playerFbrefStatData['defense_clearances'] = $this->parseInt($defenseStatRow['Salvat.'] ?? null);
                        $playerFbrefStatData['defense_blocks_general'] = $this->parseInt($defenseStatRow['Blocchi'] ?? null);
                        $playerFbrefStatData['defense_shots_blocked'] = $this->parseInt($defenseStatRow['Tiri'] ?? null);
                        $playerFbrefStatData['defense_passes_blocked'] = $this->parseInt($defenseStatRow['Passaggio'] ?? null);
                    }
                }
                
                // Processa le statistiche varie (misc) - campo 'Falli' da tabella 'difesa'
                if ($defenseStatsKey && isset($data[$defenseStatsKey]) && is_array($data[$defenseStatsKey])) {
                    $defenseStatRow = collect($data[$defenseStatsKey])->firstWhere('Giocatore', $playerName);
                    if ($defenseStatRow) {
                        $playerFbrefStatData['misc_fouls_committed'] = $this->parseInt($defenseStatRow['Falli'] ?? null);
                    }
                }
                
                // --- DEBUG: LOG DEL DATO PRIMA DEL SALVATAGGIO ---
                Log::debug("Saving PlayerFbrefStat for {$playerName}: " . json_encode($playerFbrefStatData));
                // --------------------------------------------------
                
                try {
                    // Salva o aggiorna il record nella tabella 'player_fbref_stats'
                    PlayerFbrefStat::updateOrCreate(
                        [
                            'player_id'   => $player->id,
                            'team_id'     => $team->id,
                            'season_year' => (int) $seasonYear,
                            'league_name' => $leagueName,
                            'data_source' => 'fbref_html_import', // Chiave unica per lo storico Fbref
                        ],
                        $playerFbrefStatData
                        );
                    $this->line("Salvato statistiche FBRef per '{$playerName}' ({$seasonYear}) nella lega '{$leagueName}'.");
                    $statsSavedCount++;
                } catch (\Exception $e) {
                    $this->error("Errore nel salvataggio statistiche per {$playerName}: " . $e->getMessage());
                    Log::error("Errore salvataggio FBRef stat per {$playerName} (URL: {$url}): " . $e->getMessage());
                }
            }
        } else {
            $this->warn("Nessuna tabella 'statistiche_ordinarie' trovata nell'output dello scraping, o è vuota.");
        }
        
        // --- SALVATAGGIO DEL JSON DI DEBUG (RIATTIVATO) ---
        $urlPath = parse_url($url, PHP_URL_PATH);
        $baseFileName = Str::slug(basename($urlPath ?: 'unknown_url'));
        $urlHash = substr(md5($url), 0, 6);
        $fileName = 'fbref_data_' . $baseFileName . '_' . $urlHash . '_' . date('Ymd_His') . '.json';
        $subfolder = 'scraping'; // Assicurati che questa sottocartella esista in storage/app
        $filePath = $subfolder . '/' . $fileName;
        try {
            Storage::disk('local')->put($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("\nOutput completo dei dati grezzi salvato in: storage/app/{$filePath}");
        } catch (\Exception $e) {
            $this->error("Impossibile salvare il file JSON di debug: " . $e->getMessage());
        }
        // -------------------------------------------------------------------------------------------------------
        
        $this->info("\n--- Riepilogo Salvataggio Database ---");
        $this->info("Statistiche salvate/aggiornate con successo: {$statsSavedCount}");
        $this->info("Comando fbref:scrape-team completato per URL {$url}.");
        $this->info("\nComando terminato.");
        
        return Command::SUCCESS;
    }
    
    /**
     * Helper function to map Fbref role string (which can be multiple roles) to a single FantaRole.
     * Takes only the first role from the string and maps it to a single character (P, D, C, A).
     * Uses 'X' as a single-character fallback for unknown roles.
     *
     * @param string|null $fbrefRoleString La stringa del ruolo come viene da Fbref (es. "Cen,Att").
     * @return string (P, D, C, A, or X for unknown)
     */
    private function mapFbrefRoleToFantaRole(?string $fbrefRoleString): string
    {
        // Se la stringa è nulla o vuota, restituisce il fallback 'X'
        if (is_null($fbrefRoleString) || trim($fbrefRoleString) === '') {
            return 'X'; // Fallback a singolo carattere per 'Sconosciuto'
        }
        
        // Divide la stringa per virgola e prende solo il primo ruolo.
        // Esempio: "Cen,Att" -> ["Cen", "Att"] -> "Cen"
        $roles = explode(',', $fbrefRoleString);
        $firstFbrefRole = trim($roles[0]);
        
        // Mappa il primo ruolo Fbref al ruolo Fantacalcio standard (singolo carattere)
        switch ($firstFbrefRole) {
            case 'Por':
                return 'P';
            case 'Dif':
                return 'D';
            case 'Cen':
                return 'C';
            case 'Att':
                return 'A';
                // Puoi aggiungere altri casi se Fbref usa abbreviazioni diverse per i ruoli principali, es. "CC" per "Cen"
                // case 'CC':
                //     return 'C';
            default:
                // Se il primo ruolo non è riconosciuto, restituisce il fallback 'X'
                return 'X';
        }
    }
    
    /**
     * Calcola la data di nascita di un giocatore basandosi sulla stringa dell'età di Fbref
     * e sull'anno della stagione.
     * Formato età Fbref: "YY-DDD" (Anni-Giorni)
     *
     * @param string|null $fbrefAgeString La stringa dell'età di Fbref (es. "27-142").
     * @param int $seasonYear L'anno di inizio della stagione (es. 2024 per la stagione 2024/25).
     * @return Carbon|null La data di nascita calcolata o null se la stringa età non è valida.
     */
    private function calculateDateOfBirth(?string $fbrefAgeString, int $seasonYear): ?Carbon
    {
        if (is_null($fbrefAgeString) || !str_contains($fbrefAgeString, '-')) {
            return null;
        }
        
        list($years, $days) = explode('-', $fbrefAgeString);
        
        // Assumiamo che l'età sia riferita all'inizio della stagione (es. 1° luglio dell'anno di inizio stagione)
        // o a una data specifica che Fbref usa come riferimento per l'età.
        // Per semplicità, useremo l'inizio della stagione come riferimento per l'età.
        // Puoi aggiustare la data di riferimento se sai che Fbref usa una data diversa.
        $referenceDate = Carbon::createFromDate($seasonYear, 7, 1); // Esempio: 1° luglio dell'anno di inizio stagione
        
        try {
            // Sottrae gli anni e i giorni dall'età di riferimento per ottenere la data di nascita
            $dateOfBirth = $referenceDate->subYears((int) $years)->subDays((int) $days);
            return $dateOfBirth;
        } catch (\Exception $e) {
            Log::warning("Impossibile calcolare la data di nascita da '{$fbrefAgeString}' e anno {$seasonYear}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Helper function to parse string to integer, handling null, empty, and non-numeric values.
     * This version is safer for integers and handles thousands separators.
     *
     * @param string|null $value Il valore da parsare.
     * @param bool $removeThousandsSeparator Se true, rimuove i punti come separatori delle migliaia.
     * @return int|null L'intero parsato o null se il valore non è valido.
     */
    private function parseInt(?string $value, bool $removeThousandsSeparator = false): ?int
    {
        if (is_null($value) || trim($value) === '') {
            return null;
        }
        if ($removeThousandsSeparator) {
            $value = str_replace('.', '', $value); // Rimuove i punti (separatore migliaia)
        }
        // Rimuove tutti i caratteri non numerici tranne il segno meno all'inizio
        $value = preg_replace('/[^0-9-]/', '', $value);
        return is_numeric($value) ? (int) $value : null;
    }
    
    /**
     * Helper function to parse string to float, handling null, empty, and non-numeric values.
     * Converts comma decimal separator to dot.
     *
     * @param string|null $value Il valore da parsare.
     * @return float|null Il float parsato o null se il valore non è valido.
     */
    private function parseFloat(?string $value): ?float
    {
        if (is_null($value) || trim($value) === '') {
            return null;
        }
        // Sostituisce la virgola con il punto come separatore decimale
        $value = str_replace(',', '.', $value);
        // Rimuove tutti i caratteri non numerici tranne i numeri e il punto decimale, e il segno meno all'inizio
        $value = preg_replace('/[^0-9.-]/', '', $value);
        return is_numeric($value) ? (float) $value : null;
    }
}
