<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les liens courts : huit caractères au lieu du jeton de trente-deux, pour un
 * lien qui se recopie dans un message ou s'écrit au tableau sans faute de frappe.
 *
 * Trois natures de lien, une seule table :
 *   - `quiz`   : l'accès à une évaluation ;
 *   - `form`   : l'accès à un formulaire de dépôt ;
 *   - `result` : le résultat personnel d'un étudiant, pour qu'il puisse revenir
 *                consulter sa note définitive après la correction des questions
 *                rédigées, depuis n'importe quel appareil.
 *
 * Le code est tiré au sort (voir App\Support\ShortCode), jamais séquentiel :
 * personne ne peut deviner l'adresse d'un étudiant en incrémentant un
 * identifiant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('short_links', function (Blueprint $table) {
            $table->id();

            // Alphabet sans O/0 ni I/l : un code se dicte et se retape.
            $table->string('code', 16)->unique();

            $table->string('kind', 16);

            $table->foreignId('form_id')->constrained()->onDelete('cascade');

            // Renseigné pour la seule nature « résultat ».
            $table->foreignId('quiz_attempt_id')->nullable()
                ->constrained('quiz_attempts')->onDelete('cascade');

            $table->timestamps();

            // Une seule ligne par cible : un formulaire garde le même lien court
            // d'un affichage à l'autre. MySQL considère deux NULL comme
            // distincts, donc l'unicité des liens `quiz` et `form` (sans copie)
            // est garantie côté modèle, par ShortLink::target().
            $table->unique(['kind', 'form_id', 'quiz_attempt_id'], 'short_links_target_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('short_links');
    }
};
