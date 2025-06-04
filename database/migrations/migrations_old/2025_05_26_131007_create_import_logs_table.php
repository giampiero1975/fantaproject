<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('import_logs', function (Blueprint $table) {
            $table->id();
            $table->string('original_file_name'); // Nome originale del file caricato dall'utente
            $table->string('import_type')->comment('Es: roster_quotazioni, statistiche_storiche');
            $table->string('status')->comment('Es: successo, fallito'); // Potremmo usare un ENUM in futuro
            $table->text('details')->nullable()->comment('Eventuali note, messaggi di errore, o il tag dalla riga 1');
            $table->integer('rows_processed')->nullable();
            $table->integer('rows_created')->nullable();
            $table->integer('rows_updated')->nullable();
            $table->timestamps(); // created_at (come timestamp dell'importazione) e updated_at
        });
    }
    
    public function down()
    {
        Schema::dropIfExists('import_logs');
    }
};