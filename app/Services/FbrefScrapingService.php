<?php

namespace App\Services;

use Goutte\Client as GoutteClient;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str; // Manteniamo l'import per Str, anche se non usato per gli header qui

class FbrefScrapingService
{
    private $goutteClient;
    private $targetUrl;
    
    public function __construct()
    {
        $this->goutteClient = new GoutteClient();
        $this->goutteClient->setServerParameter('HTTP_USER_AGENT', "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0.0.0 Safari/537.36");
    }
    
    /**
     * Imposta l'URL target per lo scraping.
     * @param string $url L'URL della pagina da cui fare lo scraping.
     * @return self
     */
    public function setTargetUrl(string $url): self
    {
        $this->targetUrl = $url;
        return $this;
    }
    
    /**
     * Esegue lo scraping delle statistiche della squadra dall'URL impostato.
     * Cerca di estrarre tutte le tabelle il cui ID inizia con "stats_".
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
        } catch (\Exception $e) {
            Log::error("FbrefScrapingService: Impossibile raggiungere la pagina {$this->targetUrl}: " . $e->getMessage());
            return ['error' => 'Impossibile raggiungere la pagina: ' . $e->getMessage()];
        }
        
        $scrapedData = [];
        
        // Seleziona tutte le tabelle il cui ID inizia con "stats_"
        // Escludiamo alcune tabelle di servizio o navigazione per specificità
        $allStatTableNodes = $crawler->filter('table[id^="stats_"]:not([class*="switcher_"]):not([class*="small_text"])');
        
        Log::info("FbrefScrapingService: Trovate {$allStatTableNodes->count()} tabelle potenziali con ID che inizia per 'stats_'.");
        
        $allStatTableNodes->each(function (Crawler $tableNode) use (&$scrapedData) {
            $tableId = $tableNode->attr('id'); // Ottieni l'ID della tabella
            $captionNode = $tableNode->filter('caption'); // Cerca la didascalia (caption)
            
            $tableKey = $tableId; // Chiave di default nell'array dei risultati
            $descriptionForLog = "Tabella ID: {$tableId}";
            
            if ($captionNode->count() > 0) {
                $captionText = trim($captionNode->text());
                if (!empty($captionText)) {
                    // Manteniamo Str::slug per la chiave della tabella principale (es. "statistiche_ordinarie")
                    $tableKey = Str::slug($captionText, '_');
                    $descriptionForLog = "Tabella '{$captionText}' (ID: {$tableId})";
                }
            }
            
            // Gestione semplice di collisioni di chiavi (se due didascalie diverse generano lo stesso slug)
            if (array_key_exists($tableKey, $scrapedData)) {
                $originalTableKey = $tableKey;
                $tableKey = $tableKey . '_' . $tableId; // Rendi la chiave unica aggiungendo l'ID
                Log::warning("FbrefScrapingService: Collisione di chiavi per '{$originalTableKey}'. Uso nuova chiave '{$tableKey}' per ID '{$tableId}'.");
                if (array_key_exists($tableKey, $scrapedData)) {
                    Log::error("FbrefScrapingService: Collisione di chiavi irrisolvibile per ID '{$tableId}'. Salto.");
                    return;
                }
            }
            
            Log::info("FbrefScrapingService: Inizio parsing per {$descriptionForLog}.");
            $parsedTable = $this->parseFbrefTable($tableNode, $descriptionForLog);
            
            if (isset($parsedTable['error'])) {
                Log::warning("FbrefScrapingService: Errore durante il parsing per {$descriptionForLog}: " . $parsedTable['error']);
                $scrapedData[$tableKey] = ['error' => $parsedTable['error'], 'original_id' => $tableId, 'description' => $descriptionForLog];
            } else {
                if (empty($parsedTable)) {
                    Log::info("FbrefScrapingService: Nessun dato estratto (o tabella vuota) per {$descriptionForLog}.");
                }
                $scrapedData[$tableKey] = $parsedTable;
            }
        });
            
            Log::info("FbrefScrapingService: Scraping completato per {$this->targetUrl}. Processate " . count($scrapedData) . " tabelle.");
            return $scrapedData;
    }
    
    /**
     * Fa il parsing di una tabella HTML standard di FBRef.
     * RISTABILITO: Estrae gli header esattamente come appaiono (non normalizzati).
     * @param Crawler $tableNode Il nodo Crawler della tabella.
     * @param string $tableDebugInfo Un identificatore per il logging.
     * @return array Array di dati della tabella o array con chiave 'error'.
     */
    private function parseFbrefTable(Crawler $tableNode, string $tableDebugInfo = 'unknown_table'): array
    {
        $tableData = [];
        $headers = [];
        
        // Estrae gli header delle colonne dall'ultima riga di thead
        $headerRowNode = $tableNode->filter('thead tr')->last();
        if ($headerRowNode->count() > 0) {
            $headerRowNode->filter('th')->each(function (Crawler $th) use (&$headers) {
                // RISTABILITO: Non normalizzare gli header qui. Li prendiamo come sono.
                $headers[] = trim($th->text());
            });
        }
        
        if (empty($headers)) {
            Log::error("FbrefScrapingService: Headers non trovati per la tabella '{$tableDebugInfo}'. La struttura potrebbe essere cambiata o la tabella non è standard.");
            return ['error' => "Headers non trovati per tabella: {$tableDebugInfo}"];
        }
        
        // Itera sulle righe (<tr>) del corpo della tabella (tbody)
        $tableNode->filter('tbody tr')->each(function (Crawler $rowNode, $rowIndex) use (&$tableData, $headers, $tableDebugInfo) {
            $rowData = [];
            $cells = $rowNode->filter('th, td');
            
            // Salta righe "spacer" o quelle con colspan che spesso non contengono dati di giocatori
            if ($rowNode->matches('.spacer') || $rowNode->filter('td[colspan]')->count() > 0 || $cells->count() == 0) {
                return;
            }
            
            // Itera sulle celle della riga e le mappa agli header originali
            $cells->each(function (Crawler $cellNode, $index) use (&$rowData, $headers, $rowIndex, $tableDebugInfo) {
                if (isset($headers[$index])) {
                    // Usa l'header originale come chiave
                    $rowData[$headers[$index]] = trim($cellNode->text());
                } else {
                    Log::warning("FbrefScrapingService: Header mancante per indice {$index}, riga {$rowIndex}, tabella {$tableDebugInfo}. Contenuto cella: " . substr(preg_replace('/\s+/', ' ', trim($cellNode->text())), 0, 50));
                }
            });
                
                if (!empty($rowData)) {
                    $tableData[] = $rowData;
                } else {
                    Log::debug("FbrefScrapingService: Riga {$rowIndex} non ha prodotto dati validi nella tabella {$tableDebugInfo}.");
                }
        });
            return $tableData;
    }
}