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
        Schema::create('teams', function (Blueprint $table) {
            $table->id(); // Chiave primaria auto-incrementante
            $table->string('name')->unique()->comment('Nome ufficiale della squadra (es. Inter, Milan)');
            $table->string('short_name')->nullable()->comment('Nome breve o abbreviazione (es. INT, MIL)'); // Opzionale ma utile
            $table->boolean('serie_a_team')->default(true)->comment('Indica se la squadra è attualmente in Serie A');
            $table->integer('tier')->nullable()->comment('Fascia di forza della squadra (es. 1=Top, 2=Europa, 3=Metà, 4=Salvezza)'); //
            $table->string('logo_url')->nullable()->comment('URL o percorso al logo della squadra'); // Opzionale
            $table->timestamps(); // created_at e updated_at
        });
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('teams');
    }
};