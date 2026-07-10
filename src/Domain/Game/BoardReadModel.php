<?php

namespace App\Domain\Game;

/**
 * Widok planszy dla AI – tylko rozmiar i informacja, czy pole było już ostrzelane.
 */
interface BoardReadModel
{
    public function width(): int;

    public function height(): int;

    public function wasTried(Coordinate $c): bool;
}
