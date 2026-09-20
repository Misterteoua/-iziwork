<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qui a donné la note : le correcteur externe, ou l'administrateur ?
 *
 * Une note contestée n'a de réponse que si l'on sait qui l'a posée. Cette
 * colonne garde l'identifiant du correcteur, et rien ne l'efface — pas même la
 * suppression de son compte : c'est une trace, pas une jointure. Deux colonnes
 * plutôt qu'une chaîne « admin:3 » parce qu'un index sur une colonne
 * d'identifiant reste interrogeable (« les copies corrigées par Awa »), ce dont
 * dépend l'export CSV du correcteur.
 *
 * Les lignes écrites avant ce lot n'ont aucune des deux colonnes renseignées :
 * l'affichage les annonce comme « correction antérieure au suivi », plutôt que
 * de leur inventer un auteur.
 *
 * Colonne volontairement sans contrainte de clé étrangère : SQLite (base des
 * tests) n'accepte pas d'ajouter une clé étrangère à une table existante, et
 * une trace historique ne doit de toute façon pas disparaître en cascade.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('quiz_answers', 'graded_by_grader_id')) {
            return;
        }

        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->unsignedBigInteger('graded_by_grader_id')->nullable()->after('grader_comment');
            $table->index('graded_by_grader_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('quiz_answers', 'graded_by_grader_id')) {
            return;
        }

        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->dropIndex(['graded_by_grader_id']);
            $table->dropColumn('graded_by_grader_id');
        });
    }
};
