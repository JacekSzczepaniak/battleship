<?php
namespace App\Infrastructure\Persistence\InMemory;

use App\Application\Ports\GameRepository;
use App\Domain\Shared\GameId;
use App\Domain\Game\Game;

final class InMemoryGameRepository implements GameRepository
{
    /** @var array<string, Game> */
    private array $store = [];

    public function save(Game $game): void
    {
        $this->store[(string)$game->id()] = $game;
    }

    public function get(GameId $id): ?Game
    {
        return $this->store[(string)$id] ?? null;
    }
}
