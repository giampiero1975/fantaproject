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
        Schema::table('historical_player_stats', function (Blueprint $table) {
            // Aggiungiamo la colonna per il ruolo Mantra, idealmente dopo role_for_season
            // Se 'role_for_season' esiste già, altrimenti puoi omettere ->after() o specificare un'altra colonna
            if (Schema::hasColumn('historical_player_stats', 'role_for_season')) {
                $table->string('mantra_role_for_season')->nullable()->after('role_for_season')->comment('Ruolo Mantra specifico (es. Por, Dc, M, Pc)');
            } else {
                // Se 'role_for_season' non dovesse esistere per qualche motivo, aggiungila senza ->after()
                // o specifica un'altra colonna esistente dopo cui inserirla.
                // Per sicurezza, mettiamola dopo 'team_name_for_season' se 'role_for_season' non c'è.
                if (Schema::hasColumn('historical_player_stats', 'team_name_for_season')) {
                    $table->string('mantra_role_for_season')->nullable()->after('team_name_for_season')->comment('Ruolo Mantra specifico (es. Por, Dc, M, Pc)');
                } else {
                    // Fallback se anche team_name_for_season non c'è (improbabile)
                    $table->string('mantra_role_for_season')->nullable()->comment('Ruolo Mantra specifico (es. Por, Dc, M, Pc)');
                }
            }
        });
    }
    
    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('historical_player_stats', function (Blueprint $table) {
            $table->dropColumn('mantra_role_for_season');
        });
    }
};