<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le journal des recalculs : qui a renoté quoi, quand, et sur combien de copies.
 *
 * Une note qui change sans que personne ne signe le changement est la seule
 * chose vraiment difficile à expliquer devant une contestation. Le journal des
 * relectures (`quiz_grade_reviews`) raconte les décisions humaines ; celui-ci
 * raconte les passes automatiques : la renotation qui accompagne une question
 * modifiée, la remise d'aplomb qui suit une question supprimée, et le recalcul
 * demandé à la main depuis les résultats.
 *
 * Une ligne par recalcul, et non par copie : la table grossit avec les gestes de
 * l'enseignant, jamais avec l'effectif d'une promotion. Le détail copie par copie
 * n'est pas conservé — il n'existe que dans l'aperçu, qui n'écrit rien.
 *
 * `admin_user_id` et `form_field_id` sont de simples traces, sans clé étrangère :
 * supprimer un compte ou une question ne doit pas effacer l'histoire de ce qui a
 * été recalculé. `question_label` recopie l'énoncé au moment du geste, parce que
 * c'est la seule chose qui reste d'une question supprimée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quiz_regrade_runs')) {
            return;
        }

        Schema::create('quiz_regrade_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->onDelete('cascade');

            // Qui l'a lancé. Nul seulement quand le recalcul ne vient pas d'une
            // session d'administration : un script, une reprise en base.
            $table->unsignedBigInteger('admin_user_id')->nullable();

            // « quiz » : le recalcul demandé depuis les résultats ; « question » :
            // la renotation d'une question modifiée ; « deleted » : une question
            // supprimée, dont les réponses tombent et dont le barème disparaît.
            $table->string('origin', 20);

            $table->unsignedBigInteger('form_field_id')->nullable();
            $table->text('question_label')->nullable();

            // Ce que le recalcul a changé, tel qu'il l'a annoncé : le nombre de
            // copies dont la note ou le barème a bougé.
            $table->unsignedInteger('copies')->default(0);

            $table->timestamps();

            // « Que s'est-il passé sur cette évaluation, et quand ? » est la
            // seule question posée à cette table.
            $table->index(['form_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_regrade_runs');
    }
};
