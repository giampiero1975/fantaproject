<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up()
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // Nome ufficiale della squadra
            $table->string('short_name')->nullable(); // Nome breve
            $table->string('tla')->nullable(); // Acronimo di tre lettere (es. JUV)
            $table->string('crest_url')->nullable(); // URL dello stemma
            $table->boolean('serie_a_team')->default(false)->index(); // Flag per Serie A
            $table->integer('tier')->nullable()->index(); // Tier calcolato
            $table->unsignedBigInteger('fanta_platform_id')->nullable()->unique()->comment('ID usato dalla piattaforma Fantacalcio (es. IDGazzetta)');
            $table->integer('api_football_data_id')->unique()->nullable()->comment('ID usato da football-data.org');
            $table->string('league_code')->nullable()->index()->comment('Codice lega attuale (SA, SB) da football-data.org');
            $table->timestamps();
        });
    }
    public function down()
    {
        Schema::dropIfExists('teams');
    }
};