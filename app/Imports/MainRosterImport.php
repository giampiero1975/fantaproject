<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\SkipsUnknownSheets; // Aggiunto

class MainRosterImport implements WithMultipleSheets, SkipsUnknownSheets
{
    private TuttiSheetImport $tuttiSheetImporter; // Proprietà per tenere l'istanza
    
    public function __construct()
    {
        // Istanzia l'importer del foglio qui
        $this->tuttiSheetImporter = new TuttiSheetImport();
    }
    
    public function sheets(): array
    {
        return [
            'Tutti' => $this->tuttiSheetImporter, // Usa l'istanza
        ];
    }
    
    // Metodo per recuperare l'istanza dell'importer del foglio
    public function getTuttiSheetImporter(): TuttiSheetImport
    {
        return $this->tuttiSheetImporter;
    }
    
    public function onUnknownSheet($sheetName)
    {
        Log::info("MainRosterImport: Foglio sconosciuto '$sheetName' ignorato.");
    }
}