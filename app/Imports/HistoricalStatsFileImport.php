<?php

namespace App\Imports;

// ASSICURATI DI IMPORTARE QUESTE CLASSI
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Illuminate\Support\Facades\Log;

class HistoricalStatsFileImport implements WithMultipleSheets
{
    protected $season;
    protected $leagueName;
    
    public function __construct(int $season, string $leagueName)
    {
        //dd('STO USANDO IL CONTENITORE CORRETTO: HistoricalStatsFileImport');
        $this->season = $season;
        $this->leagueName = $leagueName;
    }
    
    /**
     * @return array
     */
    public function sheets(): array
    {
        // QUESTA FUNZIONE È IL CUORE DI TUTTO.
        // Dice a Laravel-Excel di usare la nostra classe personalizzata
        // (con la logica ToCollection e WithStartRow) per ogni foglio.
        $sheets = [
            // Usa gli indici dei fogli (a partire da 0)
            // questo è più robusto dei nomi che possono cambiare.
            0 => new TuttiHistoricalStatsImport($this->season, $this->leagueName), // Primo foglio
            1 => new TuttiHistoricalStatsImport($this->season, $this->leagueName), // Secondo foglio
            2 => new TuttiHistoricalStatsImport($this->season, $this->leagueName), // Terzo foglio
            3 => new TuttiHistoricalStatsImport($this->season, $this->leagueName), // Quarto foglio
        ];
        
        // Se preferisci usare i nomi esatti dei fogli
        /*
         $sheets = [
         'Tutti P' => new TuttiHistoricalStatsImport($this->season, $this->leagueName),
         'Tutti D' => new TuttiHistoricalStatsImport($this->season, $this->leagueName),
         'Tutti C' => new TuttiHistoricalStatsImport($this->season, $this->leagueName),
         'Tutti A' => new TuttiHistoricalStatsImport($this->season, $this->leagueName),
         ];
         */
        
        Log::info("HistoricalStatsFileImport delegating to sheet-specific imports.", ['sheets' => array_keys($sheets)]);
        
        return $sheets;
    }
}