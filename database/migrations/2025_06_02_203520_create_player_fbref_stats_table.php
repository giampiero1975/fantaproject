<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('player_fbref_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained('players')->onDelete('cascade');
            $table->foreignId('team_id')->constrained('teams')->onDelete('cascade'); // Squadra per cui ha giocato in quella stagione/file
            $table->year('season_year'); // Anno di inizio stagione (es. 2024 per 2024/25)
            $table->string('league_name', 50);
            $table->string('data_source', 50)->default('fbref_html_import');
            $table->string('position_fbref')->nullable()->comment('Ruolo/i FBREF originali (es. DF,MF)');
            $table->string('age_string_fbref')->nullable()->comment('Età FBREF originale (es. 22-123)');
            
            // Statistiche di Gioco Base (Valori Totali Stagionali)
            $table->integer('games_played')->nullable();
            $table->integer('games_started')->nullable();
            $table->integer('minutes_played')->nullable();
            $table->decimal('minutes_per_90', 8, 2)->nullable()->comment('Minutes played / 90');
            
            // Rendimento Offensivo (Valori Totali Stagionali)
            $table->integer('goals')->nullable()->comment('Reti (da FBREF)');
            $table->integer('assists')->nullable()->comment('Assist (da FBREF)');
            $table->integer('non_penalty_goals')->nullable();
            $table->integer('penalties_made')->nullable()->comment('Rigori Segnati');
            $table->integer('penalties_attempted')->nullable()->comment('Rigori Tentati');
            $table->integer('yellow_cards')->nullable();
            $table->integer('red_cards')->nullable();
            
            // Expected Stats (Valori Totali Stagionali)
            $table->decimal('expected_goals', 8, 2)->nullable()->comment('xG');
            $table->decimal('non_penalty_expected_goals', 8, 2)->nullable()->comment('npxG');
            $table->decimal('expected_assisted_goals', 8, 2)->nullable()->comment('xAG');
            // $table->decimal('npxg_plus_xag', 8, 2)->nullable(); // Può essere calcolato
            
            // Progressione (Valori Totali Stagionali)
            $table->integer('progressive_carries')->nullable();
            $table->integer('progressive_passes_completed')->nullable();
            $table->integer('progressive_passes_received')->nullable();
            
            // Statistiche Per 90 Minuti (se disponibili direttamente o calcolate)
            $table->decimal('goals_per_90', 8, 4)->nullable();
            $table->decimal('assists_per_90', 8, 4)->nullable();
            $table->decimal('non_penalty_goals_per_90', 8, 4)->nullable();
            $table->decimal('expected_goals_per_90', 8, 4)->nullable();
            $table->decimal('expected_assisted_goals_per_90', 8, 4)->nullable();
            $table->decimal('non_penalty_expected_goals_per_90', 8, 4)->nullable();
            // $table->decimal('npxg_plus_xag_per_90', 8, 4)->nullable(); // Può essere calcolato
            
            // Statistiche Portiere (Valori Totali Stagionali)
            $table->integer('gk_games_played')->nullable(); // Se diverso da games_played generale
            $table->integer('gk_goals_conceded')->nullable();
            $table->integer('gk_shots_on_target_against')->nullable();
            $table->integer('gk_saves')->nullable();
            $table->decimal('gk_save_percentage', 8, 4)->nullable(); // Es. 0.7550 per 75.5%
            $table->integer('gk_clean_sheets')->nullable();
            $table->decimal('gk_cs_percentage', 8, 4)->nullable();
            $table->integer('gk_penalties_faced')->nullable(); // Rigori tentati contro
            $table->integer('gk_penalties_conceded_on_attempt')->nullable(); // Rigori segnati subiti
            $table->integer('gk_penalties_saved')->nullable();
            
            // Statistiche Difensive (Valori Totali Stagionali)
            $table->integer('defense_tackles_attempted')->nullable();
            $table->integer('defense_tackles_won')->nullable();
            $table->integer('defense_interceptions')->nullable();
            $table->integer('defense_clearances')->nullable();
            $table->integer('defense_blocks_general')->nullable()->comment('Tiri + Passaggi Bloccati');
            $table->integer('defense_shots_blocked')->nullable();
            $table->integer('defense_passes_blocked')->nullable();
            
            // Statistiche Tiro (Valori Totali Stagionali)
            $table->integer('shots_total')->nullable();
            $table->integer('shots_on_target')->nullable();
            
            // Statistiche Varie (Valori Totali Stagionali)
            $table->integer('misc_fouls_committed')->nullable();
            $table->integer('misc_fouls_drawn')->nullable();
            $table->integer('misc_own_goals')->nullable();
            
            $table->timestamps();
            
            $table->unique(['player_id', 'team_id', 'season_year', 'league_name', 'data_source'], 'player_fbref_stats_unique_key');
        });
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('player_fbref_stats');
    }
};