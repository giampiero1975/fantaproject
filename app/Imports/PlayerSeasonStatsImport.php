<?php

namespace App\Imports;

use App\Models\HistoricalPlayerStat;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow; // Facilita l'accesso per nome colonna
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings; // Per CSV con delimitatori diversi
use Illuminate\Support\Facades\Log;
use Throwable;

class PlayerSeasonStatsImport implements ToCollection, WithHeadingRow, WithCustomCsvSettings
{
    public int $processedCount = 0;
    public int $createdCount = 0;
    public int $updatedCount = 0;
    private string $defaultLeagueForFile;
    
    // Passiamo un defaultLeague per i record di questo file se la colonna manca in qualche riga
    public function __construct(string $defaultLeagueForFile = 'Serie A')
    {
        $this->defaultLeagueForFile = $defaultLeagueForFile;
    }
    
    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ';' // Assumendo che il tuo CSV usi il punto e virgola
            // Se usi XLSX, questo non è necessario. Maatwebsite/Excel gestisce XLSX.
        ];
    }
    
    // Se il tuo file CSV/XLSX ha una riga di intestazione, WithHeadingRow è utile.
    // Assicurati che i nomi delle intestazioni nel file siano consistenti.
    // Es. PlayerFantaPlatformID, NomeGiocatore, NomeSquadraDB, StagioneAnnoInizio, NomeLega,
    //      PartiteGiocate, MinutiGiocati, Reti, Assist, MediaVoto (se disponibile), etc.
    
    public function collection(Collection $rows)
    {
        Log::info("[PlayerSeasonStatsImport] Inizio importazione. Righe: " . $rows->count());
        
        foreach ($rows as $row) {
            // Normalizza i nomi delle chiavi (intestazioni) per flessibilità
            $normalizedRow = collect($row)->mapWithKeys(function ($value, $key) {
                return [str_replace(' ', '', strtolower($key)) => $value];
            })->all();
            
            
            $playerFantaId = trim($normalizedRow['playerfantaplatformid'] ?? $normalizedRow['idfanta'] ?? null);
            $playerName = trim($normalizedRow['nomegiocatore'] ?? $normalizedRow['nome'] ?? null);
            $teamNameCsv = trim($normalizedRow['nomesquadradb'] ?? $normalizedRow['squadra'] ?? null);
            $seasonStartYear = trim($normalizedRow['stagioneannoinizio'] ?? $normalizedRow['stagione'] ?? null);
            // Leggi la lega dal file, con un fallback al default passato al costruttore
            $leagueNameCsv = trim($normalizedRow['nomelega'] ?? $normalizedRow['lega'] ?? $this->defaultLeagueForFile);
            if (empty($leagueNameCsv)) $leagueNameCsv = $this->defaultLeagueForFile;
            
            
            if (empty($playerFantaId) || empty($seasonStartYear)) {
                Log::warning("[PlayerSeasonStatsImport] Riga saltata: PlayerFantaPlatformID o StagioneAnnoInizio mancanti.", $row->toArray());
                continue;
            }
            
            $player = Player::where('fanta_platform_id', $playerFantaId)->first();
            if (!$player) {
                Log::warning("[PlayerSeasonStatsImport] Giocatore con FantaPlatformID {$playerFantaId} non trovato nel DB. Riga saltata.", $row->toArray());
                continue;
            }
            
            $teamId = null;
            if ($teamNameCsv) {
                $team = Team::where('name', $teamNameCsv)
                ->orWhere('short_name', $teamNameCsv)
                ->first();
                if ($team) {
                    $teamId = $team->id;
                } else {
                    Log::warning("[PlayerSeasonStatsImport] Squadra '{$teamNameCsv}' non trovata per giocatore {$playerName}. team_id sarà NULL.");
                }
            }
            
            $seasonYearString = $seasonStartYear . '-' . substr((int)$seasonStartYear + 1, 2, 2);
            
            $dataToStore = [
                'player_fanta_platform_id' => $player->fanta_platform_id,
                'season_year'              => $seasonYearString,
                'league_name'              => $leagueNameCsv, // Campo chiave
                'team_id'                  => $teamId,
                'team_name_for_season'     => $teamNameCsv,
                // Assumi che il ruolo sia quello attuale del giocatore, o aggiungi colonna "RuoloStagione" al CSV
                'role_for_season'          => $player->role,
                'games_played'             => (int)($normalizedRow['partitegiocate'] ?? $normalizedRow['pg'] ?? 0),
                'minutes_played'           => (int)($normalizedRow['minutigiocati'] ?? $normalizedRow['min'] ?? 0),
                'goals_scored'             => (int)($normalizedRow['retisegnate'] ?? $normalizedRow['reti'] ?? $normalizedRow['gf'] ?? 0),
                'assists'                  => (int)($normalizedRow['assistforniti'] ?? $normalizedRow['assist'] ?? $normalizedRow['ass'] ?? 0),
                'avg_rating'               => isset($normalizedRow['mediavoto']) ? (float)str_replace(',', '.', $normalizedRow['mediavoto']) : null,
                'penalties_taken'          => (int)($normalizedRow['rigoritentati'] ?? $normalizedRow['rigt'] ?? 0),
                'penalties_scored'         => (int)($normalizedRow['rigorisegnati'] ?? $normalizedRow['rigori'] ?? $normalizedRow['r+'] ?? 0),
                'yellow_cards'             => (int)($normalizedRow['ammonizioni'] ?? $normalizedRow['amm'] ?? 0),
                'red_cards'                => (int)($normalizedRow['espulsioni'] ?? $normalizedRow['esp'] ?? 0),
                // Aggiungi qui altre colonne che vuoi importare da FBRef, es. xg, xag
                // 'xg_total'                 => isset($normalizedRow['xg']) ? (float)str_replace(',', '.', $normalizedRow['xg']) : null,
                // 'xag_total'                => isset($normalizedRow['xag']) ? (float)str_replace(',', '.', $normalizedRow['xag']) : null,
            ];
            
            if (isset($dataToStore['penalties_taken'], $dataToStore['penalties_scored'])) {
                $dataToStore['penalties_missed'] = $dataToStore['penalties_taken'] - $dataToStore['penalties_scored'];
            }
            
            try {
                HistoricalPlayerStat::updateOrCreate(
                    [
                        'player_fanta_platform_id' => $dataToStore['player_fanta_platform_id'],
                        'season_year'              => $dataToStore['season_year'],
                        'team_name_for_season'     => $dataToStore['team_name_for_season'], // O team_id
                        'league_name'              => $dataToStore['league_name'], // Chiave per distinguere tra leghe
                    ],
                    $dataToStore
                    );
                $this->processedCount++;
            } catch (Throwable $e) {
                Log::error("[PlayerSeasonStatsImport] Errore DB per {$playerName} ({$playerFantaId}): " . $e->getMessage(), ['data' => $dataToStore, 'row' => $row->toArray()]);
            }
        }
    }
    public function getProcessedCount(): int { return $this->processedCount; }
}