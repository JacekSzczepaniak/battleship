<?php

declare(strict_types=1);

namespace App\Domain\Game;

enum ShotResult: string
{
    case Miss = 'miss';
    case Hit = 'hit';
    case Sunk = 'sunk';
    case Duplicate = 'duplicate';

}
