<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le journal des notes revues : le second niveau de relecture.
 *
 * Une note posée par un correcteur externe n'est pas un point final. Quand
 * l'administration la reprend — ou la confirme après examen — la note précédente
 * est **archivée ici avant d'être remplacée**, jamais effacée : c'est ce qui
 * permet de répondre à « qui avait mis quoi, et pourquoi c'est changé », la
 * seule question qui compte devant une contestation.
 *
 * Une ligne par acte de relecture, et non une par note : la table grossit avec
 * les interventions, pas avec les copies. Les colonnes `previous_*` décrivent
 * l'état remplacé ; la note retenue, elle, vit dans `quiz_answers`, donc aucun
 * affichage n'a besoin d'un jointure pour lire la note courante.
 *
 * `previous_grader_id` et `previous_admin_id` sont de simples traces, sans clé
 * étrangère : supprimer un correcteur ne doit pas effacer l'histoire de ce qu'il
 * a corrigé.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quiz_grade_reviews')) {
            return;
        }

        Schema::create('quiz_grade_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_answer_id')->constrained()->onDelete('cascade');

            // La note remplacée, telle qu'elle était.
            $table->decimal('previous_points', 5, 2)->nullable();
            $table->text('previous_comment')->nullable();
            $table->unsignedBigInteger('previous_grader_id')->nullable();
            $table->unsignedBigInteger('previous_admin_id')->nullable();

            // Qui a relu, et pourquoi. Le motif est obligatoire quand la note
            // d'un correcteur est modifiée : sans lui, la trace ne dirait rien
            // à celui qui la découvre.
            $table->unsignedBigInteger('reviewed_by_admin_id')->nullable();
            $table->text('reason')->nullable();

            $table->timestamps();

            // « Ce correcteur a-t-il été repris ? » est la question la plus
            // fréquente posée à cette table.
            $table->index('previous_grader_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_grade_reviews');
    }
};
