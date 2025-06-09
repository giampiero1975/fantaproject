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
        Schema::table('teams', function (Blueprint $table) {
            // Aggiungi la colonna season_year
            $table->integer('season_year')->nullable()->after('league_code')->comment('Anno della stagione di riferimento per i dati della squadra (es. da API)');
        });
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('season_year');
        });
    }
};