<?php

namespace App\Domain\Game\AI;

use App\Domain\Game\Coordinate;

final readonly class ShotOutcome
{
    public function __construct(
        public Coordinate $coordinate,
        public ShotResult $result
    ) {
    }
}
