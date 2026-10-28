<?php

namespace App\Application\Game;

use App\Domain\Game\BattlePerks;
use App\Domain\Game\BoardSize;
use App\Domain\Game\ClassicRuleset;
use App\Domain\Game\FunRuleset;
use App\Domain\Game\Game;
use App\Domain\Game\GameRepository;
use App\Domain\Game\Siege\FortGenerator;
use App\Domain\Game\Siege\FortRules;
use App\Domain\Game\Siege\SiegeTech;
use App\Domain\Game\Weapon\Loadout;

final class CreateGame
{
    public function __construct(
        private GameRepository $repo,
        private FortGenerator $forts = new FortGenerator(),
    ) {
    }

    /**
     * @param array<int,int>|null           $ships            skład floty (długość => sztuki); null = flota klasyczna
     * @param string                        $firstTurn        'opponent' = pierwszą salwę oddaje AI zaraz po rozstawieniu floty (obrona wyspy, #78)
     * @param array<int,int>|null           $opponentShips    skład floty AI; null = lustrzany (inny przy bonusie pełnej bandery, #79)
     * @param array<int,list<Loadout>>|null $variants         warianty wyposażenia (#95); AI dostaje je lustrzanie
     * @param int|null                      $sonarRadius      promień sonaru gracza w trybie fun (null = bazowy); AI zostaje przy bazowym
     * @param array<int,list<Loadout>>|null $opponentVariants warianty AI; null = lustro wariantów gracza (strażnik cieśniny: jego własne, wyspa: lustro + wariant ze zdobyczy, #121)
     * @param int|null                      $fortSeed         bitwa oblężnicza (#119): fort z tego seeda zamiast floty AI;
     *                                                        plansza zawsze FortRules::SIZE, zasady fun (pełny arsenał i energia)
     * @param bool                          $boarding         abordaż ostatniego statku wroga (#118): bitwy wyprawy; działa tylko z energią (fun), nie w oblężeniu
     * @param BattlePerks|null              $perks            przewagi gracza z perków kapitana (#161): bitwy wyprawy; null = bez perków (trening)
     * @param SiegeTech|null                $siegeTech        technologie oblężnicze gracza (#177): tylko z $fortSeed; null = bez technologii (trening)
     */
    public function handle(?int $w = null, ?int $h = null, string $mode = 'classic', ?array $ships = null, string $firstTurn = 'player', ?array $opponentShips = null, ?array $variants = null, ?int $sonarRadius = null, ?array $opponentVariants = null, ?int $fortSeed = null, bool $boarding = false, ?BattlePerks $perks = null, ?SiegeTech $siegeTech = null): Game
    {
        $perks ??= BattlePerks::none();
        if (null !== $fortSeed) {
            [$w, $h, $mode] = [FortRules::SIZE, FortRules::SIZE, 'fun'];
        }
        $size = new BoardSize($w ?? 10, $h ?? 10);
        $rules = 'fun' === $mode
            ? new FunRuleset($size, $ships, $opponentShips, $variants, $opponentVariants, $sonarRadius, $perks->extraSonarUses)
            : new ClassicRuleset($size, $ships, $opponentShips, $variants, $opponentVariants);

        $game = Game::create($rules);
        $game->setTurn($firstTurn);
        $game->grantFirstTurnEnergy($perks->firstTurnEnergy);
        if ($boarding) {
            $game->enableBoarding($perks->boardingEdge);
        }
        if (null !== $fortSeed) {
            $game->placeFort($this->forts->generate($fortSeed), $siegeTech);
        }
        $this->repo->save($game);

        return $game;
    }
}
