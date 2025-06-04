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
            $table->integer('player_fanta_platform_id')->nullable()->comment('ID dal file Quotazioni o interno per collegare al giocatore'); // Reso nullable
            $table->string('season_year')->comment('Anno della stagione (es. 2023-24)');
            $table->string('league_name')->nullable()->comment('Lega in cui sono state registrate queste statistiche (e.g., Serie A, Serie B)');
            $table->foreignId('team_id')->nullable()->constrained('teams')->onDelete('set null');
            $table->string('team_name_for_season')->nullable()->comment('Squadra del giocatore in quella specifica stagione');
            $table->char('role_for_season', 1)->nullable()->comment('Ruolo Classic (P, D, C, A) in quella stagione');
            $table->string('mantra_role_for_season')->nullable()->comment('Ruolo Mantra specifico (es. Por, Dc, M, Pc)');
            
            $table->integer('games_played')->default(0);
            $table->float('avg_rating')->nullable();
            $table->float('fanta_avg_rating')->nullable();
            $table->integer('goals_scored')->default(0);
            $table->integer('goals_conceded')->default(0);
            $table->integer('penalties_saved')->default(0);
            $table->integer('penalties_taken')->default(0);
            $table->integer('penalties_scored')->default(0);
            $table->integer('penalties_missed')->default(0);
            $table->integer('assists')->default(0);
            $table->integer('assists_from_set_piece')->default(0)->nullable();
            $table->integer('yellow_cards')->default(0);
            $table->integer('red_cards')->default(0);
            $table->integer('own_goals')->default(0);
            
            $table->timestamps();
            
            $table->unique(['player_fanta_platform_id', 'season_year', 'team_id', 'league_name'], 'hist_player_unique_season_team_league');
        });
    }
    public function down()
    {
        Schema::dropIfExists('historical_player_stats');
    }
};