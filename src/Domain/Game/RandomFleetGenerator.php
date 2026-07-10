<?php

namespace App\Domain\Game;

/**
 * Losowy układ floty wg ruleset->allowedShips(): dla każdego statku losuje
 * pozycję do skutku (walidacja przez Board::place()), a przy zablokowanym
 * układzie restartuje całość. Po wyczerpaniu prób wraca do układu
 * deterministycznego, więc nigdy nie kończy się wyjątkiem.
 */
final class RandomFleetGenerator implements FleetGenerator
{
    private const MAX_FLEET_ATTEMPTS = 100;
    private const MAX_SHIP_ATTEMPTS = 200;

    public function __construct(
        private readonly DeterministicFleetGenerator $fallback = new DeterministicFleetGenerator(),
    ) {
    }

    public function generate(Ruleset $ruleset): array
    {
        $size = $ruleset->boardSize();
        $lengths = $this->lengthsFrom($ruleset->allowedShips());

        for ($attempt = 0; $attempt < self::MAX_FLEET_ATTEMPTS; ++$attempt) {
            $ships = $this->tryPlaceFleet($lengths, $size);
            if (null !== $ships) {
                return $ships;
            }
        }

        // statystycznie nieosiągalne dla klasycznej floty na 10x10, ale bez ryzyka pętli/wyjątku
        return $this->fallback->generate($ruleset);
    }

    /**
     * Rozwija mapę długość => liczba sztuk w listę długości, od najdłuższych
     * (duże statki najtrudniej upchnąć, więc idą pierwsze).
     *
     * @param array<int,int> $allowedShips
     * @return list<int>
     */
    private function lengthsFrom(array $allowedShips): array
    {
        krsort($allowedShips);
        $lengths = [];
        foreach ($allowedShips as $length => $count) {
            for ($i = 0; $i < $count; ++$i) {
                $lengths[] = $length;
            }
        }

        return $lengths;
    }

    /**
     * @param list<int> $lengths
     * @return Ship[]|null null, gdy układu nie udało się domknąć
     */
    private function tryPlaceFleet(array $lengths, BoardSize $size): ?array
    {
        $board = new Board($size);
        $ships = [];

        foreach ($lengths as $length) {
            $placed = false;
            for ($i = 0; $i < self::MAX_SHIP_ATTEMPTS && !$placed; ++$i) {
                $candidate = $this->randomShip($length, $size);
                try {
                    $board->place($candidate);
                    $ships[] = $candidate;
                    $placed = true;
                } catch (\DomainException) {
                    // kolizja lub styk – losuj dalej
                }
            }
            if (!$placed) {
                return null;
            }
        }

        return $ships;
    }

    private function randomShip(int $length, BoardSize $size): Ship
    {
        $orientation = 0 === random_int(0, 1) ? Orientation::H : Orientation::V;

        if (Orientation::H === $orientation) {
            $x = random_int(0, max(0, $size->width - $length));
            $y = random_int(0, $size->height - 1);
        } else {
            $x = random_int(0, $size->width - 1);
            $y = random_int(0, max(0, $size->height - $length));
        }

        return new Ship(new Coordinate($x, $y), $orientation, $length);
    }
}
