<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réglages propres à une évaluation : durée, affichage de la note, proctoring.
 *
 * Colonne `text` convertie en tableau par le modèle, et non type `json` : la
 * prod est sur un hébergement mutualisé dont la version de MariaDB décide du
 * support réel de JSON, et une colonne texte fonctionne partout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->text('quiz_settings')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->dropColumn('quiz_settings');
        });
    }
};
