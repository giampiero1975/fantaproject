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
        // Validazione base del formato dell'anno stagione, es. "YYYY-YY"
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
            $this->info("\nPunteggi forza calcolati (Grezzo / Normalizzato se applicabile):");
            $tableData = [];
            foreach($result['team_scores'] as $teamId => $data) {
                $tableData[] = [$data['name'], round($data['score'],2) , isset($data['normalized_score']) ? round($data['normalized_score'],2) : 'N/A'];
            }
            // Ordina per punteggio normalizzato se esiste, altrimenti per grezzo
            usort($tableData, function($a, $b){
                $scoreA = $a[2] !== 'N/A' ? $a[2] : $a[1];
                $scoreB = $b[2] !== 'N/A' ? $b[2] : $b[1];
                return $scoreB <=> $scoreA;
            });
                $this->table(['Squadra', 'P.Forza Grezzo', 'P.Forza Norm.'], $tableData);
        }
        
        
        return Command::SUCCESS;
    }
}