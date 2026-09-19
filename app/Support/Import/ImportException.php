<?php

namespace App\Support\Import;

use RuntimeException;

/**
 * Échec d'import dont le message est destiné à l'administrateur : il explique
 * quoi corriger (« enregistrez au format .xlsx ») plutôt que ce qui a planté.
 */
class ImportException extends RuntimeException
{
}
