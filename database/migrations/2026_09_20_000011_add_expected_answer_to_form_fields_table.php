<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * « Réponse attendue » d'une question ouverte : le guide que l'enseignant rédige
 * pour se relire au moment de corriger.
 *
 * Elle n'est jamais montrée à l'étudiant et n'intervient dans aucun calcul : une
 * réponse rédigée se note à la main. La colonne est nullable et n'existe que pour
 * les questions ouvertes, les QCM n'en ont pas besoin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_fields', function (Blueprint $table) {
            $table->text('expected_answer')->nullable()->after('correct_answer');
        });
    }

    public function down(): void
    {
        Schema::table('form_fields', function (Blueprint $table) {
            $table->dropColumn('expected_answer');
        });
    }
};
