<?php

namespace App\Services;

use Goutte\Client as GoutteClient;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage; // Per il debug dell'HTML, se necessario

class FbrefScrapingService
{
    private $goutteClient;
    private $targetUrl;
    
    public function __construct()
    {
        $this->goutteClient = new GoutteClient();
        
        // Imposta un User-Agent. Puoi usare quello personalizzato o uno più standard.
        // $this->goutteClient->setServerParameter('HTTP_USER_AGENT', "FantaprojectBot/1.0 (contatto@example.com)");
        $this->goutteClient->setServerParameter('HTTP_USER_AGENT', "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0.0.0 Safari/537.36");
        
        
        // Configura Guzzle (usato da Goutte) se necessario per timeout, SSL, ecc.
        // Esempio: client Guzzle con timeout e senza verifica SSL (usa con cautela!)
        // $guzzleClientOptions = [
        //     'timeout' => 60,
        //     'verify' => false, // Disabilita la verifica SSL - sconsigliato in produzione
        //     // Altre opzioni di Guzzle se necessario
        // ];
        // $guzzleClient = new \GuzzleHttp\Client($guzzleClientOptions);
        // $this->goutteClient->setClient($guzzleClient);
    }
    
    /**
     * Imposta l'URL target per lo scraping.
     * @param string $url
     * @return self
     */
    public function setTargetUrl(string $url): self
    {
        $this->targetUrl = $url;
        return $this;
    }
    
    /**
     * Esegue lo scraping delle statistiche della squadra dall'URL impostato.
     * @return array Dati estratti o array con chiave 'error'.
     */
    public function scrapeTeamStats(): array
    {
        if (!$this->targetUrl) {
            Log::error('FbrefScrapingService: Target URL non impostato.');
            return ['error' => 'Target URL non impostato.'];
        }
        
        try {
            Log::info("FbrefScrapingService: Inizio scraping da {$this->targetUrl}");
            $crawler = $this->goutteClient->request('GET', $this->targetUrl);
            
            // DEBUG: Puoi decommentare le righe seguenti per salvare l'HTML grezzo ricevuto
            //        e analizzarlo se le tabelle non vengono trovate.
            /*
             $htmlContent = $crawler->html();
             $debugFileName = 'fbref_dump_' . Str::slug(basename($this->targetUrl)) . '.html';
             Storage::disk('local')->put($debugFileName, $htmlContent);
             Log::info("FbrefScrapingService: HTML grezzo salvato in storage/app/{$debugFileName}. Lunghezza: " . strlen($htmlContent));
             if (strlen($htmlContent) < 2000) {
             Log::warning("FbrefScrapingService: ATTENZIONE - L'HTML ricevuto è molto corto. Potrebbe essere una pagina di errore/blocco o contenuto incompleto.");
             }
             */
            
        } catch (\Exception $e) {
            Log::error("FbrefScrapingService: Impossibile raggiungere la pagina {$this->targetUrl}: " . $e->getMessage());
            return ['error' => 'Impossibile raggiungere la pagina: ' . $e->getMessage()];
        }
        
        $scrapedData = [];
        
        // Configurazione delle tabelle da estrarre con selettori CSS dinamici
        $tableConfigs = [
            'statistiche_ordinarie'   => [
                'selector'    => 'table[id^="stats_standard_"]',
                'description' => 'Statistiche ordinarie'
            ],
            'difesa_porta'            => [
                'selector'    => 'table[id^="stats_keeper_"]:not([id*="_adv_"])',
                'description' => 'Statistiche portiere (base)'
            ],
            'difesa_porta_avanzata'   => [
                'selector'    => 'table[id^="stats_keeper_adv_"]',
                'description' => 'Statistiche portiere avanzate'
            ],
            // Aggiungi altre tabelle che FBRef fornisce per le squadre qui, se necessario:
            'tiri'                    => ['selector' => 'table[id^="stats_shooting_"]', 'description' => 'Statistiche tiri'],
            'passaggi'                => ['selector' => 'table[id^="stats_passing_"]', 'description' => 'Statistiche passaggi'],
            'tipi_passaggi'           => ['selector' => 'table[id^="stats_passing_types_"]', 'description' => 'Statistiche tipi di passaggi'],
            'creazione_azioni_da_gol' => ['selector' => 'table[id^="stats_gca_"]', 'description' => 'Statistiche creazione azioni da gol'],
            'difesa'                  => ['selector' => 'table[id^="stats_defense_"]', 'description' => 'Statistiche difesa'],
            'possesso_palla'          => ['selector' => 'table[id^="stats_possession_"]', 'description' => 'Statistiche possesso palla'],
            'tempo_di_gioco'          => ['selector' => 'table[id^="stats_playing_time_"]', 'description' => 'Statistiche tempo di gioco'],
            // 'varie'                   => ['selector' => 'table[id^="stats_misc_"]', 'description' => 'Statistiche varie'],
        ];
        
        foreach ($tableConfigs as $key => $config) {
            $selector = $config['selector'];
            $tableNodes = $crawler->filter($selector); // Cerca tutti i nodi che corrispondono
            
            if ($tableNodes->count() > 0) {
                if ($tableNodes->count() > 1) {
                    Log::warning("FbrefScrapingService: Trovate {$tableNodes->count()} tabelle per '{$config['description']}' usando selettore '{$selector}'. Verrà usata la prima.");
                }
                $tableNode = $tableNodes->first(); // Prendi il primo nodo corrispondente
                
                Log::info("FbrefScrapingService: Trovata tabella per '{$config['description']}' usando selettore '{$selector}'. Inizio parsing.");
                $parsedTable = $this->parseFbrefTable($tableNode, $selector); // Passa il selettore per il logging
                
                if (isset($parsedTable['error'])) {
                    Log::warning("FbrefScrapingService: Errore durante il parsing della tabella '{$key}' (trovata con selettore '{$selector}'): " . $parsedTable['error']);
                }
                $scrapedData[$key] = $parsedTable;
            } else {
                Log::warning("FbrefScrapingService: Tabella per '{$config['description']}' (Chiave: '{$key}', Selettore: '{$selector}') non trovata su {$this->targetUrl}");
                $scrapedData[$key] = null; // O un array vuoto
            }
        }
        Log::info("FbrefScrapingService: Scraping completato per {$this->targetUrl}");
        return $scrapedData;
    }
    
