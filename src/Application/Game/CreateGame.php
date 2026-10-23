<?php

namespace App\Application\Game;

use App\Domain\Game\BoardSize;
use App\Domain\Game\ClassicRuleset;
use App\Domain\Game\FunRuleset;
use App\Domain\Game\Game;
use App\Domain\Game\GameRepository;
use App\Domain\Game\Weapon\Loadout;

final class CreateGame
{
    public function __construct(private GameRepository $repo)
    {
    }

    /**
     * @param array<int,int>|null           $ships            skład floty (długość => sztuki); null = flota klasyczna
     * @param string                        $firstTurn        'opponent' = pierwszą salwę oddaje AI zaraz po rozstawieniu floty (obrona wyspy, #78)
     * @param array<int,int>|null           $opponentShips    skład floty AI; null = lustrzany (inny przy bonusie pełnej bandery, #79)
     * @param array<int,list<Loadout>>|null $variants         warianty wyposażenia (#95); AI dostaje je lustrzanie
     * @param int|null                      $sonarRadius      promień sonaru gracza w trybie fun (null = bazowy); AI zostaje przy bazowym
     * @param array<int,list<Loadout>>|null $opponentVariants warianty AI; null = lustro wariantów gracza ([] = same domyślne, strażnik cieśniny)
     * @param bool                          $boarding         abordaż ostatniego statku wroga (#118): bitwy wyprawy; działa tylko z energią (fun)
     */
    public function handle(?int $w = null, ?int $h = null, string $mode = 'classic', ?array $ships = null, string $firstTurn = 'player', ?array $opponentShips = null, ?array $variants = null, ?int $sonarRadius = null, ?array $opponentVariants = null, bool $boarding = false): Game
    {
        $size = new BoardSize($w ?? 10, $h ?? 10);
        $rules = 'fun' === $mode
            ? new FunRuleset($size, $ships, $opponentShips, $variants, $opponentVariants, $sonarRadius)
            : new ClassicRuleset($size, $ships, $opponentShips, $variants, $opponentVariants);

        $game = Game::create($rules);
        $game->setTurn($firstTurn);
        if ($boarding) {
            $game->enableBoarding();
        }
        $this->repo->save($game);

        return $game;
    }
}
