<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithLimit;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\SkipsUnknownSheets;

// Questa classe è un po' più complessa per gestire la selezione di un foglio specifico
// e leggere solo la prima riga di quel foglio.
class FirstRowSheetImport implements ToArray, WithLimit
{
    public function limit(): int
    {
        return 1; // Leggi solo la prima riga (che dovrebbe essere la riga del titolo)
    }
    
    public function array(array $array): array
    {
        // Restituisce l'array di righe (in questo caso, solo una riga)
        return $array;
    }
}

// Main importer to select the "Tutti" sheet for FirstRowSheetImport
class FirstRowOnlyImport implements WithMultipleSheets, SkipsUnknownSheets
{
    private $targetSheetName;
    
    public function __construct(string $targetSheetName)
    {
        $this->targetSheetName = $targetSheetName;
    }
    
    public function sheets(): array
    {
        return [
            $this->targetSheetName => new FirstRowSheetImport(),
        ];
    }
    
    // Opzionale: se vuoi ignorare altri fogli senza errori
    public function onUnknownSheet($sheetName)
    {
        // Log o ignora
        info("Foglio sconosciuto durante lettura prima riga: $sheetName");
    }
}