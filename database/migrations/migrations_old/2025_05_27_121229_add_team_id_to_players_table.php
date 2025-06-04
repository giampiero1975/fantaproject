<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::table('players', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('fvm')->constrained('teams')->onDelete('set null');
            // Puoi anche decidere di rimuovere team_name se team_id diventa la fonte primaria
            // $table->dropColumn('team_name');
        });
    }
    public function down() {
        Schema::table('players', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropColumn('team_id');
            // Se hai rimosso team_name, dovresti ricrearlo qui nel down()
            // $table->string('team_name')->after('name')->comment('Squadra attuale del giocatore (colonna "Squadra")');
        });
    }
};