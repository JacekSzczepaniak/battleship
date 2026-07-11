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

    public function handle(?int $w = null, ?int $h = null, string $mode = 'classic'): Game
    {
        $size = new BoardSize($w ?? 10, $h ?? 10);
        $rules = 'fun' === $mode ? new FunRuleset($size) : new ClassicRuleset($size);

        $game = Game::create($rules);
        $this->repo->save($game);

        return $game;
    }
}
