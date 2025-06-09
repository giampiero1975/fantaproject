<?php

namespace App\Services;

use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LeagueStandingsScrapingService
{
    protected Client $client;
    protected string $targetUrl;
    
    public function __construct(Client $client)
    {
        $this->client = $client;
    }
    
    public function setTargetUrl(string $url): self
    {
        $this->targetUrl = $url;
        return $this;
    }
    
    /**
     * Raschia i dati di una tabella di classifica da FBRef.
     *
     * @return array Restituisce un array con i dati della classifica o un array con 'error'.
     */
    public function scrapeStandings(): array
    {
        if (empty($this->targetUrl)) {
            return ['error' => 'Target URL non impostato per lo scraping delle classifiche di lega.'];
        }
        
        Log::info("LeagueStandingsScrapingService: Inizio scraping classifica da {$this->targetUrl}");
        
        try {
            $crawler = $this->client->request('GET', $this->targetUrl);
            
            // Trova la tabella della classifica (solitamente ha l'ID 'resultsYYYY-YYYYN_overall')
            // Cerca ID che iniziano con 'results' e contengono '_overall' per le classifiche generali
            $tableNode = $crawler->filter('table[id^="results"][id*="_overall"]')->first();
            
            if ($tableNode->count() === 0) {
                return ['error' => 'Nessuna tabella di classifica generale trovata sulla pagina: ' . $this->targetUrl];
            }
            
            $standingsData = [];
            $headers = [];
            
            // Estrai le intestazioni della tabella, usando data-stat come chiave se disponibile
            $tableNode->filter('thead th')->each(function (Crawler $headerNode) use (&$headers) {
                $dataStat = $headerNode->attr('data-stat');
                if ($dataStat) {
                    $headers[] = $dataStat; // Preferiamo data-stat come chiave
                } else {
                    $headers[] = trim($headerNode->text()); // Fallback al testo se data-stat non c'è
                }
            });
                $headers = array_filter($headers, fn($h) => !empty($h));
                
                Log::debug("LeagueStandingsScrapingService: Intestazioni tabella classifica: " . json_encode($headers));
                
                // Estrai i dati delle righe
                $tableNode->filter('tbody tr')->each(function (Crawler $rowNode) use (&$standingsData, $headers) {
                    $rowData = [];
                    // Gestisci rank e team separatamente, usando le chiavi che l'API fornisce ('rank', 'team')
                    $rankNode = $rowNode->filter('th[data-stat="rank"]');
                    if ($rankNode->count() > 0) {
                        $rowData['rank'] = trim($rankNode->text()); // Chiave 'rank'
                    }
                    
                    $teamNode = $rowNode->filter('th[data-stat="team"] a');
                    if ($teamNode->count() > 0) {
                        $rowData['team'] = trim($teamNode->text()); // Chiave 'team'
                        // L'ID del team può essere estratto dall'URL, ma per ora ci basiamo sul nome/TLA per il lookup nel DB locale
                        // $teamFbrefId = basename(dirname($teamNode->attr('href'))); // Es. '9aad3a77'
                        // $rowData['team_fbref_id'] = $teamFbrefId;
                    }
                    
                    $rowNode->filter('td')->each(function (Crawler $cellNode) use (&$rowData, $headers) {
                        $dataStat = $cellNode->attr('data-stat');
                        if ($dataStat && in_array($dataStat, $headers)) { // Assicurati che data-stat sia valido e presente nelle intestazioni
                            $rowData[$dataStat] = trim($cellNode->text());
                        }
                        // Non usare un fallback generico 'col_X' qui, basiamoci solo su data-stat per precisione
                    });
                        
                        if (!empty($rowData) && (isset($rowData['rank']) || isset($rowData['team']))) {
                            $standingsData[] = $rowData;
                        }
                });
                    
                    Log::info("LeagueStandingsScrapingService: Scraping classifica completato per {$this->targetUrl}. Trovati " . count($standingsData) . " record.");
                    return ['standings' => $standingsData];
                    
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();
            Log::error("LeagueStandingsScrapingService: Errore client HTTP ({$statusCode}) durante lo scraping classifica di {$this->targetUrl}: " . $responseBody);
            return ['error' => "Errore API/client classifica: Status {$statusCode} - " . Str::limit($responseBody, 200)];
        } catch (\Exception $e) {
            Log::error("LeagueStandingsScrapingService: Errore generico durante lo scraping classifica di {$this->targetUrl}: " . $e->getMessage(), ['exception' => $e]);
            return ['error' => "Errore sconosciuto classifica: " . $e->getMessage()];
        }
    }
}