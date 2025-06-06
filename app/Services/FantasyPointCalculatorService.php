<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class FantasyPointCalculatorService
{
    /**
     * Calcola i fantapunti basati sulle statistiche fornite e le regole di punteggio.
     *
     * @param array $stats Un array associativo di statistiche (es. ['mv' => 6.5, 'gol_fatti' => 2.5, 'assist' => 1.3, ...])
     * @param array $scoringRules Un array associativo con le regole di punteggio (es. ['gol_a' => 3, 'assist' => 1, ...])
     * @param string $playerRole Il ruolo del giocatore (P, D, C, A) per applicare bonus/malus specifici per ruolo.
     * @return float Il totale dei fantapunti calcolati.
     */
    public function calculateFantasyPoints(array $stats, string $scoringRules, string $playerRole): float
    {
        // Log di input per debug
        Log::debug("FantasyPointCalculatorService: Ricevute stats: " . json_encode($stats) . " con regole: " . json_encode($scoringRules) . " e ruolo: " . $playerRole);
        
        $fantasyPoints = 0.0;
        
        // Media Voto (MV) è la base
        $mv = (float)($stats['mv'] ?? 0.0);
        $fantasyPoints += $mv;
        // Log::debug("FantasyPointCalculatorService: Punti dopo MV ({$mv}): {$fantasyPoints}");
        
        // Gol Fatti
        if (isset($stats['gol_fatti']) && (float)$stats['gol_fatti'] > 0) {
            $golFatti = (float)$stats['gol_fatti'];
            $roleKeyPart = strtolower($playerRole);
            $bonusKey = 'gol_' . $roleKeyPart; // es. gol_p, gol_d, gol_c, gol_a
            $legacyBonusKey = 'bonus_gol_' . $roleKeyPart; // es. bonus_gol_portiere
            $defaultGolBonus = (float)($scoringRules['gol_generico'] ?? 3.0);
            
            $golPoints = (float)($scoringRules[$bonusKey] ?? $scoringRules[$legacyBonusKey] ?? $defaultGolBonus) * $golFatti;
            $fantasyPoints += $golPoints;
            // Log::debug("FantasyPointCalculatorService: Punti dopo Gol ({$golFatti} * {$golPoints/$golFatti}): {$fantasyPoints}");
        }
        
        // Assist
        if (isset($stats['assist']) && (float)$stats['assist'] > 0) {
            $assist = (float)$stats['assist'];
            $assistPoints = (float)($scoringRules['assist'] ?? $scoringRules['bonus_assist'] ?? 1.0) * $assist;
            $fantasyPoints += $assistPoints;
            // Log::debug("FantasyPointCalculatorService: Punti dopo Assist ({$assist} * {$assistPoints/$assist}): {$fantasyPoints}");
        }
        
        // Ammonizioni
        if (isset($stats['ammonizioni']) && (float)$stats['ammonizioni'] > 0) {
            $ammonizioni = (float)$stats['ammonizioni'];
            $ammPoints = (float)($scoringRules['ammonizione'] ?? $scoringRules['malus_ammonizione'] ?? -0.5) * $ammonizioni;
            $fantasyPoints += $ammPoints;
            // Log::debug("FantasyPointCalculatorService: Punti dopo Ammonizioni ({$ammonizioni} * {$ammPoints/$ammonizioni}): {$fantasyPoints}");
        }
        
        // Espulsioni
        if (isset($stats['espulsioni']) && (float)$stats['espulsioni'] > 0) {
            $espulsioni = (float)$stats['espulsioni'];
            $espPoints = (float)($scoringRules['espulsione'] ?? $scoringRules['malus_espulsione'] ?? -1.0) * $espulsioni;
            $fantasyPoints += $espPoints;
            // Log::debug("FantasyPointCalculatorService: Punti dopo Espulsioni ({$espulsioni} * {$espPoints/$espulsioni}): {$fantasyPoints}");
        }
        
        // Autogol
        if (isset($stats['autogol']) && (float)$stats['autogol'] > 0) {
            $autogol = (float)$stats['autogol'];
            $autogolPoints = (float)($scoringRules['autogol'] ?? $scoringRules['malus_autogol'] ?? -2.0) * $autogol;
            $fantasyPoints += $autogolPoints;
            // Log::debug("FantasyPointCalculatorService: Punti dopo Autogol ({$autogol} * {$autogolPoints/$autogol}): {$fantasyPoints}");
        }
        
        // Rigori
        if (isset($stats['rigori_segnati']) && (float)$stats['rigori_segnati'] > 0) {
            $rigSegnati = (float)$stats['rigori_segnati'];
            $rigSPoints = (float)($scoringRules['rigore_segnato'] ?? $scoringRules['bonus_rigore_segnato'] ?? 3.0) * $rigSegnati;
            $fantasyPoints += $rigSPoints;
            // Log::debug("FantasyPointCalculatorService: Punti dopo Rigori Segnati ({$rigSegnati} * {$rigSPoints/$rigSegnati}): {$fantasyPoints}");
        }
        if (isset($stats['rigori_sbagliati']) && (float)$stats['rigori_sbagliati'] > 0) {
            $rigSbagliati = (float)$stats['rigori_sbagliati'];
            $rigSbPoints = (float)($scoringRules['rigore_sbagliato'] ?? $scoringRules['malus_rigore_sbagliato'] ?? -3.0) * $rigSbagliati;
            $fantasyPoints += $rigSbPoints;
            // Log::debug("FantasyPointCalculatorService: Punti dopo Rigori Sbagliati ({$rigSbagliati} * {$rigSbPoints/$rigSbagliati}): {$fantasyPoints}");
        }
        
        // Specifiche per Portiere
        if (strtoupper($playerRole) === 'P') {
            if (isset($stats['rigori_parati']) && (float)$stats['rigori_parati'] > 0) {
                $rigParati = (float)$stats['rigori_parati'];
                $rigPPoints = (float)($scoringRules['rigore_parato'] ?? $scoringRules['bonus_rigore_parato'] ?? 3.0) * $rigParati;
                $fantasyPoints += $rigPPoints;
                // Log::debug("FantasyPointCalculatorService: Punti dopo Rigori Parati ({$rigParati} * {$rigPPoints/$rigParati}): {$fantasyPoints}");
            }
            if (isset($stats['gol_subiti']) && (float)$stats['gol_subiti'] > 0) {
                $golSubiti = (float)$stats['gol_subiti'];
                $gsPoints = (float)($scoringRules['gol_subito_p'] ?? $scoringRules['malus_gol_subito_portiere'] ?? -1.0) * $golSubiti;
                $fantasyPoints += $gsPoints;
                // Log::debug("FantasyPointCalculatorService: Punti dopo Gol Subiti ({$golSubiti} * {$gsPoints/$golSubiti}): {$fantasyPoints}");
            }
            if (isset($stats['clean_sheet']) && $stats['clean_sheet'] && ($stats['mv'] ?? 0) >= 6) {
                $csPPoints = (float)($scoringRules['clean_sheet_p'] ?? $scoringRules['bonus_imbattibilita_portiere'] ?? 1.0);
                $fantasyPoints += $csPPoints;
                // Log::debug("FantasyPointCalculatorService: Punti dopo Clean Sheet Portiere (+{$csPPoints}): {$fantasyPoints}");
            }
        }
        
        // Specifiche per Difensore (Clean Sheet)
        if (strtoupper($playerRole) === 'D') {
            if (isset($stats['clean_sheet']) && $stats['clean_sheet'] && ($stats['mv'] ?? 0) >= 6) {
                $csDPoints = (float)($scoringRules['clean_sheet_d'] ?? $scoringRules['bonus_imbattibilita_difensore'] ?? 0.5);
                $fantasyPoints += $csDPoints;
                // Log::debug("FantasyPointCalculatorService: Punti dopo Clean Sheet Difensore (+{$csDPoints}): {$fantasyPoints}");
            }
        }
        
        Log::debug("FantasyPointCalculatorService: FantaMedia finale calcolata: {$fantasyPoints}");
        return $fantasyPoints;
    }
}
