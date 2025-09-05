<?php

namespace App\Domain\Game;

final class BoardReadModelAdapter implements BoardReadModel
{
    public function __construct(private TargetBoard $board)
    {
    }

    public function size(): int
    {
        return $this->board->size();
    }

    public function wasTried(Coordinate $c): bool
    {
        return $this->board->wasTried($c);
    }
}
