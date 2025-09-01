<?php

namespace App\Domain\Game;

final class BoardSize
{
    public function __construct(public int $width, public int $height)
    {
        if ($width < 5 || $height < 5) {
            throw new \InvalidArgumentException('Board too small');
        }
    }
}
