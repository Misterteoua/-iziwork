<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pièces jointes d'une réponse d'évaluation.
 *
 * Un étudiant peut répondre à une question rédigée en joignant une image ou un
 * PDF (deux au maximum, 1 Mo chacun). Les fichiers sont rangés sur le disque
 * privé et la ligne ne porte que leurs métadonnées, exactement comme pour les
 * dépôts de travaux : rien de ce que rend un étudiant n'est servi depuis un
 * dossier public.
 *
 * La table est purement additive : une évaluation déjà en service ne la voit
 * pas, et une réponse sans pièce jointe se comporte comme avant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_attempt_id')->constrained()->onDelete('cascade');
            $table->foreignId('form_field_id')->constrained()->onDelete('cascade');

            $table->string('original_name');
            $table->string('stored_name');
            $table->string('file_path');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('mime_type')->nullable();
            $table->timestamps();

            // Le rattachement à une réponse se lit toujours par sa question, dans
            // la copie : cet index évite une lecture complète de la table quand
            // une correction charge les pièces jointes question par question.
            $table->index(['quiz_attempt_id', 'form_field_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attachments');
    }
};
