<?php

namespace App\Domain\Game;

enum GameStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Finished = 'finished';
}
