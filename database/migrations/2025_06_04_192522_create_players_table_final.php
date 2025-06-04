<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up()
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->integer('fanta_platform_id')->nullable()->unique()->comment('ID dal file Quotazioni (colonna "Id")'); // Reso nullable qui
            $table->integer('api_football_data_id')->nullable()->unique()->comment('ID del giocatore sull\'API Football-Data.org');
            $table->string('name')->comment('Nome del giocatore (colonna "Nome")');
            $table->string('team_name')->comment('Squadra attuale del giocatore (colonna "Squadra")');
            $table->char('role', 1)->comment('Ruolo ufficiale (P,D,C,A - colonna "R")');
            $table->integer('initial_quotation')->comment('Quotazione Iniziale (colonna "Qt. I")');
            $table->integer('current_quotation')->nullable()->comment('Quotazione Attuale (colonna "Qt. A")');
            $table->integer('fvm')->nullable()->comment('Fantavalore Valore di Mercato (colonna "FVM")');
            $table->date('date_of_birth')->nullable()->comment('Data di nascita del giocatore');
            $table->string('detailed_position')->nullable()->comment('Posizione dettagliata da API (es. Central Midfield)');
            $table->foreignId('team_id')->nullable()->constrained('teams')->onDelete('set null'); // FK a teams
            
            // Nuove colonne di proiezione (come da Flusso command e proiezioni.md)
            $table->float('avg_rating_proj')->nullable()->comment('Media Voto proiettata per la stagione futura');
            $table->float('fanta_mv_proj')->nullable()->comment('Fantamedia proiettata per la stagione futura');
            $table->integer('games_played_proj')->nullable()->comment('Partite giocate proiettate per la stagione futura');
            $table->float('total_fanta_points_proj')->nullable()->comment('Punti Fantacalcio totali proiettati per la stagione futura');
            
            $table->timestamps();
            $table->softDeletes();
        });
    }
    public function down()
    {
        Schema::dropIfExists('players');
    }
};