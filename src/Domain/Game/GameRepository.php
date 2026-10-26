<?php

namespace App\Domain\Game;

use App\Domain\Shared\GameId;

interface GameRepository
{
    public function save(Game $game): void;

    public function get(GameId $id): ?Game;

    /** Usuwa grę, której bitwę anulowano przed rozstawieniem (#166); brak gry = nic. */
    public function remove(GameId $id): void;
}
