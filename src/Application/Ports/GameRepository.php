<?php
namespace App\Application\Ports;

use App\Domain\Shared\GameId;
use App\Domain\Game\Game;

interface GameRepository
{
    public function save(Game $game): void;
    public function get(GameId $id): ?Game;
}
