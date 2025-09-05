<?php

namespace App\Domain\Game;


interface BoardReadModel
{
    public function size(): int;
    public function wasTried(Coordinate $c): bool;
}

