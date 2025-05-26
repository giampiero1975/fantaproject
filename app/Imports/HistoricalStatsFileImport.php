<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class HistoricalStatsFileImport implements WithMultipleSheets
{
    private string $seasonForImport;
    
    // QUESTO È IL COSTRUTTORE CHE SI ASPETTA UN ARGOMENTO
    public function __construct(string $seasonYear)
    {
        $this->seasonForImport = $seasonYear;
    }
    
    public function sheets(): array
    {
        return [
            'Tutti' => new TuttiHistoricalStatsImport($this->seasonForImport),
        ];
    }
}