<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute le type « radio » (choix unique) à la liste des types de champs.
 *
 * C'est le type d'une question à choix multiple, par opposition à « checkbox »
 * qui autorise plusieurs réponses. Le type « select » existant reste ce qu'il
 * est : une liste déroulante de dépôt, pas une question.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_fields', function (Blueprint $table) {
            $table->enum('field_type', ['text', 'email', 'tel', 'file', 'select', 'checkbox', 'radio'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('form_fields', function (Blueprint $table) {
            $table->enum('field_type', ['text', 'email', 'tel', 'file', 'select', 'checkbox'])->change();
        });
    }
};
