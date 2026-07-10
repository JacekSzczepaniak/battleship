<?php

namespace App\Domain\Game;

/**
 * Widok planszy zbudowany z mapy oddanych strzałów (klucze "x:y").
 * Używany przez Game do wystawienia AI stanu ostrzału bez odsłaniania statków.
 */
final class ShotsTakenView implements BoardReadModel
{
    /** @param array<string,bool> $taken */
    public function __construct(
        private readonly int $width,
        private readonly int $height,
        private readonly array $taken,
    ) {
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function wasTried(Coordinate $c): bool
    {
        return $this->taken[$c->x.':'.$c->y] ?? false;
    }
}
