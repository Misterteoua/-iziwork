<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Une pièce jointe déposée par un étudiant en réponse à une question.
 *
 * La ligne ne porte que des métadonnées : le fichier lui-même vit sur le disque
 * privé, et n'est servi qu'à travers une route qui vérifie d'abord à qui la
 * copie appartient. Le nom d'origine est conservé pour l'affichage et le
 * téléchargement, le nom stocké (un UUID) pour éviter toute collision et tout
 * nom de fichier choisi par l'utilisateur sur le disque.
 */
class QuizAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'quiz_attempt_id',
        'form_field_id',
        'original_name',
        'stored_name',
        'file_path',
        'file_size',
        'mime_type',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    public function attempt()
    {
        return $this->belongsTo(QuizAttempt::class, 'quiz_attempt_id');
    }

    public function field()
    {
        return $this->belongsTo(FormField::class, 'form_field_id');
    }

    /**
     * Taille lisible : « 812 Ko », « 1,2 Mo ».
     *
     * Le même calcul que pour les dépôts de travaux, pour que les deux écrans
     * annoncent une taille de la même façon.
     */
    public function getFormattedSizeAttribute(): string
    {
        $bytes = max((int) $this->file_size, 0);
        $units = ['o', 'Ko', 'Mo', 'Go'];
        $pow = $bytes > 0 ? (int) floor(log($bytes) / log(1024)) : 0;
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2).' '.$units[$pow];
    }

    /** Le fichier est-il une image ? (décide de l'aperçu, pas du droit d'accès) */
    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }
}
