<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Imports\PlayerSeasonStatsImport; // Il NUOVO importer
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log;

class PlayersImportAdvancedStats extends Command
{
    protected $signature = 'players:import-advanced-stats
                            {filepath : Path to the CSV/XLSX file}
                            {--league=Serie B : Default league name for records in this file}';
    
    protected $description = 'Imports player historical stats from a file, including league name and potentially advanced stats.';
    
    public function handle()
    {
        $filePath = $this->argument('filepath');
        $defaultLeague = $this->option('league');
        
        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return Command::FAILURE;
        }
        
        $this->info("Starting import of advanced player stats from: {$filePath} for league: {$defaultLeague}");
        Log::info(self::class . ": Starting import from {$filePath}, default league for file: {$defaultLeague}");
        
        try {
            $importer = new PlayerSeasonStatsImport($defaultLeague);
            Excel::import($importer, $filePath);
            
            $this->info("Import completed. Records processed/saved: " . $importer->getProcessedCount());
            Log::info(self::class . ": Import from {$filePath} completed. Processed: " . $importer->getProcessedCount());
            
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            // Gestisci errori di validazione se li hai implementati nell'importer
            $failures = $e->failures();
            foreach ($failures as $failure) {
                $this->error("Error on row {$failure->row()}: " . implode(', ', $failure->errors()));
                Log::error(self::class . ": Validation error on row {$failure->row()}: " . implode(', ', $failure->errors()), $failure->values());
            }
            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error("An error occurred: " . $e->getMessage());
            Log::error(self::class . ": Exception during import from {$filePath}: " . $e->getMessage());
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }
}