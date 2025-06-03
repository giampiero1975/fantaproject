<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\FbrefScrapingService; // Il tuo servizio di scraping
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage; // Per salvare il file JSON di debug
use Illuminate\Support\Str;             // Per generare nomi di file sicuri

class ScrapeFbrefTeamStatsCommand extends Command
{
    /**
     * The name and signature of the console command.
     * Esempio di come chiamarlo:
     * php artisan fbref:scrape-team "https://fbref.com/it/squadre/4cceedfc/Statistiche-Pisa"
     * php artisan fbref:scrape-team "URL_ALTRA_SQUADRA" --team_id=XYZ
     *
     * @var string
     */
    protected $signature = 'fbref:scrape-team
                            {url : L\'URL completo della pagina della squadra su FBRef (es. https://fbref.com/it/squadre/id/Statistiche-NomeSquadra)}
                            {--team_id= : ID opzionale della squadra nel tuo database per associare i dati (non usato in questa versione base)}';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Esegue lo scraping delle statistiche di una squadra da un URL FBRef e salva i dati grezzi in un file JSON.';
    
    private $scrapingService;
    
    /**
     * Create a new command instance.
     *
     * @param \App\Services\FbrefScrapingService $scrapingService
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
    public function handle()
    {
        $url = $this->argument('url');
        $teamId = $this->option('team_id');
        
        $this->info("Avvio scraping per l'URL: {$url}");
        if ($teamId) {
            $this->line("> ID Squadra (opzionale) per riferimento futuro: {$teamId}");
        }
        
        // Chiama il servizio per ottenere i dati
        $data = $this->scrapingService->setTargetUrl($url)->scrapeTeamStats();
        
        if (isset($data['error'])) {
            $this->error("Errore critico durante lo scraping: " . $data['error']);
            Log::channel('stderr')->error("Errore scraping FBRef per URL {$url}: " . $data['error']);
            return Command::FAILURE;
        }
        
        $this->info('Scraping dei dati grezzi completato dal servizio.');
        $this->line('--- Riepilogo Tabelle Processate ---');
        
        $foundDataCount = 0;
        if (is_array($data) && !empty($data)) {
            foreach ($data as $tableKey => $tableContent) {
                if ($tableContent === null) {
                    // Questo caso potrebbe non verificarsi se il servizio imposta sempre un array o un errore
                    $this->warn("- Tabella '{$tableKey}': Non trovata o nessun dato (valore null).");
                    continue;
                }
                if (isset($tableContent['error'])) {
                    $this->error("- Tabella '{$tableKey}': Errore durante il parsing - " . $tableContent['error']);
                    continue;
                }
                
                $rowCount = is_array($tableContent) ? count($tableContent) : 0;
                if ($rowCount > 0) {
                    $this->line("[OK] Tabella '{$tableKey}': {$rowCount} righe estratte.");
                    $foundDataCount++;
                } else {
                    $this->line("[INFO] Tabella '{$tableKey}': 0 righe estratte (vuota o solo header).");
                }
            }
        } else {
            $this->warn("Nessun dato o struttura dati inattesa ricevuta dal servizio di scraping.");
        }
        
        if ($foundDataCount > 0) {
            $this->info("\nAlmeno una tabella con dati è stata elaborata con successo.");
        } else {
            $this->warn("\nNessun dato tabellare significativo è stato estratto (o tutte le tabelle erano vuote/hanno avuto errori di parsing).");
        }
        
        // --- MODIFICA PER SALVARE IN SOTTOCARTELLA ---
        $urlPath = parse_url($url, PHP_URL_PATH);
        $baseFileName = Str::slug(basename($urlPath ?: 'unknown_url'));
        $urlHash = substr(md5($url), 0, 6);
        
        $fileName = 'fbref_data_' . $baseFileName . '_' . $urlHash . '_' . date('Ymd_His') . '.json';
        $subfolder = 'scraping'; // Definisci la tua sottocartella
        $filePath = $subfolder . '/' . $fileName; // Anteponi la sottocartella al nome del file
        
        try {
            Storage::disk('local')->put($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            // Aggiorna il messaggio di output per mostrare il percorso corretto
            $this->info("\nOutput completo dei dati grezzi salvato in: storage/app/{$filePath}");
        } catch (\Exception $e) {
            $this->error("Impossibile salvare il file JSON di debug: " . $e->getMessage());
        }
        // --- FINE MODIFICA ---
        
        Log::info("Comando fbref:scrape-team completato per URL {$url}.");
        $this->info("\nComando terminato.");
        return Command::SUCCESS;
    }
}