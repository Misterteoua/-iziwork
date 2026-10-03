<?php

namespace App\Support;

use Barryvdh\DomPDF\PDF;
use Dompdf\Canvas;
use Dompdf\FontMetrics;

/**
 * Filigrane et numéro de document des PDF générés.
 *
 * Deux protections, complémentaires :
 *
 *   1. un **filigrane** diagonal, répété sur chaque page, dessiné en marge du
 *      contenu HTML. Il ne peut pas être retiré en éditant le gabarit : il est
 *      tracé directement dans le flux du PDF, après le rendu de la page.
 *   2. un **numéro de document unique**, dérivé de la clé de l'application et de
 *      l'identifiant de l'objet. Un document falsifié ou recopié d'un autre
 *      dossier porte un numéro qui ne correspond pas à celui du dossier réel —
 *      c'est ce qui permet de le détecter.
 *
 * Pourquoi un callback dompdf plutôt qu'un simple bloc HTML ?
 *
 *   dompdf n'applique **pas** la rotation CSS (`transform`) : un `<div>`
 *   incliné se rend droit. Le CSS `opacity`, lui, est bien supporté, mais pas la
 *   rotation. La seule voie fiable est de dessiner après coup sur le canevas de
 *   la page, via l'événement `end_document` — qui reçoit le canevas et les
 *   métriques de police. On y trace un texte incliné et translucide, page par
 *   page.
 *
 * Le numéro est calculé par HMAC : il est **stable** (le même document donne
 * toujours le même numéro) et **impossible à fabriquer** sans la clé de
 * l'application. Il ne dépend d'aucune donnée affichée dans le document, donc il
 * reste un repère de contrôle indépendant du contenu.
 */
final class PdfWatermark
{
    /** Opacité du filigrane : présent, mais jamais au point de gêner la lecture. */
    private const OPACITY = 0.06;

    /** Couleur du filigrane (bleu de la marque, en flottants 0–1 pour dompdf). */
    private const COLOR = [0.02, 0.20, 0.60];

    /** Corps du texte du filigrane, en points. */
    private const FONT_SIZE = 34;

    /** Inclinaison du filigrane, en degrés. */
    private const ANGLE = 45;

    /** Hauteurs (en points, depuis le haut) des rangées du filigrane. */
    private const ROWS = [170, 420, 670];

    /** Nombre de caractères hexadécimaux du numéro, hors préfixe. */
    private const ID_LENGTH = 12;

    /**
     * Numéro de document, stable et infalsifiable.
     *
     * @param  string  $scope       nature du document (« quiz-attempt », « submission »…)
     * @param  string|int  $identifier  identifiant stable de l'objet
     */
    public static function documentId(string $scope, string|int $identifier): string
    {
        // La clé peut manquer sur une installation mal configurée : on retombe
        // alors sur une valeur fixe, plutôt que de lever une erreur en pleine
        // génération de PDF. Le numéro perd sa garantie, mais le document se
        // génère quand même.
        $key = (string) config('app.key');

        if ($key === '') {
            $key = 'iziwork-sans-cle';
        }

        $digest = strtoupper(substr(
            hash_hmac('sha256', $scope.':'.$identifier, $key),
            0,
            self::ID_LENGTH
        ));

        // Groupes de quatre, plus lisibles à l'œil et à la recopie.
        return 'DOC-'.implode('-', str_split($digest, 4));
    }

    /**
     * Appose le filigrane portant `$documentId` sur **toutes** les pages du PDF.
     *
     * À appeler après `Pdf::loadView(...)`, avant `download()`.
     */
    public static function apply(PDF $pdf, string $documentId): void
    {
        $pdf->getDomPDF()->setCallbacks([
            [
                'event' => 'end_document',
                'f' => static function (
                    int $pageNumber,
                    int $pageCount,
                    Canvas $canvas,
                    FontMetrics $fontMetrics
                ) use ($documentId): void {
                    self::drawOnCanvas($canvas, $fontMetrics, $documentId);
                },
            ],
        ]);
    }

    /** Trace le filigrane sur une page déjà rendue. */
    private static function drawOnCanvas(Canvas $canvas, FontMetrics $fontMetrics, string $documentId): void
    {
        // Helvetica est une police « cœur » de dompdf : rien à embarquer, donc
        // le filigrane n'alourdit pas le document.
        $font = $fontMetrics->getFont('Helvetica', 'bold');

        if ($font === null) {
            return;
        }

        $width = $canvas->get_width();
        $textWidth = $fontMetrics->getTextWidth($documentId, $font, self::FONT_SIZE);

        // Espacement horizontal : la largeur du texte plus une respiration, de
        // sorte que deux occurrences ne se chevauchent jamais.
        $step = max(1.0, $textWidth + 90.0);

        // La page est inclinée à 45° : on déborde volontairement des deux côtés
        // pour couvrir les angles, où le motif serait sinon absent.
        $from = -$width;
        $to = $width * 2.0;

        $canvas->set_opacity(self::OPACITY);

        foreach (self::ROWS as $y) {
            for ($x = $from; $x <= $to; $x += $step) {
                $canvas->text(
                    (float) $x,
                    (float) $y,
                    $documentId,
                    $font,
                    self::FONT_SIZE,
                    self::COLOR,
                    0.0,
                    0.0,
                    self::ANGLE
                );
            }
        }

        // On rétablit l'opacité : la page suivante du callback repartira propre.
        $canvas->set_opacity(1.0);
    }
}
