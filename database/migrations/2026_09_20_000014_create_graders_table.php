<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les correcteurs externes : des personnes qui n'ont aucun compte
 * d'administration, et à qui l'on confie seulement des copies à corriger.
 *
 * Ce qui les sépare d'un administrateur tient en une phrase : ils n'ont pas de
 * mot de passe. Leur accès repose sur deux éléments distincts — un lien
 * personnel (`link_code`, huit caractères) et une référence (dix caractères)
 * qu'ils doivent saisir avec leur email. Le lien seul n'ouvre donc rien, et le
 * transmettre ne donne aucun pouvoir de correction.
 *
 * La référence est stockée en clair, comme celle des étudiants : l'enseignant
 * doit pouvoir la relire pour la retransmettre. Ce n'est pas son secret qui
 * protège l'accès, c'est son entropie et le frein posé sur les tentatives.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('graders')) {
            return;
        }

        Schema::create('graders', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');

            // Clé d'accès (10 caractères, alphabet sans O/0 ni I/1/L) et code du
            // lien personnel (8 caractères, mêmes précautions de dictée).
            $table->string('reference', 32)->unique();
            $table->string('link_code', 32)->unique();

            // Échéance de la mission : évaluée à chaque requête, donc aucune
            // tâche planifiée n'est nécessaire pour suspendre l'accès.
            $table->timestamp('expires_at')->nullable();

            // Dernière activité : c'est ce qui permet de dire à l'enseignant si
            // un correcteur a commencé, et depuis quelle adresse.
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();

            $table->timestamps();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('graders');
    }
};
