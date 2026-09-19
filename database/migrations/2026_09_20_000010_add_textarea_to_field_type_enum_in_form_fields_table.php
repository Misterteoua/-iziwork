<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute le type « textarea » (question ouverte, réponse rédigée) à la liste des
 * types de champs.
 *
 * La valeur est ajoutée en fin de liste : les questions déjà enregistrées
 * (« radio », « checkbox ») restent valides, et rien n'est converti. Modifier un
 * ENUM en y ajoutant une valeur ne touche aucune ligne existante sur MySQL.
 *
 * Ce type n'est pas proposé par le constructeur de formulaires de dépôt : les
 * listes fermées de StoreFormRequest/UpdateFormRequest ne le contiennent pas, une
 * question d'évaluation ne peut donc pas se retrouver dans un dépôt de travaux.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_fields', function (Blueprint $table) {
            $table->enum('field_type', ['text', 'email', 'tel', 'file', 'select', 'checkbox', 'radio', 'textarea'])->change();
        });
    }

    public function down(): void
    {
        // Le retour en arrière ne peut s'appliquer que si aucune question ouverte
        // n'existe : sinon MySQL refuserait la valeur et la migration échouerait
        // avec un message obscur. On le dit explicitement avant d'essayer.
        $openQuestions = \Illuminate\Support\Facades\DB::table('form_fields')
            ->where('field_type', 'textarea')
            ->exists();

        if ($openQuestions) {
            throw new \RuntimeException(
                'Des questions à réponse rédigée existent : supprimez-les avant de revenir en arrière.'
            );
        }

        Schema::table('form_fields', function (Blueprint $table) {
            $table->enum('field_type', ['text', 'email', 'tel', 'file', 'select', 'checkbox', 'radio'])->change();
        });
    }
};
