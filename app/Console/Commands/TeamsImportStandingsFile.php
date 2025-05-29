<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Team;
use App\Models\TeamHistoricalStanding;
use Illuminate\Support\Facades\Log;
use League\Csv\Reader;
use League\Csv\Statement;

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
        // Opzioni per la creazione automatica di team mancanti
        $createMissingTeams = filter_var($this->option('create-missing-teams'), FILTER_VALIDATE_BOOLEAN);
        $defaultTierForNew = (int)$this->option('default-tier-for-new');
        $isSerieALeague = filter_var($this->option('is-serie-a-league'), FILTER_VALIDATE_BOOLEAN);
        
        
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
        if ($createMissingTeams) {
            $this->info("Opzione --create-missing-teams ATTIVA. I team non trovati verranno creati con tier default: {$defaultTierForNew} e serie_a_team: " . ($isSerieALeague ? 'true' : 'false'));
        }
        Log::info(self::class . ": Inizio importazione da {$filePath} per stagione {$seasonYearString}, lega {$leagueName}. Creazione team: " . ($createMissingTeams ? 'SI':'NO'));
        
        try {
            $csv = Reader::createFromPath($filePath, 'r');
            $csv->setDelimiter(';');
            $csv->setHeaderOffset(0);
            
            $stmt = Statement::create();
            $records = $stmt->process($csv);
            
            $importedCount = 0;
            $createdTeamCount = 0;
            $notFoundSkippedCount = 0; // Contatore per i team non trovati e saltati (se createMissingTeams è false)
            $rowCount = 0;
            
            foreach ($records as $index => $record) {
                $rowCount++;
                Log::debug(self::class . ": Processo riga CSV #{$rowCount}, Dati grezzi: " . json_encode($record));
                
                if (!isset($record['NomeSquadraDB']) || empty(trim($record['NomeSquadraDB']))) {
                    $this->warn("Riga #{$rowCount} saltata: 'NomeSquadraDB' mancante o vuoto nel CSV.");
                    Log::warning(self::class . ": Riga #{$rowCount} CSV saltata, 'NomeSquadraDB' mancante/vuoto. Record: ".json_encode($record));
                    continue;
                }
                $teamNameFromCsv = trim($record['NomeSquadraDB']);
                $normalizedCsvName = $this->normalizeTeamNameForMatching($teamNameFromCsv);
                
                // Tenta di trovare il team in modo più flessibile
                $team = Team::query()
                ->whereRaw('LOWER(name) = ?', [$normalizedCsvName])
                ->orWhereRaw('LOWER(short_name) = ?', [$normalizedCsvName])
                ->first();
                
                if (!$team && $createMissingTeams) {
                    $this->line("Team '{$teamNameFromCsv}' non trovato. Lo creo con tier {$defaultTierForNew} e serie_a_team = " . ($isSerieALeague ? 'true':'false'));
                    try {
                        $team = Team::create([
                            'name' => $teamNameFromCsv, // Usa il nome dal CSV come nome principale
                            'short_name' => $teamNameFromCsv, // Inizialmente uguale al nome, puoi modificarlo dopo
                            'serie_a_team' => $isSerieALeague, // Basato sull'opzione
                            'tier' => $defaultTierForNew,
                            'api_football_data_team_id' => null, // Sarà popolato da un altro comando se necessario
                        ]);
                        $this->info("Team '{$team->name}' (ID: {$team->id}) creato con successo.");
                        Log::info(self::class . ": Creato nuovo team '{$team->name}' (ID: {$team->id}) da CSV.");
                        $createdTeamCount++;
                    } catch (\Exception $e) {
                        $this->error("Errore durante la creazione del team '{$teamNameFromCsv}': " . $e->getMessage());
                        Log::error(self::class . ": Errore creazione team '{$teamNameFromCsv}' da CSV: " . $e->getMessage());
                        $notFoundSkippedCount++; // Lo contiamo come saltato se la creazione fallisce
                        continue;
                    }
                } elseif (!$team) {
                    $this->warn("Team '{$teamNameFromCsv}' dal CSV (riga #{$rowCount}) non trovato nel DB locale. Riga saltata (creazione disattivata).");
                    Log::warning(self::class . ": Team '{$teamNameFromCsv}' (CSV riga #{$rowCount}) non trovato. Creazione disattivata. Stagione {$seasonYearString}.");
                    $notFoundSkippedCount++;
                    continue;
                }
                
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
                Log::debug(self::class . ": Dati da salvare per '{$team->name}' (Stagione {$seasonYearString}): " . json_encode($dataToStore));
                
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
            $this->info("Importazione completata per {$seasonYearString}. Record letti: {$rowCount}. Record classifica salvati/aggiornati: {$importedCount}. Team creati: {$createdTeamCount}. Team non trovati/saltati: {$notFoundSkippedCount}.");
            Log::info(self::class . ": Importazione CSV {$filePath} completata. Letti: {$rowCount}, Salvati: {$importedCount}, Creati: {$createdTeamCount}, Saltati: {$notFoundSkippedCount}.");
            
        } catch (\League\Csv\Exception $e) {
            $this->error("Errore CSV specifico della libreria: " . $e->getMessage());
            Log::error(self::class . ": Eccezione League\Csv {$filePath}: " . $e->getMessage());
            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error("Errore generico durante l'importazione del file CSV: " . $e->getMessage());
            Log::error(self::class . ": Eccezione generica importazione CSV {$filePath}: " . $e->getMessage());
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }
}