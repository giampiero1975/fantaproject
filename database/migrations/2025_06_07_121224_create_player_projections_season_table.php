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
    public function up(): void
    {
        Schema::create('player_projections_season', function (Blueprint $table) {
            $table->id();
            $table->string('player_fanta_platform_id'); // ID univoco del giocatore dalla piattaforma Fantacalcio
            $table->integer('season_start_year'); // Anno di inizio della stagione di proiezione (es. 2025 per 2025-26)
            $table->float('avg_rating_proj')->nullable();
            $table->float('fanta_mv_proj')->nullable();
            $table->integer('games_played_proj')->nullable();
            $table->float('total_fanta_points_proj')->nullable();
            // Puoi aggiungere qui tutti gli altri campi di proiezione dettagliati se li calcoli
            $table->float('goals_scored_proj')->nullable();
            $table->float('assists_proj')->nullable();
            $table->float('yellow_cards_proj')->nullable();
            $table->float('red_cards_proj')->nullable();
            $table->float('own_goals_proj')->nullable();
            $table->float('penalties_taken_proj')->nullable();
            $table->float('penalties_scored_proj')->nullable();
            $table->float('penalties_missed_proj')->nullable();
            $table->float('goals_conceded_proj')->nullable();
            $table->float('penalties_saved_proj')->nullable();
            
            $table->timestamps();
            
            // Indice univoco per garantire una sola proiezione per giocatore per stagione
            $table->unique(['player_fanta_platform_id', 'season_start_year'], 'player_season_unique_projection');
            
            // Chiave esterna non vincolata, perché player_fanta_platform_id non è la PK della tabella players
            // $table->foreign('player_fanta_platform_id')->references('fanta_platform_id')->on('players')->onDelete('cascade');
            // Questo potrebbe essere aggiunto se fanta_platform_id diventa una chiave secondaria indicizzata o primaria
        });
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('player_projections_season');
    }
};