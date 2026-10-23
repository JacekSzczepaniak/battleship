<?php

namespace App\Domain\Game\Siege;

use App\Domain\Game\Coordinate;
use App\Domain\Game\Orientation;
use App\Domain\Game\Ship;
use App\Domain\Game\Weapon\Loadout;

/**
 * Układ fortu (#119): działa, odcinki muru i magazyn prochu. Na planszy wroga
 * każda konstrukcja stoi jako zwykły Ship bez broni (toShips()), więc strzały,
 * sonar, torpeda i nalot działają na fort bez wyjątków. Fort tylko mówi, co
 * jest czym: działa liczą się do wygranej, mur pochłania strzał, magazyn
 * wybucha.
 */
final class Fort
{
    /**
     * @param list<Coordinate> $guns
     * @param list<Ship>       $walls
     */
    public function __construct(
        public readonly int $seed,
        public readonly array $guns,
        public readonly array $walls,
        public readonly Coordinate $magazine,
    ) {
    }

    /**
     * Konstrukcje jako statki planszy wroga, bez wyposażenia (fort nie ma broni).
     *
     * @return list<Ship>
     */
    public function toShips(): array
    {
        $single = static fn (Coordinate $c) => new Ship($c, Orientation::H, 1, Loadout::none());
        $walls = array_map(
            static fn (Ship $w) => new Ship($w->start, $w->orientation, $w->length, Loadout::none()),
            $this->walls,
        );

        return [...array_map($single, $this->guns), ...$walls, $single($this->magazine)];
    }

    public function pieceAt(int $x, int $y): ?FortPiece
    {
        foreach ($this->guns as $gun) {
            if ($gun->x === $x && $gun->y === $y) {
                return FortPiece::Gun;
            }
        }
        if ($this->magazine->x === $x && $this->magazine->y === $y) {
            return FortPiece::Magazine;
        }
        foreach ($this->walls as $wall) {
            foreach ($wall->cells() as $c) {
                if ($c->x === $x && $c->y === $y) {
                    return FortPiece::Wall;
                }
            }
        }

        return null;
    }

    /**
     * Pola eksplozji magazynu: kwadrat o promieniu FortRules::EXPLOSION_RADIUS,
     * przycięty do planszy.
     *
     * @return list<Coordinate>
     */
    public function blastCells(int $width, int $height): array
    {
        $r = FortRules::EXPLOSION_RADIUS;
        $cells = [];
        for ($y = $this->magazine->y - $r; $y <= $this->magazine->y + $r; ++$y) {
            for ($x = $this->magazine->x - $r; $x <= $this->magazine->x + $r; ++$x) {
                if ($x >= 0 && $y >= 0 && $x < $width && $y < $height) {
                    $cells[] = new Coordinate($x, $y);
                }
            }
        }

        return $cells;
    }

    /**
     * Kształt snapshotu: seed (do podglądu), działa i magazyn; mury to reszta
     * statków planszy wroga, ale zapisujemy je też, żeby fort był samowystarczalny.
     *
     * @return array{seed:int, guns:list<array{0:int,1:int}>, walls:list<array{x:int,y:int,o:string,l:int}>, magazine:array{0:int,1:int}}
     */
    public function toArray(): array
    {
        return [
            'seed' => $this->seed,
            'guns' => array_map(static fn (Coordinate $c) => [$c->x, $c->y], $this->guns),
            'walls' => array_map(static fn (Ship $w) => ['x' => $w->start->x, 'y' => $w->start->y, 'o' => $w->orientation->value, 'l' => $w->length], $this->walls),
            'magazine' => [$this->magazine->x, $this->magazine->y],
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $cell = static fn (mixed $c) => new Coordinate((int) $c[0], (int) $c[1]);

        return new self(
            (int) ($data['seed'] ?? 0),
            array_values(array_map($cell, (array) ($data['guns'] ?? []))),
            array_values(array_map(
                static fn (array $w) => new Ship(new Coordinate((int) $w['x'], (int) $w['y']), Orientation::from((string) $w['o']), (int) $w['l'], Loadout::none()),
                (array) ($data['walls'] ?? []),
            )),
            $cell($data['magazine'] ?? [0, 0]),
        );
    }
}
