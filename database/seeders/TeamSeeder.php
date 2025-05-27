<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Team; // Importa il modello Team
use Illuminate\Support\Facades\DB; // Per usare DB facade se necessario

class TeamSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Disabilita i controlli sulle chiavi esterne temporaneamente se necessario
        // DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        // Team::truncate(); // Svuota la tabella prima di popolarla, se lo desideri
        // DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        
        $teams = [
        // Tier 1: Top Club
            ['name' => 'Napoli', 'short_name' => 'NAP', 'serie_a_team' => true, 'tier' => 1],
            ['name' => 'Inter', 'short_name' => 'INT', 'serie_a_team' => true, 'tier' => 1],
            ['name' => 'Atalanta', 'short_name' => 'ATA', 'serie_a_team' => true, 'tier' => 1],
            // Tier 2: Squadre da Europa
            ['name' => 'Juventus', 'short_name' => 'JUV', 'serie_a_team' => true, 'tier' => 2],
            ['name' => 'Roma', 'short_name' => 'ROM', 'serie_a_team' => true, 'tier' => 2],
            ['name' => 'Fiorentina', 'short_name' => 'FIO', 'serie_a_team' => true, 'tier' => 2],
            ['name' => 'Lazio', 'short_name' => 'LAZ', 'serie_a_team' => true, 'tier' => 2],
            ['name' => 'Milan', 'short_name' => 'MIL', 'serie_a_team' => true, 'tier' => 2],
            // Tier 3: Metà Classifica / Ambiziose
            ['name' => 'Bologna', 'short_name' => 'BOL', 'serie_a_team' => true, 'tier' => 3],
            ['name' => 'Como', 'short_name' => 'COM', 'serie_a_team' => true, 'tier' => 3],
            ['name' => 'Torino', 'short_name' => 'TOR', 'serie_a_team' => true, 'tier' => 3],
            // Tier 4: Lotta Salvezza / Neopromosse (Aggiorna con le squadre 2024/25)
            ['name' => 'Udinese', 'short_name' => 'UDI', 'serie_a_team' => true, 'tier' => 4],
            ['name' => 'Genoa', 'short_name' => 'GEN', 'serie_a_team' => true, 'tier' => 4],
            ['name' => 'Verona', 'short_name' => 'VER', 'serie_a_team' => true, 'tier' => 4],
            ['name' => 'Cagliari', 'short_name' => 'CAG', 'serie_a_team' => true, 'tier' => 4],
            ['name' => 'Parma', 'short_name' => 'PAR', 'serie_a_team' => true, 'tier' => 4],
            ['name' => 'Lecce', 'short_name' => 'LEC', 'serie_a_team' => true, 'tier' => 4],
            ['name' => 'Empoli', 'short_name' => 'EMP', 'serie_a_team' => true, 'tier' => 4],
            ['name' => 'Venezia', 'short_name' => 'VEN', 'serie_a_team' => true, 'tier' => 4],
            ['name' => 'Monza', 'short_name' => 'MON', 'serie_a_team' => true, 'tier' => 4],
        ];
        
        
        foreach ($teams as $teamData) {
            Team::updateOrCreate(['name' => $teamData['name']], $teamData);
        }
        
        $this->command->info('Tabella Teams popolata con le squadre di Serie A e i relativi tier.');
    }
}