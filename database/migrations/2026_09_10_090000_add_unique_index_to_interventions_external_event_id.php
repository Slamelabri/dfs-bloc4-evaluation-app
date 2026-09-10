<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un evenement externe ne doit produire qu'une seule intervention.
     * L'index unique garantit la deduplication meme en cas d'appels
     * concurrents du webhook.
     */
    public function up(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->unique('external_event_id', 'interventions_external_event_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->dropUnique('interventions_external_event_id_unique');
        });
    }
};
