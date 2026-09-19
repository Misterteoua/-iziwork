<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une « participation » à une évaluation : c'est aussi la référence remise à
 * l'étudiant. Quand l'administration prépare une liste de références, chaque
 * ligne existe avant même que l'étudiant ne commence ; elle porte alors le nom
 * et l'email importés (lot suivant), et la ligne reste « pending » tant que
 * l'épreuve n'a pas démarré.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->onDelete('cascade');

            // Référence alphanumérique de 10 caractères : c'est l'identifiant de
            // connexion de l'étudiant quand l'évaluation est anonyme.
            $table->string('reference', 10)->unique();

            // Renseignés à l'import de la liste, ou saisis par l'étudiant en
            // mode libre. Nulls en évaluation anonyme : le nom ne doit pas
            // exister, sinon l'anonymat n'en est plus un.
            $table->string('student_name')->nullable();
            $table->string('student_email')->nullable();
            $table->string('student_major')->nullable();

            $table->enum('status', ['pending', 'in_progress', 'submitted', 'expired'])
                ->default('pending');

            // expires_at est figée au démarrage de l'épreuve : c'est elle qui
            // fait foi pour le temps, jamais l'horloge du navigateur.
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('submitted_at')->nullable();

            $table->decimal('score', 6, 2)->nullable();
            $table->decimal('max_score', 6, 2)->nullable();

            // Journal des pertes de focus (changements d'onglet, sorties de
            // plein écran) : on trace, l'administrateur apprécie.
            $table->text('infractions')->nullable();
            $table->unsignedInteger('infraction_count')->default(0);

            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempts');
    }
};
