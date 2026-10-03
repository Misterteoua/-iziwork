<?php

namespace App\Support;

/**
 * Images embarquées dans un PDF, lues en base64.
 *
 * dompdf n'a pas accès au disque depuis un gabarit de la même façon qu'une page
 * web : il ne peut pas aller chercher une URL, et un chemin relatif dépend du
 * `chroot` configuré. Le motif retenu dans ce projet — encoder le fichier en
 * base64 — fonctionne partout, mais il lève une erreur fatale si le fichier
 * n'existe pas (`file_get_contents` sur un chemin absent).
 *
 * Ce helper rend cette lecture **tolérante** : un logo manquant donne `null`,
 * et le gabarit omet simplement le bloc. Un document ne doit jamais échouer à se
 * générer parce qu'une image d'habillage est absente du serveur.
 */
final class PdfAssets
{
    /** Types d'images acceptés, avec leur type MIME. */
    private const MIME_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
    ];

    /**
     * L'image en URI de données, ou `null` si elle est introuvable.
     *
     * @param  string  $relativePath  chemin relatif à `public/` (ex. « images/logo.png »)
     */
    public static function dataUri(string $relativePath): ?string
    {
        $path = public_path($relativePath);

        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false || $contents === '') {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = self::MIME_TYPES[$extension] ?? 'application/octet-stream';

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }

    /** Le fichier existe-t-il ? (pour décider d'afficher un bloc ou non) */
    public static function exists(string $relativePath): bool
    {
        return is_file(public_path($relativePath));
    }
}
