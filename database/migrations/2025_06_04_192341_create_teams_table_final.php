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
            $table->string('name')->unique()->comment('Nome ufficiale della squadra (es. Inter, Milan)');
            $table->string('short_name')->nullable()->comment('Nome breve o abbreviazione (es. INT, MIL)');
            $table->boolean('serie_a_team')->default(true)->comment('Indica se la squadra è attualmente in Serie A');
            $table->integer('tier')->nullable()->comment('Fascia di forza della squadra (es. 1=Top, 2=Europa, 3=Metà, 4=Salvezza)');
            $table->integer('api_football_data_team_id')->nullable()->unique()->comment('ID della squadra sull\'API Football-Data.org');
            $table->timestamps();
        });
    }
    public function down()
    {
        Schema::dropIfExists('teams');
    }
};