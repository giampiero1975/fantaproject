<?php

namespace App\Imports;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\SkipsUnknownSheets; // Aggiunto per ignorare altri fogli

class HistoricalStatsFileImport implements WithMultipleSheets, SkipsUnknownSheets
{
    private string $seasonForImport;
    private TuttiHistoricalStatsImport $tuttiSheetImporter; // Proprietà per tenere l'istanza
    
    public function __construct(string $seasonYear)
    {
        $this->seasonForImport = $seasonYear;
        // Istanzia l'importer del foglio qui
        $this->tuttiSheetImporter = new TuttiHistoricalStatsImport($this->seasonForImport);
    }
    
    public function sheets(): array
    {
        return [
            // La chiave 'Tutti' deve corrispondere al nome del foglio
            'Tutti' => $this->tuttiSheetImporter, // Usa l'istanza
        ];
    }
    
    // Metodo per recuperare l'istanza dell'importer del foglio
    public function getTuttiSheetImporter(): TuttiHistoricalStatsImport
    {
        return $this->tuttiSheetImporter;
    }
    
    public function onUnknownSheet($sheetName)
    {
        // Logga o ignora i fogli non denominati "Tutti"
        Log::info("HistoricalStatsFileImport: Foglio sconosciuto '$sheetName' ignorato.");
    }
}