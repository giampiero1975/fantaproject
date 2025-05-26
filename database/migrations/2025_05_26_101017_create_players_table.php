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
        Schema::create('players', function (Blueprint $table) {
            $table->id(); // ID interno auto-incrementante del nostro database
            $table->integer('fanta_platform_id')->unique()->comment('ID dal file Quotazioni (colonna "Id")');
            $table->string('name')->comment('Nome del giocatore (colonna "Nome")');
            $table->string('team_name')->comment('Squadra attuale del giocatore (colonna "Squadra")');
            $table->char('role', 1)->comment('Ruolo ufficiale (P,D,C,A - colonna "R")');
            $table->integer('initial_quotation')->comment('Quotazione Iniziale (colonna "Qt. I")');
            $table->integer('current_quotation')->nullable()->comment('Quotazione Attuale (colonna "Qt. A")');
            $table->integer('fvm')->nullable()->comment('Fantavalore Valore di Mercato (colonna "FVM")');
            // Qui potremmo aggiungere anche 'Diff.' e 'FVM CD' se li riteniamo utili
            // $table->float('quotation_difference')->nullable()->comment('Differenza quotazioni (colonna "Diff.")');
            // $table->string('fvm_cd')->nullable()->comment('FVM CD dal file Quotazioni');
            $table->timestamps(); // Colonne created_at e updated_at
        });
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('players');
    }
};