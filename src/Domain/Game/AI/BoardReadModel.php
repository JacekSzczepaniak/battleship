<?php

namespace App\Domain\Game\AI;

use App\Domain\Game\Coordinate;

/**
 * Tylko do odczytu – AI nie zna pełnego stanu, jedynie wyniki strzałów.
 */
interface BoardReadModel
{
    public function size(): int; // np. 10

    public function wasTried(Coordinate $c): bool;
}
