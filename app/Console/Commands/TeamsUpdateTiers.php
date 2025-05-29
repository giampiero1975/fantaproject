<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TeamTieringService; // Assicurati che il namespace sia corretto

class TeamsUpdateTiers extends Command
{
    protected $signature = 'teams:update-tiers {targetSeasonYear : La stagione PER CUI calcolare i tier (es. 2024-25)}';
    protected $description = 'Recalculates and updates team tiers based on historical performance';
    
    protected TeamTieringService $tieringService;
    
    public function __construct(TeamTieringService $tieringService)
    {
        parent::__construct();
        $this->tieringService = $tieringService;
    }
    
    public function handle()
    {
        $targetSeasonYear = $this->argument('targetSeasonYear');
        if (!preg_match('/^\d{4}-\d{2}$/', $targetSeasonYear)) {
            $this->error("Formato stagione target non valido. Usare YYYY-YY (es. 2024-25).");
            return Command::FAILURE;
        }
        
        $this->info("Avvio aggiornamento tier squadre per la stagione: {$targetSeasonYear}");
        
        $result = $this->tieringService->updateAllTeamTiersForSeason($targetSeasonYear);
        
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
                    isset($data['score']) ? round($data['score'],2) : 'N/A',
                    $data['normalized_score'] ?? 'N/A (Raw Used)' // Usa la chiave corretta dall'array ritornato
                ];
            }
            // Ordina per punteggio normalizzato se esiste, altrimenti per grezzo
            usort($tableData, function($a, $b){
                $scoreA = $a[2] !== 'N/A (Raw Used)' && $a[2] !== 'N/A (No Score)' ? (float)$a[2] : (float)$a[1];
                $scoreB = $b[2] !== 'N/A (Raw Used)' && $b[2] !== 'N/A (No Score)' ? (float)$b[2] : (float)$b[1];
                return $scoreB <=> $scoreA; // Decrescente
            });
                $this->table(['Squadra', 'P.Forza Grezzo', 'P.Forza Norm./Usato'], $tableData);
        }
        
        return Command::SUCCESS;
    }
}