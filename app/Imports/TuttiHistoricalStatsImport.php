<?php

namespace App\Imports;

use App\Traits\FindsTeam;
use App\Traits\ImportsHistoricalPlayerData; // <-- IMPORTA IL NUOVO TRAIT
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Throwable;

class TuttiHistoricalStatsImport implements ToCollection, WithStartRow, SkipsOnError
{
    use FindsTeam, ImportsHistoricalPlayerData; // <-- USA ENTRAMBI I TRAITS
    
    protected int $season;
    protected string $leagueName;
    
    public int $processedCount = 0;
    public int $createdCount = 0;
    public int $updatedCount = 0;
    
    public function __construct(int $season, string $leagueName)
    {
        $this->season = $season;
        $this->leagueName = $leagueName;
        $this->preloadTeams();
    }
    
    public function startRow(): int
    {
        return 3;
    }
    
    public function collection(Collection $rows)
    {
        Log::info("[IMPORT STORICO] Inizio elaborazione di " . $rows->count() . " righe per la lega {$this->leagueName}.");
        
        foreach ($rows as $rowIndex => $rowDataArray) {
            $excelActualRowNumber = $this->startRow() + $rowIndex;
            
            if (empty($rowDataArray[0]) || empty($rowDataArray[3])) {
                Log::warning("[IMPORT STORICO] RIGA SALTATA (Excel #{$excelActualRowNumber}) per mancanza di Id o Nome.");
                continue;
            }
            
            // Chiama il metodo del Trait per fare tutto il lavoro sporco
            $historicalStat = $this->processHistoricalPlayerRow($rowDataArray->toArray());
            
            // Aggiorna i contatori (logica mantenuta come da tua richiesta)
            if ($historicalStat) {
                $this->processedCount++;
                if ($historicalStat->wasRecentlyCreated) {
                    $this->createdCount++;
                } elseif ($historicalStat->wasChanged()) {
                    $this->updatedCount++;
                }
            }
        }
    }
    
    public function onError(Throwable $e)
    {
        Log::error('[IMPORT STORICO - onError] Messaggio: ' . $e->getMessage());
    }
    
    public function getProcessedCount(): int { return $this->processedCount; }
    public function getCreatedCount(): int { return $this->createdCount; }
    public function getUpdatedCount(): int { return $this->updatedCount; }
}