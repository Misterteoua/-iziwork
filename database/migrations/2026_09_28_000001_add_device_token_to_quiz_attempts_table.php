<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'empreinte de l'appareil qui a remis une copie.
 *
 * Une évaluation anonyme sans liste de références ne laisse aucune identité :
 * ni nom, ni adresse email. Pour pouvoir refuser une deuxième copie sans
 * bloquer tout un amphi, l'application pose un identifiant d'appareil (cookie
 * chiffré, stable dans le temps) et le recopie ici au démarrage de l'épreuve.
 *
 * C'est une empreinte, jamais une identité : deux candidats qui se succèdent
 * sur le même poste la partagent, et c'est précisément pour cela que la règle
 * « une seule copie par appareil » est un **réglage** d'évaluation, désactivé
 * par défaut — une salle informatique ne doit jamais être bloquée par lui.
 *
 * Colonne nullable, sans index : elle est lue pour une évaluation et une
 * empreinte données, jamais parcourue en entier, et les copies existantes
 * (antérieures à cette version) n'ont évidemment pas d'empreinte.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('quiz_attempts', 'device_token')) {
            return;
        }

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->string('device_token', 64)->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('quiz_attempts', 'device_token')) {
            return;
        }

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropColumn('device_token');
        });
    }
};
