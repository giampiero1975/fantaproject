<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Team;
use App\Models\TeamHistoricalStanding;
use Illuminate\Support\Facades\Log;
use League\Csv\Reader;
use League\Csv\Statement;
use App\Models\ImportLog;

class TeamsImportStandingsFile extends Command
{
    protected $signature = 'teams:import-standings-file {filepath} {--season-start-year=} {--league-name=Serie A} {--create-missing-teams=false} {--default-tier-for-new=4} {--is-serie-a-league=true}';
    protected $description = 'Imports historical team standings from a CSV file, optionally creating missing teams';
    
    public function __construct()
    {
        parent::__construct();
    }
    
    // Funzione di normalizzazione base (puoi affinarla se necessario)
    // Dentro TeamsImportStandingsFile.php
    private function normalizeTeamNameForMatching(string $name): string
    {
        // Tenta la translitterazione per rimuovere accenti se l'estensione intl è disponibile
        if (function_exists('transliterator_transliterate')) {
            $name = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower();', $name);
        } else {
            // Fallback manuale molto limitato
            $name = strtolower(trim($name));
            $accents = ['á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 'ü'=>'u', 'ñ'=>'n', 'ç'=>'c', 'ò'=>'o', 'à'=>'a', 'è'=>'e', 'ì'=>'i', 'ù'=>'u'];
            $name = strtr($name, $accents);
        }
        
        $commonWords = ['fc', 'cfc', 'bc', 'ac', 'ssc', 'us', 'calcio', '1909', '1913', '1919', 'spa', 'srl'];
        $patterns = [];
        foreach ($commonWords as $word) {
            $patterns[] = '/\b' . preg_quote($word, '/') . '\b/';
        }
        $patterns[] = '/[0-9]{4}/'; // Rimuove anni a 4 cifre
        $name = preg_replace($patterns, '', $name);
        
        $name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name); // Mantieni solo lettere (Unicode), numeri e spazi
        $name = preg_replace('/\s+/', ' ', $name);
        return trim($name);
    }
    
