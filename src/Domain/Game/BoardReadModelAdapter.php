<?php

namespace App\Domain\Game;

final class BoardReadModelAdapter implements BoardReadModel
{
    public function __construct(private TargetBoard $board)
    {
    }

    public function width(): int
    {
        return $this->board->size();
    }

    public function height(): int
    {
        return $this->board->size();
    }

    public function wasTried(Coordinate $c): bool
    {
        return $this->board->wasTried($c);
    }
}
