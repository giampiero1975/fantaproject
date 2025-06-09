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
        Schema::table('players', function (Blueprint $table) {
            // Rimuovi le colonne di proiezione se esistono
            if (Schema::hasColumn('players', 'avg_rating_proj')) {
                $table->dropColumn('avg_rating_proj');
            }
            if (Schema::hasColumn('players', 'fanta_mv_proj')) {
                $table->dropColumn('fanta_mv_proj');
            }
            if (Schema::hasColumn('players', 'games_played_proj')) {
                $table->dropColumn('games_played_proj');
            }
            if (Schema::hasColumn('players', 'total_fanta_points_proj')) {
                $table->dropColumn('total_fanta_points_proj');
            }
        });
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            // Se fai il rollback, ricrea le colonne (con nullable)
            $table->float('avg_rating_proj')->nullable();
            $table->float('fanta_mv_proj')->nullable();
            $table->integer('games_played_proj')->nullable();
            $table->float('total_fanta_points_proj')->nullable();
        });
    }
};