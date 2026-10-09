<?php

use App\Support\QuizQuestionData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Énoncé d'une question : de VARCHAR(255) à TEXT.
 *
 * L'application accepte un énoncé de 2000 caractères
 * ({@see QuizQuestionData::MAX_LABEL_LENGTH}), à la saisie manuelle
 * comme à l'import par fichier. La colonne, elle, n'en acceptait que 255.
 *
 * SQLite — le poste de développement — ne contrôle pas la longueur déclarée :
 * tout passait donc en local. MySQL, en production et en mode strict, refuse
 * au-delà de 255 par « Data too long for column 'field_label' », ce qui faisait
 * échouer l'import *entier* sur une page blanche, pour des questions pourtant
 * parfaitement légitimes : un cas pratique dépasse couramment 255 caractères
 * (1513 mesurés dans une évaluation réelle).
 *
 * Élargir est la seule façon de tenir la promesse des 2000 caractères sans
 * réécrire les fichiers des enseignants. Sur MySQL, c'est un simple
 * `alter table form_fields modify field_label text not null` : aucune donnée
 * n'est réécrite, aucun index ni clé étrangère n'est touché — la colonne n'est
 * ni indexée ni référencée par une autre table. Sur SQLite, Laravel reconstruit
 * la table, exactement comme le fait déjà la migration de l'énumération
 * `field_type` ; les lignes sont recopiées à l'identique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_fields', function (Blueprint $table) {
            $table->text('field_label')->change();
        });
    }

    public function down(): void
    {
        // Rétrécir tronquerait les énoncés longs, et MySQL refuserait la
        // conversion : on le dit explicitement plutôt que d'échouer sur un
        // message obscur, comme pour le retour en arrière de l'énumération.
        if (DB::table('form_fields')->whereRaw('length(field_label) > 255')->exists()) {
            throw new RuntimeException(
                'Des énoncés de plus de 255 caractères existent : raccourcissez-les avant de revenir en arrière.'
            );
        }

        Schema::table('form_fields', function (Blueprint $table) {
            $table->string('field_label')->change();
        });
    }
};
