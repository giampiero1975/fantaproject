<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\FbrefScrapingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\Player;
use App\Models\Team;
use App\Models\PlayerFbrefStat;

class ScrapeFbrefTeamStatsCommand extends Command
{
    /**
     * The name and signature of the console command.
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
     * @param FbrefScrapingService $scrapingService
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
        $url = $this->argument('url');
        $teamIdOption = $this->option('team_id');
        $seasonYear = $this->option('season');
        $leagueNameOption = $this->option('league');
        
        if (!$seasonYear || !is_numeric($seasonYear)) {
            $this->error("L'anno della stagione (--season) � obbligatorio e deve essere un numero valido (es. 2024).");
            return Command::FAILURE;
        }
        
        $this->info("Avvio scraping per l'URL: {$url}");
        
        $team = null;
        if ($teamIdOption) {
            $team = Team::find($teamIdOption);
            if (!$team) {
                $this->error("ID Squadra specificato ({$teamIdOption}) non trovato nel database.");
                return Command::FAILURE;
            }
            $this->line("> ID Squadra (dal parametro): {$team->id} - {$team->name}");
        } else {
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
        
        $data = $this->scrapingService->setTargetUrl($url)->scrapeTeamStats();
        
        if (isset($data['error'])) {
            $this->error("Errore critico durante lo scraping: " . $data['error']);
            Log::channel('stderr')->error("Errore scraping FBRef per URL {$url}: " . $data['error']);
            return Command::FAILURE;
        }
        
        $this->info('Scraping dei dati grezzi completato dal servizio.');
        $this->line('--- Inizio salvataggio nel Database ---');
        
        $statsSavedCount = 0;
        
        $ordinaryStatsKey = null;
        foreach ($data as $key => $content) {
            if (Str::contains($key, 'statistiche_ordinarie')) {
                $ordinaryStatsKey = $key;
                break;
            }
        }
        
        if ($ordinaryStatsKey && isset($data[$ordinaryStatsKey]) && is_array($data[$ordinaryStatsKey])) {
            $this->info("Elaborazione '{$ordinaryStatsKey}'...");
            
            $leagueName = $leagueNameOption;
            if (!$leagueName) {
                if (Str::contains($ordinaryStatsKey, 'serie_a')) {
                    $leagueName = 'Serie A';
                } elseif (Str::contains($ordinaryStatsKey, 'serie_b')) {
                    $leagueName = 'Serie B';
                }
                $leagueName = $leagueName ?? 'Unknown League';
                if ($leagueName === 'Unknown League') {
                    $this->warn("Nome lega non specificato (--league) e non rilevato dalla tabella. Verr� usato '{$leagueName}'.");
                } else {
                    $this->line("Nome lega determinato da tableKey: {$leagueName}.");
                }
            }
            
            foreach ($data[$ordinaryStatsKey] as $playerStatRow) {
                $playerName = $playerStatRow['Giocatore'] ?? null;
                $fbrefPlayerRoleString = $playerStatRow['Ruolo'] ?? null;
                
                // --- MODIFICA QUI PER LEGGERE 'Et�' IN MODO ROBUSTO ---
                $fbrefAgeString = null;
                // Prova prima la chiave "Et�" con l'accento grave
                if (isset($playerStatRow['Et�'])) {
                    $fbrefAgeString = $playerStatRow['Et�'];
                }
                // Se non trovata, prova la sua versione unicode se il parsing l'ha trasformata
                elseif (isset($playerStatRow["Et\u{00e0}"])) { // Et� con accento grave codificato in UTF-8
                    $fbrefAgeString = $playerStatRow["Et\u{00e0}"];
                }
                // Prova anche una versione senza accento, se per qualche ragione la fonte lo varia
                elseif (isset($playerStatRow['Eta'])) {
                    $fbrefAgeString = $playerStatRow['Eta'];
                }
                // --- FINE MODIFICA ---
                
                // --- DEBUG AGGIUNTI (MANTENUTI) ---
                Log::debug("DEBUG_ET�: Player: {$playerName}");
                Log::debug("DEBUG_ET�:   - playerStatRow keys: " . json_encode(array_keys($playerStatRow)));
                Log::debug("DEBUG_ET�:   - raw 'Et�' value (from playerStatRow['Et�']): " . ($playerStatRow['Et�'] ?? 'KEY_MISSING_RAW'));
                Log::debug("DEBUG_ET�:   - raw 'Et\u{00e0}' value (from playerStatRow[\"Et\\u{00e0}\"]): " . ($playerStatRow["Et\u{00e0}"] ?? 'KEY_MISSING_UNICODE'));
                Log::debug("DEBUG_ET�:   - \$fbrefAgeString value (after logic): " . ($fbrefAgeString ?? 'NULL_VAR_FINAL'));
                // --- FINE DEBUG AGGIUNTI ---
                
                if (!$playerName) {
                    Log::warning("Riga dati saltata: 'Giocatore' non trovato nella riga.");
                    continue;
                }
                
                $fantaRole = $this->mapFbrefRoleToFantaRole($fbrefPlayerRoleString);
                
                $dateOfBirth = null;
                if (!empty($fbrefAgeString)) {
                    $dateOfBirth = $this->calculateDateOfBirth($fbrefAgeString, (int) $seasonYear);
                }
                
                // Trova o crea il Player nella tabella 'players'.
                $player = Player::firstOrCreate(
                    ['name' => $playerName],
                    [
                        'team_id' => $team->id,
                        'team_name' => $team->name,
                        'role' => $fantaRole,
                        'initial_quotation' => 0,
                        'date_of_birth' => $dateOfBirth, // Inserisce la data di nascita stimata se non esiste gi�
                    ]
                    );
                
                // Aggiorna team_id, team_name e role se sono cambiati
                $playerNeedsUpdate = false;
                if ($player->team_id !== $team->id) { $player->team_id = $team->id; $playerNeedsUpdate = true; }
                if ($player->team_name !== $team->name) { $player->team_name = $team->name; $playerNeedsUpdate = true; }
                if ($player->role !== $fantaRole) { $player->role = $fantaRole; $playerNeedsUpdate = true; }
                
                // Se date_of_birth non era presente PRIMA di questo enrich, e ora l'abbiamo da FBRef, aggiornala
                if ($player->date_of_birth === null && $dateOfBirth !== null) {
                    $player->date_of_birth = $dateOfBirth;
                    $playerNeedsUpdate = true;
                }
                
                if ($playerNeedsUpdate || $player->isDirty()) {
                    $player->save();
                    $this->line("Aggiornato dati (team, role, date_of_birth) per giocatore '{$player->name}'.");
                }
                
                // Prepara i dati per il salvataggio nella tabella 'player_fbref_stats'
                $playerFbrefStatData = [
                    'player_id'          => $player->id,
                    'team_id'            => $team->id,
                    'season_year'        => (int) $seasonYear,
                    'league_name'        => $leagueName,
                    'data_source'        => 'fbref_html_import',
                    'position_fbref'     => $fbrefPlayerRoleString,
                    'age_string_fbref'   => $fbrefAgeString, // Questo dovrebbe ora avere il valore corretto
                    
                    'games_played'       => $this->parseInt($playerStatRow['PG'] ?? null),
                    'games_started'      => $this->parseInt($playerStatRow['Tit'] ?? null),
                    'minutes_played'     => $this->parseInt($playerStatRow['Min'] ?? null, true),
                    'minutes_per_90'     => $this->parseFloat($playerStatRow['90 min'] ?? null),
                    
                    'goals'              => $this->parseFloat($playerStatRow['Reti'] ?? null),
                    'assists'            => $this->parseFloat($playerStatRow['Assist'] ?? null),
                    'non_penalty_goals'  => $this->parseFloat($playerStatRow['R - Rig'] ?? null),
                    'penalties_made'     => $this->parseInt($playerStatRow['Rigori'] ?? null),
                    'penalties_attempted'=> $this->parseInt($playerStatRow['Rig T'] ?? null),
                    'yellow_cards'       => $this->parseInt($playerStatRow['Amm.'] ?? null),
                    'red_cards'          => $this->parseInt($playerStatRow['Esp.'] ?? null),
                    
                    'expected_goals'     => $this->parseFloat($playerStatRow['xG'] ?? null),
                    'non_penalty_expected_goals' => $this->parseFloat($playerStatRow['npxG'] ?? null),
                    'expected_assisted_goals' => $this->parseFloat($playerStatRow['xAG'] ?? null),
                    
                    'progressive_carries' => $this->parseInt($playerStatRow['PrgC'] ?? null),
                    'progressive_passes_completed' => $this->parseInt($playerStatRow['PrgP'] ?? null),
                    'progressive_passes_received' => $this->parseInt($playerStatRow['PrgR'] ?? null),
                    
                    // Aggiunti i campi per 90 minuti. Assicurati che le chiavi del tuo scraped JSON siano queste
                    'goals_per_90' => $this->parseFloat($playerStatRow['Gls/90'] ?? null),
                    'assists_per_90' => $this->parseFloat($playerStatRow['Ast/90'] ?? null),
                    'non_penalty_goals_per_90' => $this->parseFloat($playerStatRow['G-PK/90'] ?? null),
                    'expected_goals_per_90' => $this->parseFloat($playerStatRow['xG/90'] ?? null),
                    'expected_assisted_goals_per_90' => $this->parseFloat($playerStatRow['xAG/90'] ?? null),
                    'non_penalty_expected_goals_per_90' => $this->parseFloat($playerStatRow['npxG/90'] ?? null),
                    
                ];
                
                $goalkeeperStatsKey = null;
                foreach ($data as $key => $content) {
                    if (Str::contains($key, 'difesa_della_porta') || Str::contains($key, 'difesa_porta')) {
                        $goalkeeperStatsKey = $key;
                        break;
                    }
                }
                
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
                
                $shootingStatsKey = null;
                foreach ($data as $key => $content) {
                    if (Str::contains($key, 'tiri')) {
                        $shootingStatsKey = $key;
                        break;
                    }
                }
                if ($shootingStatsKey && isset($data[$shootingStatsKey]) && is_array($data[$shootingStatsKey])) {
                    $shootingStatRow = collect($data[$shootingStatsKey])->firstWhere('Giocatore', $playerName);
                    if ($shootingStatRow) {
                        $playerFbrefStatData['shots_total'] = $this->parseInt($shootingStatRow['Tiri'] ?? null);
                        $playerFbrefStatData['shots_on_target'] = null; // often not available as direct total
                    }
                }
                
                $defenseStatsKey = null;
                foreach ($data as $key => $content) {
                    if (Str::contains($key, 'difesa')) {
                        $defenseStatsKey = $key;
                        break;
                    }
                }
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
                
                // Misc stats (fouls committed)
                if ($defenseStatsKey && isset($data[$defenseStatsKey]) && is_array($data[$defenseStatsKey])) {
                    $defenseStatRow = collect($data[$defenseStatsKey])->firstWhere('Giocatore', $playerName);
                    if ($defenseStatRow) {
                        $playerFbrefStatData['misc_fouls_committed'] = $this->parseInt($defenseStatRow['Falli'] ?? null);
                    }
                }
                
                Log::debug("Saving PlayerFbrefStat for {$playerName}: " . json_encode($playerFbrefStatData));
                
                try {
                    PlayerFbrefStat::updateOrCreate(
                        [
                            'player_id'   => $player->id,
                            'team_id'     => $team->id,
                            'season_year' => (int) $seasonYear,
                            'league_name' => $leagueName,
                            'data_source' => 'fbref_html_import',
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
            $this->warn("Nessuna tabella 'statistiche_ordinarie' trovata nell'output dello scraping, o � vuota.");
        }
        
        $urlPath = parse_url($url, PHP_URL_PATH);
        $baseFileName = Str::slug(basename($urlPath ?: 'unknown_url'));
        $urlHash = substr(md5($url), 0, 6);
        $fileName = 'fbref_data_' . $baseFileName . '_' . $urlHash . '_' . date('Ymd_His') . '.json';
        $subfolder = 'scraping';
        $filePath = $subfolder . '/' . $fileName;
        try {
            Storage::disk('local')->put($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("\nOutput completo dei dati grezzi salvato in: storage/app/{$filePath}");
        } catch (\Exception $e) {
            $this->error("Impossibile salvare il file JSON di debug: " . $e->getMessage());
        }
        
        $this->info("\n--- Riepilogo Salvataggio Database ---");
        $this->info("Statistiche salvate/aggiornate con successo: {$statsSavedCount}");
        $this->info("Comando fbref:scrape-team completato per URL {$url}.");
        $this->info("\nComando terminato.");
        
        return Command::SUCCESS;
    }
    
    /**
     * Helper function to map Fbref role string (which can be multiple roles) to a single FantaRole.
     *
     * @param string|null $fbrefRoleString
     * @return string
     */
    private function mapFbrefRoleToFantaRole(?string $fbrefRoleString): string
    {
        if (is_null($fbrefRoleString) || trim($fbrefRoleString) === '') {
            return 'X';
        }
        
        $roles = explode(',', $fbrefRoleString);
        $firstFbrefRole = trim($roles[0]);
        
        switch ($firstFbrefRole) {
            case 'Por': return 'P';
            case 'Dif': return 'D';
            case 'Cen': return 'C';
            case 'Att': return 'A';
            default: return 'X';
        }
    }
    
