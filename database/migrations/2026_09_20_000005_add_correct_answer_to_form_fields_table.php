<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bonne réponse d'une question, sous forme d'index d'options.
 *
 * Cette colonne ne doit jamais être sérialisée vers le navigateur de
 * l'étudiant : la correction est faite exclusivement côté serveur, à la
 * soumission. Un test le vérifie explicitement sur la page rendue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_fields', function (Blueprint $table) {
            $table->text('correct_answer')->nullable()->after('options');
        });
    }

    public function down(): void
    {
        Schema::table('form_fields', function (Blueprint $table) {
            $table->dropColumn('correct_answer');
        });
    }
};
