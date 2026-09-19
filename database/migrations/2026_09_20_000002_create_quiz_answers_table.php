<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une réponse donnée à une question par un candidat.
 *
 * L'unicité (participation, question) est la garantie structurelle de la
 * navigation linéaire : une question déjà répondue ne peut pas l'être deux
 * fois, et le flux « première question sans réponse » ne peut donc pas
 * revenir en arrière.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_attempt_id')->constrained()->onDelete('cascade');
            $table->foreignId('form_field_id')->constrained()->onDelete('cascade');

            // Index des options choisies, en texte converti en tableau par le
            // modèle : le type JSON de MariaDB varie selon les versions.
            $table->text('choice')->nullable();

            $table->boolean('is_correct')->default(false);
            $table->decimal('points_awarded', 5, 2)->default(0);
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['quiz_attempt_id', 'form_field_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_answers');
    }
};
