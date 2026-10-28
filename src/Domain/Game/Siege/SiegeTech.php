<?php

namespace App\Domain\Game\Siege;

/**
 * Przewagi gracza w oblężeniu z gałęzi Oblężnictwo (#177). Gra nie zna
 * technologii: dostaje gotowe parametry przy starcie oblężenia wyprawy.
 * Trening i zwykłe bitwy ich nie mają.
 */
final class SiegeTech
{
    /** Promień wybuchu magazynu z Lontami prochowymi: 2 = kwadrat 5×5. */
    public const POWDER_FUSE_BLAST_RADIUS = 2;

    public function __construct(
        /** Lunety artyleryjskie: jedno działo fortu namierzone od startu. */
        public readonly bool $spyglasses = false,
        /** Promień wybuchu magazynu prochu (1 = 3×3). */
        public readonly int $blastRadius = FortRules::EXPLOSION_RADIUS,
    ) {
        if ($blastRadius < 1) {
            throw new \InvalidArgumentException('Blast radius must be at least 1');
        }
    }

    public static function none(): self
    {
        return new self();
    }
}
