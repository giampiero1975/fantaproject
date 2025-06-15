<?php

namespace App\Imports;

use App\Traits\FindsTeam; // Assicurati di avere questo Trait
use App\Traits\ImportsHistoricalPlayerData;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;

class PlayerSeasonStatsImport implements ToCollection, WithStartRow
{
    use FindsTeam, ImportsHistoricalPlayerData; // Usa entrambi i Traits
    
    private int $seasonStartYear;
    private string $leagueName;
    private int $processedCount = 0;
    
    public function __construct(int $seasonStartYear, string $leagueName)
    {
        $this->season = $seasonStartYear; // Il trait si aspetta 'season'
        $this->seasonStartYear = $seasonStartYear;
        $this->leagueName = $leagueName;
        $this->preloadTeams();
    }
    
    public function startRow(): int
    {
        return 2;
    }
    
    public function collection(\Illuminate\Support\Collection $rows)
    {
        foreach ($rows as $row) {
            $this->processHistoricalPlayerRow($row->toArray());
            $this->processedCount++;
        }
    }
    
    public function getProcessedCount(): int
    {
        return $this->processedCount;
    }
}