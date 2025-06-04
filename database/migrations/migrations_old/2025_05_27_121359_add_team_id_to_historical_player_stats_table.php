<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::table('historical_player_stats', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('season_year')->constrained('teams')->onDelete('set null');
            // Rimuovi il vecchio vincolo unique se team_name_for_season viene sostituito o reso meno centrale
            // $table->dropUnique('hist_player_season_team_unique');
            // $table->dropColumn('team_name_for_season'); // Valuta se rimuoverlo
            // Aggiungi un nuovo indice se necessario
            // $table->unique(['player_fanta_platform_id', 'season_year', 'team_id'], 'hist_player_season_teamid_unique');
        });
    }
    public function down() {
        Schema::table('historical_player_stats', function (Blueprint $table) {
            // $table->dropUnique('hist_player_season_teamid_unique');
            $table->dropForeign(['team_id']);
            $table->dropColumn('team_id');
            // $table->string('team_name_for_season')->nullable()->after('season_year');
            // Ripristina il vecchio unique se lo hai tolto
            // $table->unique(['player_fanta_platform_id', 'season_year', 'team_name_for_season'], 'hist_player_season_team_unique');
        });
    }
};