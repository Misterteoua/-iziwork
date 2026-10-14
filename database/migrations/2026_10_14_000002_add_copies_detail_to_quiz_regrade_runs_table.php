<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le détail des copies d'un recalcul : lesquelles, et leur note avant/après.
 *
 * Le journal disait « trois copies ont changé de note » ; il dit maintenant
 * lesquelles, et ce qu'elles valaient avant. C'est la même information que
 * l'aperçu montre avant le clic, conservée après : sans elle, une note contestée
 * deux mois plus tard ne se vérifie qu'en la recalculant à la main.
 *
 * Le tableau est stocké en texte JSON, comme les autres listes du module
 * (`quiz_attempts.question_order`, `question_imports.questions`) : le type JSON
 * natif varie selon les versions de MariaDB, le modèle s'occupe de la conversion.
 *
 * Les copies y sont désignées par leur **référence**, la seule chose qui reste
 * d'une participation supprimée depuis, et affichées par leur nom seulement quand
 * l'évaluation est nominative — un journal d'évaluation anonyme ne doit pas
 * devenir le registre des noms.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('quiz_regrade_runs', 'copies_detail')) {
            return;
        }

        Schema::table('quiz_regrade_runs', function (Blueprint $table) {
            $table->longText('copies_detail')->nullable()->after('copies');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('quiz_regrade_runs', 'copies_detail')) {
            return;
        }

        Schema::table('quiz_regrade_runs', function (Blueprint $table) {
            $table->dropColumn('copies_detail');
        });
    }
};
