<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('historical_player_stats', function (Blueprint $table) {
            $table->id();
            $table->integer('player_fanta_platform_id');
            $table->string('season_year');
            // Nomi colonna corretti e più descrittivi:
            $table->string('team_name_for_season')->nullable()->comment('Squadra del giocatore in quella specifica stagione');
            $table->char('role_for_season', 1)->nullable()->comment('Ruolo del giocatore in quella specifica stagione');
            
            $table->integer('games_played')->default(0);      // Pg
            $table->float('avg_rating')->nullable();          // Mv (Media Voto)
            $table->float('fanta_avg_rating')->nullable();    // Mf (FantaMedia)
            $table->integer('goals_scored')->default(0);      // Gf
            $table->integer('goals_conceded')->default(0);    // Gs (per portieri)
            $table->integer('penalties_saved')->default(0);   // Rp
            $table->integer('penalties_taken')->default(0);   // Rc (calciati)
            $table->integer('penalties_scored')->default(0);  // R+
            $table->integer('penalties_missed')->default(0);  // R-
            $table->integer('assists')->default(0);           // Ass
            $table->integer('assists_from_set_piece')->default(0)->nullable(); // Asf
            $table->integer('yellow_cards')->default(0);      // Amm
            $table->integer('red_cards')->default(0);         // Esp
            $table->integer('own_goals')->default(0);         // Au
            
            $table->timestamps();
            
            $table->index(['player_fanta_platform_id', 'season_year'], 'hist_player_season_idx');
            
            // Vincolo Unique aggiornato con i nomi colonna corretti
            $table->unique(['player_fanta_platform_id', 'season_year', 'team_name_for_season'], 'hist_player_season_team_unique');
        });
    }
    public function down()
    {
        Schema::dropIfExists('historical_player_stats');
    }
};