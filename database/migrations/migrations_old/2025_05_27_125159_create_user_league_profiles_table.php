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
        Schema::create('user_league_profiles', function (Blueprint $table) {
            $table->id();
            // Per ora, user_id è nullable. In futuro, se implementi l'autenticazione multi-utente,
            // potrai renderlo non nullable e aggiungere una foreign key.
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            
            $table->string('league_name')->default('La Mia Lega Fantacalcio');
            $table->integer('total_budget')->default(500)->comment('Budget totale per l\'asta');
            $table->integer('num_goalkeepers')->default(3)->comment('Numero portieri in rosa');
            $table->integer('num_defenders')->default(8)->comment('Numero difensori in rosa');
            $table->integer('num_midfielders')->default(8)->comment('Numero centrocampisti in rosa');
            $table->integer('num_attackers')->default(6)->comment('Numero attaccanti in rosa');
            $table->integer('num_participants')->default(10)->comment('Numero partecipanti alla lega');
            $table->text('scoring_rules')->nullable()->comment('Regole di punteggio specifiche (es. JSON o testo semplice)');
            // Esempio di struttura JSON per scoring_rules:
            // { "gol_attaccante": 3, "gol_centrocampista": 3.5, "assist": 1, "ammonizione": -0.5, ... }
            $table->timestamps();
        });
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('user_league_profiles');
    }
};
