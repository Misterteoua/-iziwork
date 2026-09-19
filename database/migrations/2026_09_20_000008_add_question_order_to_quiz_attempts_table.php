<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ordre des questions pour cette participation, et seulement pour elle.
 *
 * Il est figé au démarrage et non recalculé à chaque affichage : sinon le
 * tirage aléatoire changerait les questions au milieu de l'épreuve, et les
 * réponses déjà enregistrées ne correspondraient plus à ce que le candidat voit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->text('question_order')->nullable()->after('student_major');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropColumn('question_order');
        });
    }
};
