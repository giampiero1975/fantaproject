<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('historical_player_stats', function (Blueprint $table) {
            // Aggiungi la colonna dopo 'season_year' o un'altra colonna a tua scelta
            $table->string('league_name')->nullable()->after('season_year')->comment('League in which these stats were recorded (e.g., Serie A, Serie B)');
        });
    }
    
    public function down()
    {
        Schema::table('historical_player_stats', function (Blueprint $table) {
            $table->dropColumn('league_name');
        });
    }
};