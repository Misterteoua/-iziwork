<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distingue un dépôt de travaux d'une évaluation en ligne.
 *
 * La valeur par défaut « deposit » est ce qui rend l'ajout sans risque : tous
 * les formulaires existants gardent exactement leur comportement, et aucune
 * requête du module de dépôt n'a besoin d'être modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->enum('type', ['deposit', 'quiz'])->default('deposit')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
