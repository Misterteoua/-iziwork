<?php

namespace App\Support\Qr;

/**
 * Matrice de modules d'un QR code : un carré de booléens (true = module noir),
 * sans marge blanche. La marge est ajoutée au rendu, parce qu'elle se compte en
 * modules et non en pixels.
 */
final class QrMatrix
{
    /** @param array<int, array<int, bool>> $modules */
    public function __construct(
        private readonly array $modules,
        public readonly int $version,
        public readonly int $mask,
    ) {}

    /** Nombre de modules d'un côté (17 + 4 × version). */
    public function size(): int
    {
        return count($this->modules);
    }

    public function isDark(int $row, int $column): bool
    {
        return $this->modules[$row][$column] ?? false;
    }

    /** @return array<int, array<int, bool>> */
    public function rows(): array
    {
        return $this->modules;
    }
}
