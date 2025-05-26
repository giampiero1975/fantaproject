<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class MainRosterImport implements WithMultipleSheets
{
    /**
     * Il costruttore non ha bisogno di parametri se TuttiSheetImport
     * definisce autonomamente la sua riga di intestazione.
     */
    public function __construct()
    {
    }
    
    /**
     * Specifica quale importer usare per ogni foglio.
     * In questo caso, solo il foglio 'Tutti' ci interessa.
     *
     * @return array
     */
    public function sheets(): array
    {
        return [
            // La chiave 'Tutti' deve corrispondere esattamente al nome del foglio nel file Excel (sensibile a maiuscole/minuscole).
            'Tutti' => new TuttiSheetImport(),
        ];
    }
}