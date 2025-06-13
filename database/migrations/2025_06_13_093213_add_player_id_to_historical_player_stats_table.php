<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPlayerIdToHistoricalPlayerStatsTable extends Migration
{
    /**
     * Esegue la migration per aggiungere la colonna.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('historical_player_stats', function (Blueprint $table) {
            // Aggiunge la colonna 'player_id' di tipo BigInt, unsigned.
            // La posiziona subito dopo la colonna 'id' per una migliore leggibilità.
            // 'nullable()' permette alla colonna di essere vuota.
            // 'constrained('players')' crea una chiave esterna che punta alla colonna 'id' della tabella 'players'.
            // 'onDelete('cascade')' dice al DB di eliminare questa statistica se il giocatore associato viene eliminato.
            $table->foreignId('player_id')->nullable()->after('id')->constrained('players')->onDelete('cascade');
        });
    }
    
    /**
     * Annulla la migration, rimuovendo la colonna.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('historical_player_stats', function (Blueprint $table) {
            // Rimuove prima la chiave esterna, poi la colonna.
            $table->dropForeign(['player_id']);
            $table->dropColumn('player_id');
        });
    }
}