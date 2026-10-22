<?php

namespace App\Application\Game;

use App\Domain\Game\Coordinate;
use App\Domain\Game\FleetGenerator;
use App\Domain\Game\GameRepository;
use App\Domain\Game\Orientation;
use App\Domain\Game\Ship;
use App\Domain\Game\Weapon\Loadout;
use App\Domain\Shared\GameId;

final class PlaceFleet
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly FleetGenerator $opponentFleetGenerator,
        private readonly OpponentTurn $opponentTurn,
    ) {
    }

    /**
     * Opcjonalne `w` statku to jego wyposażenie {broń: użycia}; brak = domyślne
     * dla długości. Flota musi zgadzać się ze składem i wariantami rulesetu.
     *
     * @param array<int,array{x:int,y:int,o:string,l:int,w?:mixed}> $shipsSpec
     */
    public function handle(string $gameId, array $shipsSpec): void
    {
        $game = $this->repo->get(new GameId($gameId));
        if (!$game) {
            throw new \RuntimeException('Game not found');
        }

        $ships = [];
        foreach ($shipsSpec as $s) {
            $ships[] = new Ship(
                new Coordinate((int) $s['x'], (int) $s['y']),
                Orientation::from(strtoupper((string) $s['o'])),
                (int) $s['l'],
                $this->loadoutOrNull($s['w'] ?? null),
            );
        }

        $game->placeFleet($ships);

        // Po rozstawieniu floty gracza – wygeneruj flotę przeciwnika
        $game->placeOpponentFleet($this->opponentFleetGenerator->generate($game->ruleset()));

        // gra zaczynana turą przeciwnika (obrona wyspy, #78): napastnik strzela
        // pierwszy, zanim gracz zobaczy planszę bitwy
        if ('opponent' === $game->turn()) {
            $this->opponentTurn->respond($game);
        }

        $this->repo->save($game);
    }

    private function loadoutOrNull(mixed $raw): ?Loadout
    {
        if (null === $raw) {
            return null;
        }
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('Invalid ship loadout');
        }

        return Loadout::fromArray($raw);
    }
}
