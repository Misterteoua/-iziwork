<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qui a donné la note, quand ce n'est pas un correcteur : l'administrateur.
 *
 * Même raison que pour `graded_by_grader_id`, et même précaution : une trace
 * d'audit ne se supprime pas en cascade, donc aucune contrainte de clé
 * étrangère (que SQLite refuserait d'ajouter à une table existante).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('quiz_answers', 'graded_by_admin_id')) {
            return;
        }

        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->unsignedBigInteger('graded_by_admin_id')->nullable()->after('graded_by_grader_id');
            $table->index('graded_by_admin_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('quiz_answers', 'graded_by_admin_id')) {
            return;
        }

        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->dropIndex(['graded_by_admin_id']);
            $table->dropColumn('graded_by_admin_id');
        });
    }
};
