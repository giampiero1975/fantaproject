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
        Schema::create('team_historical_standings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->onDelete('cascade'); // FK alla tabella teams
            $table->string('season_year'); // Es. "2023-24"
            $table->string('league_name')->default('Serie A'); // In caso di espansione futura
            $table->integer('position')->nullable(); // Posizione in classifica
            $table->integer('played_games')->nullable();
            $table->integer('won')->nullable();
            $table->integer('draw')->nullable();
            $table->integer('lost')->nullable();
            $table->integer('points')->nullable();
            $table->integer('goals_for')->nullable();
            $table->integer('goals_against')->nullable();
            $table->integer('goal_difference')->nullable();
            // $table->float('avg_points_per_game')->nullable(); // Potrebbe essere calcolato o memorizzato
            $table->string('data_source')->nullable(); // Es. 'manual_import', 'api_football-data'
            $table->timestamps();
            
            $table->unique(['team_id', 'season_year', 'league_name']); // Assicura unicità
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('team_historical_standings');
    }
};
