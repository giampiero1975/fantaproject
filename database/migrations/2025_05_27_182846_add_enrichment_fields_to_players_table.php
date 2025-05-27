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
        Schema::table('players', function (Blueprint $table) {
            // Assicurati che i campi siano aggiunti dopo colonne esistenti sensate
            // Se 'team_name' non esiste più perché usi solo team_id, mettili dopo 'role' o 'fvm'
            $table->date('date_of_birth')->nullable()->after('fvm'); // O dopo un'altra colonna esistente
            $table->string('detailed_position')->nullable()->after('date_of_birth');
            // L'ID dell'API di Football-Data, assicurati sia univoco per evitare duplicati se fai re-enrich
            $table->integer('api_football_data_id')->nullable()->unique()->after('fanta_platform_id');
        });
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn(['date_of_birth', 'detailed_position', 'api_football_data_id']);
        });
    }
};