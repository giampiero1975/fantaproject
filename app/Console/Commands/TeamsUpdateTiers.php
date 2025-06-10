<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TeamTieringService;

class TeamsUpdateTiers extends Command
{
    /**
     * The name and signature of the console command.
     * --- MODIFICATO per accettare l'opzione --year ---
     * @var string
     */
    protected $signature = 'teams:update-tiers {--year= : Anno di inizio della stagione target (es. 2025). Se non specificato, usa un default intelligente.}';
    
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ricalcola e aggiorna i tier delle squadre basandosi sulla performance storica.';
    
    protected TeamTieringService $tieringService;
    
    public function __construct(TeamTieringService $tieringService)
    {
        parent::__construct();
        $this->tieringService = $tieringService;
    }
    
    public function handle()
    {
        // --- NUOVA LOGICA PER GESTIRE L'OPZIONE --year ---
        $targetYear = $this->option('year');
        
        if (!$targetYear) {
            // Se l'anno non è specificato, calcoliamo un default basato sulla data corrente.
            // Se siamo dopo Giugno, usiamo l'anno corrente, altrimenti quello precedente.
            $targetYear = date('m') >= 7 ? (int)date('Y') : (int)date('Y') - 1;
            $this->info("Anno non specificato, uso il default calcolato: {$targetYear}");
        }
        
        // Il service si aspetta il formato YYYY-YY, quindi lo costruiamo.
        $targetSeasonString = $targetYear . '-' . substr($targetYear + 1, -2);
        
        $this->info("Avvio aggiornamento tier squadre per la stagione: {$targetSeasonString}");
        
        // Chiamiamo il service con il formato stringa che si aspetta
        $result = $this->tieringService->updateAllTeamTiersForSeason($targetSeasonString);
        
        $this->info("Aggiornamento completato. Squadre con tier modificato: {$result['updated_count']}");
        
        if (!empty($result['tier_distribution'])) {
            $this->info("Nuova distribuzione dei tier:");
            foreach($result['tier_distribution'] as $tier => $count) {
                if ($count > 0) $this->line("Tier {$tier}: {$count} squadre");
            }
        }
        
        if (!empty($result['team_scores'])) {
            $this->info("\nPunteggi forza calcolati (Squadra: P.Grezzo / P.Norm. o Usato):");
            $tableData = [];
            foreach($result['team_scores'] as $teamId => $data) {
                $tableData[] = [
                    $data['name'],
                    isset($data['score']) ? round($data['score'], 2) : 'N/A',
                    $data['normalized_score'] ?? 'N/A (Raw Used)'
                ];
            }
            
            // Ordina per punteggio normalizzato se esiste, altrimenti per grezzo
            usort($tableData, function($a, $b) {
                $scoreA = $a[2] !== 'N/A (Raw Used)' && $a[2] !== 'N/A (No Score)' ? (float)$a[2] : (float)$a[1];
                $scoreB = $b[2] !== 'N/A (Raw Used)' && $b[2] !== 'N/A (No Score)' ? (float)$b[2] : (float)$b[1];
                return $scoreB <=> $scoreA; // Decrescente
            });
                
                $this->table(['Squadra', 'P.Forza Grezzo', 'P.Forza Norm./Usato'], $tableData);
        }
        
        return Command::SUCCESS;
    }
}