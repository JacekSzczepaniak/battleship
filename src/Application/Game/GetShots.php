<?php

namespace App\Application\Game;

use App\Domain\Game\GameRepository;
use App\Domain\Shared\GameId;

final class GetShots
{
    public function __construct(private readonly GameRepository $repo)
    {
    }

    /** @return array{finished:bool, shots:array<int, array{x:int,y:int,result:string}>} */
    public function handle(string $gameId): array
    {
        $game = $this->repo->get(new GameId($gameId));
        if (!$game) {
            throw new \InvalidArgumentException('Game not found');
        }

        return [
            'finished' => $game->isFinished(),
            'shots' => $game->shotsWithResults(),
        ];
    }
}
