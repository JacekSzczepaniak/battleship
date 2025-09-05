<?php

namespace App\Domain\Game\AI;

use App\Domain\Game\Coordinate;

enum ShotResult: string
{
    case MISS = 'MISS';
    case HIT = 'HIT';
    case SUNK = 'SUNK';
}
