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

    /** Types et extensions d'image matricielle montrables en aperçu. */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

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

    /**
     * Le fichier est-il une image affichable en aperçu ?
     *
     * Seuls les formats matriciels comptent : un SVG, qui peut porter du script,
     * n'est jamais un aperçu. Le type enregistré est confronté au nom d'origine,
     * pour qu'une ligne ancienne sans type reconnu reste téléchargeable sans
     * jamais s'afficher dans la page.
     */
    public function isImage(): bool
    {
        if (in_array(strtolower((string) $this->mime_type), self::IMAGE_MIMES, true)) {
            return true;
        }

        $extension = strtolower(pathinfo((string) ($this->original_name ?: $this->stored_name), PATHINFO_EXTENSION));

        return in_array($extension, self::IMAGE_EXTENSIONS, true);
    }
}
