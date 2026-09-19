<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réponses rédigées et notation en deux temps.
 *
 * `answer_text` porte la réponse tapée par l'étudiant à une question ouverte —
 * elle ne pouvait pas aller dans `choice`, qui est un tableau d'index de
 * propositions converti en JSON.
 *
 * `is_correct` et `points_awarded` deviennent nullables, et c'est le cœur du
 * dispositif : `null` veut dire « pas encore corrigé », ce qui est une
 * information différente de `0` = « corrigé, zéro point ». Sans cette
 * distinction, une copie en attente de correction serait comptée comme une copie
 * notée, et l'enseignant n'aurait aucun moyen de savoir ce qui lui reste à faire.
 * Les lignes existantes gardent leurs valeurs (0 par défaut à l'époque), elles
 * restent donc des réponses corrigées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->text('answer_text')->nullable()->after('choice');
        });

        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->boolean('is_correct')->nullable()->default(null)->change();
        });

        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->decimal('points_awarded', 5, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        // Les réponses rédigées n'ont pas de colonne où revenir : on les efface
        // plutôt que de laisser des lignes muettes que le modèle ne saurait plus
        // lire (une réponse sans choix ni texte n'est ni juste ni fausse).
        \Illuminate\Support\Facades\DB::table('quiz_answers')->whereNotNull('answer_text')->delete();

        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->boolean('is_correct')->default(false)->nullable(false)->change();
        });

        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->decimal('points_awarded', 5, 2)->default(0)->nullable(false)->change();
        });

        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->dropColumn('answer_text');
        });
    }
};
