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
        Schema::table('historical_player_stats', function (Blueprint $table) {
            // Modifica da integer a decimal per preservare la precisione
            $table->decimal('goals_scored', 8, 2)->default(0)->change();
            $table->decimal('assists', 8, 2)->default(0)->change();
            $table->decimal('yellow_cards', 8, 2)->default(0)->change();
            $table->decimal('red_cards', 8, 2)->default(0)->change();
            $table->decimal('own_goals', 8, 2)->default(0)->change();
            $table->decimal('penalties_taken', 8, 2)->default(0)->change();
            $table->decimal('penalties_scored', 8, 2)->default(0)->change();
            $table->decimal('penalties_missed', 8, 2)->default(0)->change();
            $table->decimal('goals_conceded', 8, 2)->default(0)->change();
            $table->decimal('penalties_saved', 8, 2)->default(0)->change();
            // Aggiungi qui altre colonne se necessario
        });
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('historical_player_stats', function (Blueprint $table) {
            // Ripristina i tipi originali se fai il rollback
            $table->integer('goals_scored')->default(0)->change();
            $table->integer('assists')->default(0)->change();
            $table->integer('yellow_cards')->default(0)->change();
            $table->integer('red_cards')->default(0)->change();
            $table->integer('own_goals')->default(0)->change();
            $table->integer('penalties_taken')->default(0)->change();
            $table->integer('penalties_scored')->default(0)->change();
            $table->integer('penalties_missed')->default(0)->change();
            $table->integer('goals_conceded')->default(0)->change();
            $table->integer('penalties_saved')->default(0)->change();
        });
    }
};