    /**
     * Calcola la data di nascita di un giocatore basandosi sulla stringa dell'et� di Fbref
     * e sull'anno della stagione di riferimento.
     *
     * @param string|null $fbrefAgeString
     * @param int $seasonYear
     * @return Carbon|null
     */
    private function calculateDateOfBirth(?string $fbrefAgeString, int $seasonYear): ?Carbon
    {
        if (is_null($fbrefAgeString) || !str_contains($fbrefAgeString, '-')) {
            return null;
        }
        
        list($years, $days) = explode('-', $fbrefAgeString);
        
        // La data di riferimento � l'inizio della stagione successiva (1� Luglio)
        // Se un giocatore ha 26-167 nella stagione 2024 (che termina a met� 2025),
        // allora al 1� Luglio 2025 aveva 26 anni e 167 giorni.
        $referenceDate = Carbon::create($seasonYear + 1, 7, 1, 0, 0, 0, 'Europe/Rome');
        
        try {
            $birthDate = $referenceDate->subYears((int) $years)->subDays((int) $days);
            return $birthDate;
        } catch (\Exception $e) {
            Log::warning("Impossibile calcolare la data di nascita da '{$fbrefAgeString}' e anno {$seasonYear}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Helper function to parse string to integer.
     *
     * @param string|null $value
     * @param bool $removeThousandsSeparator
     * @return int|null
     */
    private function parseInt(?string $value, bool $removeThousandsSeparator = false): ?int
    {
        if (is_null($value) || trim($value) === '') {
            return null;
        }
        if ($removeThousandsSeparator) {
            $value = str_replace('.', '', $value);
        }
        $value = preg_replace('/[^0-9-]/', '', $value); // Keep only digits and leading minus sign
        return is_numeric($value) ? (int) $value : null;
    }
    
    /**
     * Helper function to parse string to float.
     *
     * @param string|null $value
     * @return float|null
     */
    private function parseFloat(?string $value): ?float
    {
        if (is_null($value) || trim($value) === '') {
            return null;
        }
        $value = str_replace(',', '.', $value); // Replace comma with dot for decimals
        $value = preg_replace('/[^0-9.-]/', '', $value); // Keep only digits, dot, and leading minus sign
        return is_numeric($value) ? (float) $value : null;
    }
}