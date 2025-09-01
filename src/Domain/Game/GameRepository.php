<?php

namespace App\Domain\Game;

use App\Domain\Shared\GameId;

interface GameRepository
{
    public function save(Game $game): void;

    public function get(GameId $id): ?Game;
}