    /**
     * Fa il parsing di una tabella HTML di FBRef.
     * @param Crawler $tableNode Il nodo Crawler della tabella.
     * @param string $tableDebugId Un identificatore per il logging.
     * @return array Array di dati della tabella o array con chiave 'error'.
     */
    private function parseFbrefTable(Crawler $tableNode, string $tableDebugId = 'unknown_table'): array
    {
        $tableData = [];
        $headers = [];
        
        // Estrai gli headers dall'ultima riga di <thead>
        $headerRowNode = $tableNode->filter('thead tr')->last();
        if ($headerRowNode->count() > 0) {
            $headerRowNode->filter('th')->each(function (Crawler $th) use (&$headers) {
                $headers[] = trim($th->text());
            });
        }
        
        if (empty($headers)) {
            Log::error("FbrefScrapingService: Headers non trovati per la tabella identificata da '{$tableDebugId}'. La struttura della tabella potrebbe essere inattesa o cambiata.");
            return ['error' => "Headers non trovati per tabella {$tableDebugId}"];
        }
        
        // Itera sulle righe (<tr>) dentro <tbody>
        $tableNode->filter('tbody tr')->each(function (Crawler $rowNode, $rowIndex) use (&$tableData, $headers, $tableDebugId) {
            $rowData = [];
            // Prendi tutte le celle (sia <th> per il nome giocatore, che <td> per i dati) in ordine
            $cells = $rowNode->filter('th, td');
            
            // Salta le righe che sono chiaramente divisori (spesso con classe 'spacer')
            // o righe di intestazione/piede di gruppo che usano colspan
            if ($rowNode->matches('.spacer') || $rowNode->filter('td[colspan]')->count() > 0) {
                // Log::debug("FbrefScrapingService: Riga {$rowIndex} saltata (spacer/colspan) nella tabella {$tableDebugId}.");
                return; // Salta questa iterazione (riga)
            }
            
            if ($cells->count() === count($headers)) {
                $cells->each(function (Crawler $cellNode, $index) use (&$rowData, $headers) {
                    // Assicurati che l'header esista per evitare errori "Undefined offset"
                    if (isset($headers[$index])) {
                        $rowData[$headers[$index]] = trim($cellNode->text());
                    } else {
                        // Caso anomalo, potrebbe indicare un problema con l'estrazione degli header
                        // o una riga con una struttura di celle imprevista
                        $rowData['col_imprevista_' . $index] = trim($cellNode->text());
                        Log::warning("FbrefScrapingService: Header mancante per l'indice {$index} nella riga {$rowIndex} della tabella {$tableDebugId}.");
                    }
                });
                    if (!empty($rowData)) { // Aggiungi solo se la riga ha prodotto dati
                        $tableData[] = $rowData;
                    }
            } else {
                // Logga discrepanze se una riga in tbody non corrisponde al numero di header
                // e non è una riga che ci aspettiamo di saltare (es. righe di totali parziali)
                if ($cells->count() > 0) { // Non loggare righe completamente vuote
                    Log::warning("FbrefScrapingService: Discrepanza numero celle/header ({$cells->count()}/".count($headers).") per la riga {$rowIndex} nella tabella {$tableDebugId}. Contenuto riga (prime 100 chars): " . substr(preg_replace('/\s+/', ' ', trim($rowNode->text())), 0, 100));
                }
            }
        });
            return $tableData;
    }
}