<?php

namespace App\Application\Game;

use App\Domain\Game\BoardSize;
use App\Domain\Game\ClassicRuleset;
use App\Domain\Game\FunRuleset;
use App\Domain\Game\Game;
use App\Domain\Game\GameRepository;

final class CreateGame
{
    public function __construct(private GameRepository $repo)
    {
    }

    /**
     * @param array<int,int>|null $ships     skład floty (długość => sztuki); null = flota klasyczna
     * @param string              $firstTurn 'opponent' = pierwszą salwę oddaje AI zaraz po rozstawieniu floty (obrona wyspy, #78)
     */
    public function handle(?int $w = null, ?int $h = null, string $mode = 'classic', ?array $ships = null, string $firstTurn = 'player'): Game
    {
        $size = new BoardSize($w ?? 10, $h ?? 10);
        $rules = 'fun' === $mode ? new FunRuleset($size, $ships) : new ClassicRuleset($size, $ships);

        $game = Game::create($rules);
        $game->setTurn($firstTurn);
        $this->repo->save($game);

        return $game;
    }
}
