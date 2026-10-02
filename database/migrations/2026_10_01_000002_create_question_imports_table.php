<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les imports de questions en attente de relecture.
 *
 * Un aperçu d'import n'est pas une page jetable : un enseignant peut fermer son
 * navigateur, se déconnecter, ou reprendre le travail depuis un autre poste. Le
 * jeu de questions analysé est donc gardé ici, tel qu'il est relu — découpages,
 * recousures et retouches comprises — plutôt que dans la session, qui meurt à la
 * déconnexion et ne suit pas d'un appareil à l'autre.
 *
 * Une ligne par import en attente : `questions` porte le jeu complet (en texte,
 * donc sans dépendre d'un type JSON selon la version de MySQL), `errors` les
 * lignes refusées au moment de l'analyse, `ignored` leur nombre. Le jeu est
 * remplacé par le nouvel aperçu du même enseignant sur la même évaluation, et
 * purgé au bout d'une semaine.
 *
 * `admin_user_id` n'a pas de clé étrangère : un import en attente appartient à
 * son auteur, et la reprise se limite à lui. La colonne est indexée avec
 * `form_id` parce que la question posée est toujours la même — « y a-t-il un
 * import en attente pour cette évaluation, et pour moi ? »
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('question_imports')) {
            return;
        }

        Schema::create('question_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('admin_user_id')->nullable();

            $table->longText('questions');
            $table->longText('errors')->nullable();
            $table->unsignedInteger('ignored')->default(0);

            $table->timestamps();

            $table->index(['form_id', 'admin_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_imports');
    }
};
