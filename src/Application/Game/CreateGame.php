<?php
namespace App\Application\Game;

use App\Application\Ports\GameRepository;
use App\Domain\Game\ClassicRuleset;
use App\Domain\Game\Game;
use App\Domain\Game\BoardSize;

final class CreateGame
{
    public function __construct(private GameRepository $repo) {}

    public function handle(?int $w = null, ?int $h = null): Game
    {
        $rules = new ClassicRuleset(new BoardSize($w ?? 10, $h ?? 10));
        $game = Game::create($rules);
        $this->repo->save($game);
        return $game;
    }
}
