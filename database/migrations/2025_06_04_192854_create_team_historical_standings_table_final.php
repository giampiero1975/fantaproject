<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up()
    {
        Schema::create('team_historical_standings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->onDelete('cascade');
            $table->string('season_year');
            $table->string('league_name')->default('Serie A');
            $table->integer('position')->nullable();
            $table->integer('played_games')->nullable();
            $table->integer('won')->nullable();
            $table->integer('draw')->nullable();
            $table->integer('lost')->nullable();
            $table->integer('points')->nullable();
            $table->integer('goals_for')->nullable();
            $table->integer('goals_against')->nullable();
            $table->integer('goal_difference')->nullable();
            $table->string('data_source')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'season_year', 'league_name']);
        });
    }
    public function down()
    {
        Schema::dropIfExists('team_historical_standings');
    }
};