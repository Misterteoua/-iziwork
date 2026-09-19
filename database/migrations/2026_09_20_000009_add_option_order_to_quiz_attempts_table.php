<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ordre d'affichage des propositions, par question et par candidat.
 *
 * Stocké sous forme « question_id → liste des index d'origine » : les réponses
 * continuent d'être enregistrées avec les index d'origine, donc la correction
 * automatique et le barème ne dépendent pas de l'ordre affiché. Sans cela, la
 * note d'un candidat changerait selon le mélange reçu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->text('option_order')->nullable()->after('question_order');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropColumn('option_order');
        });
    }
};
