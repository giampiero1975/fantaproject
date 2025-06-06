<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Team;
use Illuminate\Support\Facades\Http; // Se fa chiamate HTTP dirette (sconsigliato, usare servizio)
use Illuminate\Support\Facades\Log;
use App\Services\PlayerStatsApiService; // Idealmente, dovrebbe usare questo servizio

class MapTeamApiIdsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'teams:map-api-ids 
                            {--league_code=SA : Codice della lega per cui recuperare le squadre (es. SA, SB)}
                            {--target_season_start_year= : Anno di inizio stagione per cui recuperare le squadre (es. 2023 per 2023-24)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mappa gli ID delle squadre locali con gli ID API da un provider esterno (DEPRECATO o da RIVEDERE)';

    protected ?string $apiKey;    // Chiave API per il provider esterno
    protected ?string $apiHost;   // Host base dell'API
    protected ?string $apiKeyName; // Nome dell'header per la chiave API

    // Inietta il PlayerStatsApiService se vuoi fare chiamate API tramite esso
    // protected PlayerStatsApiService $playerStatsApiService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(/* PlayerStatsApiService $playerStatsApiService */) // Decommenta per iniezione
    {
        parent::__construct();
        // $this->playerStatsApiService = $playerStatsApiService; // Decommenta per iniezione

        // Tenta di leggere la configurazione API corretta per football-data.org
        $configPath = 'services.player_stats_api.providers.football_data_org';
        $apiConfig = config($configPath);

        if (is_null($apiConfig) || empty($apiConfig['api_key']) || empty($apiConfig['base_url']) || empty($apiConfig['api_key_name'])) {
            Log::warning("MapTeamApiIdsCommand: Configurazione API per football_data_org non trovata o incompleta in {$configPath}. Il comando potrebbe non funzionare correttamente se necessita di chiamate API dirette.");
            $this->apiKey = null;
            $this->apiHost = null;
            $this->apiKeyName = null;
        } else {
            $this->apiKey = $apiConfig['api_key'];
            $this->apiHost = rtrim($apiConfig['base_url'], '/');
            $this->apiKeyName = $apiConfig['api_key_name'];
            Log::info("MapTeamApiIdsCommand initializzato con API Host: {$this->apiHost}");
        }
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Avvio comando MapTeamApiIdsCommand...');
        $this->warn('ATTENZIONE: Questo comando (teams:map-api-ids) potrebbe essere obsoleto o necessitare di una revisione significativa.');
        $this->warn('La mappatura degli ID API (api_football_data_id) ora avviene principalmente tramite il comando `teams:set-active-league`.');

        // Se il comando dovesse comunque fare chiamate API dirette (sconsigliato):
        if (empty($this->apiKey) || empty($this->apiHost) || empty($this->apiKeyName)) {
            $this->error('Chiave API, Host API o Nome Chiave API (per football-data.org) non configurati correttamente.');
            $this->error('Assicurati che FOOTBALL_DATA_API_KEY sia impostata nel .env e che config/services.php sia corretto.');
            return Command::FAILURE;
        }

        $leagueCode = strtoupper($this->option('league_code'));
        $targetSeasonStartYear = $this->option('target_season_start_year');

        if (!$targetSeasonStartYear || !is_numeric($targetSeasonStartYear)) {
            $targetSeasonStartYear = date('Y'); // Default all'anno corrente se non specificato o non valido
            $this->info("Anno di inizio stagione non specificato o non valido, utilizzo default: {$targetSeasonStartYear} (per stagione {$targetSeasonStartYear}-" . substr($targetSeasonStartYear + 1, -2) . ")");
        }

        $this->info("Tentativo di mappare ID API per le squadre della lega {$leagueCode}, stagione {$targetSeasonStartYear}.");

        // Logica di esempio (DA RIVEDERE E COMPLETARE SE IL COMANDO È ANCORA NECESSARIO):
        // 1. Recuperare le squadre dalla tua tabella `teams` che non hanno `api_football_data_id`.
        // 2. Per ogni squadra, tentare di trovare una corrispondenza sull'API esterna (es. per nome).
        //    Questo dovrebbe essere fatto tramite PlayerStatsApiService.
        // 3. Se trovata, aggiornare `api_football_data_id` nella tabella `teams`.

        /* Esempio di logica che usa PlayerStatsApiService (richiede iniezione nel costruttore)
        $apiTeamsData = $this->playerStatsApiService->getTeamsForCompetitionAndSeason($leagueCode, (int)$targetSeasonStartYear);

        if (!$apiTeamsData || !isset($apiTeamsData['teams'])) {
            $this->error("Impossibile recuperare le squadre dall'API per {$leagueCode} stagione {$targetSeasonStartYear}.");
            return Command::FAILURE;
        }

        $teamsToMap = Team::whereNull('api_football_data_id')->get();
        $mappedCount = 0;

        foreach ($apiTeamsData['teams'] as $apiTeam) {
            if (empty($apiTeam['id']) || empty($apiTeam['name'])) continue;

            // Tentativo di match per nome (normalizzato)
            $normalizedApiName = $this->normalizeName($apiTeam['name']); // Assumendo un metodo normalizeName()

            foreach ($teamsToMap as $localTeam) {
                if ($this->normalizeName($localTeam->name) === $normalizedApiName) {
                    $localTeam->api_football_data_id = $apiTeam['id'];
                    $localTeam->save();
                    $this->info("Mappato '{$localTeam->name}' (DB ID: {$localTeam->id}) con API ID {$apiTeam['id']} ('{$apiTeam['name']}')");
                    $mappedCount++;
                    // Rimuovi dalla lista per non ricontrollare
                    $teamsToMap = $teamsToMap->except($localTeam->id);
                    break;
                }
            }
        }
        $this->info("Mappatura completata. {$mappedCount} squadre mappate.");
        */

        $this->comment('---------------------------------------------------------------------');
        $this->comment('La logica effettiva di questo comando necessita di essere implementata o revisionata.');
        $this->comment('Considera se il comando `teams:set-active-league` già copre le tue necessità di mapping.');
        $this->comment('---------------------------------------------------------------------');

        $this->info('Comando MapTeamApiIdsCommand terminato.');
        return Command::SUCCESS;
    }

    /**
     * Normalizza un nome per un confronto più flessibile.
     * (Potrebbe essere spostato in un Trait o Service se usato da più comandi)
     */
    /*
    private function normalizeName(string $name): string
    {
        $normalized = strtolower($name);
        $toRemove = [' fc', ' calcio', ' ac', ' ssc', ' spa', ' 1909', ' 1913', ' asd', 'cf', 'bc', 'us'];
        $normalized = str_replace($toRemove, '', $normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $normalized);
        $normalized = trim($normalized);
        return preg_replace('/\s+/', ' ', $normalized);
    }
    */
}