<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rend student_email nullable et relâche l'index unique (form_id,
 * student_email) : l'admin peut désormais corriger — voire vider — l'email
 * d'une soumission erronée. La garde anti-doublon reste appliquée au niveau
 * du contrôleur (SubmissionController), qui autorise un email vide.
 *
 * SQLite n'a pas besoin de cette migration : son schéma est créé à zéro par
 * la migration initiale (déjà nullable, sans index unique).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // The index name comes from Laravel's default naming convention used
        // by the original create migration.
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropUnique('submissions_form_id_student_email_unique');
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->string('student_email')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('submissions', function (Blueprint $table) {
            $table->string('student_email')->nullable(false)->change();
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->unique(['form_id', 'student_email']);
        });
    }
};
