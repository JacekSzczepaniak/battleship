<?php

namespace App\Domain\Game;

final class RandomShooter implements Shooter
{
    /** @var array<string,bool> */
    private array $tried = [];

    public function nextShot(BoardReadModel $board): Coordinate
    {
        do {
            $x = random_int(0, $board->size() - 1);
            $y = random_int(0, $board->size() - 1);
            $c = new Coordinate($x, $y);
            $k = $x . ':' . $y;
        } while (($this->tried[$k] ?? false) || $board->wasTried($c));

        $this->tried[$k] = true;
        return $c;
    }

    public function notify(Coordinate $c, ShotResult $result): void
    {
        // nic – to tylko stub
    }
}
