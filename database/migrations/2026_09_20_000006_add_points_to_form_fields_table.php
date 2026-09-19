<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Barème d'une question. Décimal pour autoriser les demi-points, avec 1 point
 * par défaut : une question créée sans réfléchir au barème compte quand même.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_fields', function (Blueprint $table) {
            $table->decimal('points', 5, 2)->default(1)->after('correct_answer');
        });
    }

    public function down(): void
    {
        Schema::table('form_fields', function (Blueprint $table) {
            $table->dropColumn('points');
        });
    }
};