    public function handle()
    {
        $filePath = $this->argument('filepath');
        $seasonStartYear = $this->option('season-start-year');
        $leagueName = $this->option('league-name');
        $createMissingTeams = filter_var($this->option('create-missing-teams'), FILTER_VALIDATE_BOOLEAN);
        $defaultTierForNew = (int)$this->option('default-tier-for-new');
        $isSerieALeague = filter_var($this->option('is-serie-a-league'), FILTER_VALIDATE_BOOLEAN);
        
        // ... (validazioni iniziali come prima) ...
        if (!file_exists($filePath)) {
            $this->error("File non trovato: {$filePath}");
            Log::error(self::class . ": File non trovato {$filePath}");
            return Command::FAILURE;
        }
        if (!$seasonStartYear || !ctype_digit($seasonStartYear) || strlen($seasonStartYear) !== 4) {
            $this->error("Anno di inizio stagione non valido o mancante. Usa --season-start-year=YYYY (es. 2021 per la stagione 2021-22).");
            Log::error(self::class . ": Anno di inizio stagione non valido o mancante: {$seasonStartYear}");
            return Command::FAILURE;
        }
        $seasonYearString = $seasonStartYear . '-' . substr((int)$seasonStartYear + 1, 2, 2);
        
        $this->info("Importazione classifica da file: {$filePath} per la stagione {$seasonYearString}, Lega: {$leagueName}");
        // ... (altri info log) ...
        Log::info(self::class . ": Inizio importazione da {$filePath} per stagione {$seasonYearString}, lega {$leagueName}. Creazione team: " . ($createMissingTeams ? 'SI':'NO'));
        
        $startTime = microtime(true);
        $importedCount = 0;
        $createdTeamCount = 0;
        $notFoundSkippedCount = 0;
        $rowCount = 0;
        $errorMessages = []; // Per collezionare errori specifici delle righe
        
        try {
            $csv = Reader::createFromPath($filePath, 'r');
            // Tenta di rilevare il delimitatore, altrimenti usa il default ';'
            $delimiter = $this->detectDelimiter($filePath);
            $this->info("Utilizzo delimitatore: '{$delimiter}'");
            $csv->setDelimiter($delimiter);
            $csv->setHeaderOffset(0);
            
            $stmt = Statement::create();
            $records = $stmt->process($csv);
            
            foreach ($records as $index => $record) {
                $rowCount++;
                // ... (logica di processing record come prima) ...
                // Assicurati che dentro il loop, se salti una riga o c'è un errore,
                // lo aggiungi a $errorMessages o incrementi un contatore di errori.
                if (!isset($record['NomeSquadraDB']) || empty(trim($record['NomeSquadraDB']))) {
                    $msg = "Riga #{$rowCount} saltata: 'NomeSquadraDB' mancante o vuoto nel CSV.";
                    $this->warn($msg); Log::warning(self::class . ": " . $msg . " Record: ".json_encode($record));
                    $errorMessages[] = $msg;
                    continue;
                }
                $teamNameFromCsv = trim($record['NomeSquadraDB']);
                $normalizedCsvName = $this->normalizeTeamNameForMatching($teamNameFromCsv);
                
                $team = Team::query()
                ->whereRaw('LOWER(name) = ?', [$normalizedCsvName])
                ->orWhereRaw('LOWER(short_name) = ?', [$normalizedCsvName])
                ->first();
                
                if (!$team && $createMissingTeams) {
                    // ... (logica creazione team) ...
                    try {
                        $team = Team::create([
                            'name' => $teamNameFromCsv,
                            'short_name' => $teamNameFromCsv,
                            'serie_a_team' => $isSerieALeague,
                            'tier' => $defaultTierForNew,
                            'api_football_data_id' => null,
                        ]);
                        $this->info("Team '{$team->name}' (ID: {$team->id}) creato.");
                        Log::info(self::class . ": Creato nuovo team '{$team->name}' (ID: {$team->id}) da CSV.");
                        $createdTeamCount++;
                    } catch (\Exception $e) {
                        $msg = "Errore creazione team '{$teamNameFromCsv}': " . $e->getMessage();
                        $this->error($msg); Log::error(self::class . ": " . $msg);
                        $errorMessages[] = $msg;
                        $notFoundSkippedCount++;
                        continue;
                    }
                } elseif (!$team) {
                    $msg = "Team '{$teamNameFromCsv}' (CSV riga #{$rowCount}) non trovato. Riga saltata (creazione disattivata).";
                    $this->warn($msg); Log::warning(self::class . ": " . $msg);
                    $notFoundSkippedCount++;
                    $errorMessages[] = $msg;
                    continue;
                }
                
                $dataToStore = [ /* ... come prima ... */ ];
                // ... (resto della logica per preparare $dataToStore e updateOrCreate TeamHistoricalStanding come prima)
                $dataToStore = [
                    'position' => isset($record['Posizione']) && is_numeric($record['Posizione']) ? (int)$record['Posizione'] : null,
                    'played_games' => isset($record['Giocate']) && is_numeric($record['Giocate']) ? (int)$record['Giocate'] : null,
                    'won' => isset($record['V']) && is_numeric($record['V']) ? (int)$record['V'] : null,
                    'draw' => isset($record['N']) && is_numeric($record['N']) ? (int)$record['N'] : null,
                    'lost' => isset($record['P']) && is_numeric($record['P']) ? (int)$record['P'] : null,
                    'points' => isset($record['Punti']) && is_numeric($record['Punti']) ? (int)$record['Punti'] : null,
                    'goals_for' => isset($record['GF']) && is_numeric($record['GF']) ? (int)$record['GF'] : null,
                    'goals_against' => isset($record['GS']) && is_numeric($record['GS']) ? (int)$record['GS'] : null,
                    'data_source' => 'manual_csv_import',
                ];
                if ($dataToStore['goals_for'] !== null && $dataToStore['goals_against'] !== null) {
                    $dataToStore['goal_difference'] = $dataToStore['goals_for'] - $dataToStore['goals_against'];
                } else {
                    $dataToStore['goal_difference'] = null;
                }
                
                TeamHistoricalStanding::updateOrCreate(
                    [
                        'team_id' => $team->id,
                        'season_year' => $seasonYearString,
                        'league_name' => $leagueName,
                    ],
                    $dataToStore
                    );
                $importedCount++;
            }
            
            $duration = microtime(true) - $startTime;
            $summary = "Importazione CSV {$filePath} completata per {$seasonYearString} in " . round($duration, 2) . " sec. Letti: {$rowCount}, Classifiche salvate/agg.: {$importedCount}, Team creati: {$createdTeamCount}, Team non trovati/saltati: {$notFoundSkippedCount}.";
            
            ImportLog::create([
                'original_file_name' => basename($filePath),
                'import_type' => 'standings_csv_import', // Tipo specifico per questo import
                'status' => ($notFoundSkippedCount === 0 && empty($errorMessages)) ? 'successo' : 'parziale',
                'details' => $summary . (empty($errorMessages) ? '' : " Errori: " . implode('; ', $errorMessages)),
                'rows_processed' => $rowCount,
                'rows_created' => $importedCount, // Numero di record classifica scritti/aggiornati
                'rows_updated' => 0, // updateOrCreate non distingue facilmente, qui contiamo come "created" in senso lato
                'rows_failed' => $notFoundSkippedCount + (count($errorMessages) - $notFoundSkippedCount) // Fallimenti nel trovare team + altri errori
            ]);
            
            $this->info($summary);
            Log::info(self::class . ": " . $summary);
            
        } catch (\League\Csv\Exception $e) {
            // ... (gestione eccezione League\Csv) ...
            $this->error("Errore CSV specifico della libreria: " . $e->getMessage());
            Log::error(self::class . ": Eccezione League\Csv {$filePath}: " . $e->getMessage());
            ImportLog::create([ /* ... log fallimento ... */ ]);
            return Command::FAILURE;
        } catch (\Exception $e) {
            // ... (gestione eccezione generica) ...
            $this->error("Errore generico importazione CSV: " . $e->getMessage());
            Log::error(self::class . ": Eccezione generica importazione CSV {$filePath}: " . $e->getMessage());
            ImportLog::create([ /* ... log fallimento ... */ ]);
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }
    
    // Funzione helper per rilevare il delimitatore (opzionale, ma utile)
    private function detectDelimiter(string $filePath): string
    {
        $delimiters = [";" => 0, "," => 0, "\t" => 0, "|" => 0];
        $file = fopen($filePath, "r");
        if ($file === false) return ';'; // Default
        $line = fgets($file);
        fclose($file);
        if ($line === false) return ';'; // Default
        
        foreach ($delimiters as $delimiter => &$count) {
            $count = count(str_getcsv($line, $delimiter));
        }
        return array_search(max($delimiters), $delimiters) ?: ';';
    }
}