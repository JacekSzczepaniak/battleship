<?php

namespace App\Application\Game;

use App\Domain\Game\Coordinate;
use App\Domain\Game\GameRepository;
use App\Domain\Shared\GameId;

final class FireShot
{
    public function __construct(private readonly GameRepository $repo)
    {
    }

    /** @return array{result:string, win:bool} */
    public function handle(string $gameId, int $x, int $y): array
    {
        $game = $this->repo->get(new GameId($gameId));
        if (!$game) {
            throw new \InvalidArgumentException('Game not found');
        }

        $out = $game->fireShot(new Coordinate($x, $y));

        // ważne: utrwal stan po strzale (żeby kolejne requesty widziały zmiany)
        $this->repo->save($game);

        return [
            'result' => $out->value,                     // 'miss' | 'hit' | 'sunk' | 'duplicate'
            'win' => 'won' === $game->status()->value, // dostosuj jeżeli masz inny enum/value
        ];
    }
}
