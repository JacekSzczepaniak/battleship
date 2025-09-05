<?php

namespace App\Domain\Game;

interface Shooter
{
    public function nextShot(BoardReadModel $board): Coordinate;
    public function notify(Coordinate $c, ShotResult $result): void;
}
