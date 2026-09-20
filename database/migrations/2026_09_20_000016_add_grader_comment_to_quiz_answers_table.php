<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le commentaire laissé sur une réponse rédigée.
 *
 * Une note sans phrase est vécue comme arbitraire, et se défend mal devant une
 * contestation : « 1,5/3 » n'explique rien, « les deux étapes sont justes mais
 * le calcul final est faux » se discute. Le commentaire vit sur la réponse, pas
 * sur la copie : c'est la réponse qu'on juge.
 *
 * Il n'est montré à l'étudiant qu'une fois **toutes** les rédactions notées
 * (voir QuizAttempt::pendingManualCount()) : un commentaire écrit pendant qu'une
 * autre réponse attend encore n'est pas forcément définitif.
 *
 * Une migration par colonne, nommée d'après elle : la réconciliation de
 * production (App\Support\MigrationReconciliation) sait ainsi constater qu'une
 * colonne déjà ajoutée par un déploiement interrompu est en place, au lieu
 * d'échouer sur « duplicate column ».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('quiz_answers', 'grader_comment')) {
            return;
        }

        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->text('grader_comment')->nullable()->after('points_awarded');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('quiz_answers', 'grader_comment')) {
            return;
        }

        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->dropColumn('grader_comment');
        });
    }
};
