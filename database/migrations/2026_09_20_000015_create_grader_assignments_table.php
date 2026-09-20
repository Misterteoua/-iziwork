<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quelles évaluations un correcteur a le droit de corriger.
 *
 * L'affectation est la seule source du périmètre d'un correcteur : sa file ne
 * contient que les copies des évaluations listées ici. Le supprimer d'une ligne
 * lui retire l'évaluation sans toucher aux autres, et supprimer une évaluation
 * retire la ligne en cascade.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('grader_assignments')) {
            return;
        }

        Schema::create('grader_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grader_id')->constrained()->onDelete('cascade');
            $table->foreignId('form_id')->constrained()->onDelete('cascade');
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            // Un correcteur n'est affecté qu'une fois à une même évaluation :
            // sans cette contrainte, la liste des missions se remplirait de
            // doublons à chaque clic.
            $table->unique(['grader_id', 'form_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grader_assignments');
    }
};
