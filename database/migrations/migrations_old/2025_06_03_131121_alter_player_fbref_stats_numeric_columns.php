<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_fbref_stats', function (Blueprint $table) {
            $table->decimal('goals', 8, 2)->nullable()->change();
            $table->decimal('assists', 8, 2)->nullable()->change();
            $table->decimal('non_penalty_goals', 8, 2)->nullable()->change();
            $table->decimal('expected_goals', 8, 2)->nullable()->change(); // Già decimal, ma per chiarezza
            $table->decimal('non_penalty_expected_goals', 8, 2)->nullable()->change(); // Già decimal
            $table->decimal('expected_assisted_goals', 8, 2)->nullable()->change(); // Già decimal
            $table->decimal('minutes_per_90', 8, 2)->nullable()->change(); // Già decimal
            $table->decimal('gk_save_percentage', 8, 4)->nullable()->change();
            $table->decimal('gk_cs_percentage', 8, 4)->nullable()->change();
            // Aggiungi qui tutti gli altri campi che sono float nel JSON ma int nel DB.
            // Ad esempio, se 'Reti' e 'Assist' sono float, ma la colonna è INT.
            // Dalla tua migrazione originale, goals, assists, non_penalty_goals sono INT.
            // Quindi, DEVONO ESSERE CAMBIATI a DECIMAL.
        });
    }
    
    public function down(): void
    {
        Schema::table('player_fbref_stats', function (Blueprint $table) {
            $table->integer('goals')->nullable()->change();
            $table->integer('assists')->nullable()->change();
            $table->integer('non_penalty_goals')->nullable()->change();
            $table->float('expected_goals')->nullable()->change(); // Torna al tipo originale
            $table->float('non_penalty_expected_goals')->nullable()->change();
            $table->float('expected_assisted_goals')->nullable()->change();
            $table->float('minutes_per_90')->nullable()->change();
            $table->float('gk_save_percentage')->nullable()->change();
            $table->float('gk_cs_percentage')->nullable()->change();
        });
    }
};