<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up()
    {
        Schema::create('user_league_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('league_name')->default('La Mia Lega Fantacalcio');
            $table->integer('total_budget')->default(500)->comment('Budget totale per l\'asta');
            $table->integer('num_goalkeepers')->default(3)->comment('Numero portieri in rosa');
            $table->integer('num_defenders')->default(8)->comment('Numero difensori in rosa');
            $table->integer('num_midfielders')->default(8)->comment('Numero centrocampisti in rosa');
            $table->integer('num_attackers')->default(6)->comment('Numero attaccanti in rosa');
            $table->integer('num_participants')->default(10)->comment('Numero partecipanti alla lega');
            $table->text('scoring_rules')->nullable()->comment('Regole di punteggio specifiche (es. JSON o testo semplice)');
            $table->timestamps();
        });
    }
    public function down()
    {
        Schema::dropIfExists('user_league_profiles');
    }
};