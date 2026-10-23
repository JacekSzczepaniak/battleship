<?php

namespace App\Domain\Expedition;

/**
 * Stan kapitana na jednym akwenie (Epic I): pozycja floty, odkryte
 * i odwiedzone sektory. Flota stoi w jednym akwenie naraz; stan pozostałych
 * czeka na powrót. Część agregatu CaptainProfile.
 */
final class SeaState
{
    /** @var array<string,bool> odkryte sektory (klucz "x:y") */
    private array $discovered;

    /**
     * @var array<string,bool> sektory, na których kapitan był (klucz "x:y"). Odkrycie
     *                         sięga widoczności akwenu, odwiedziny — tylko pozycji; sztorm
     *                         grozi przy pierwszym wpłynięciu na sektor
     */
    private array $visited;

    /**
     * @param array{x:int,y:int}|null $position   null = akwen jeszcze nieodwiedzony
     * @param list<string>            $discovered sektory "x:y"
     * @param list<string>            $visited    sektory "x:y"
     */
    public function __construct(private ?array $position = null, array $discovered = [], array $visited = [])
    {
        $this->discovered = array_fill_keys($discovered, true);
        $this->visited = array_fill_keys($visited, true);
    }

    /** @return array{x:int,y:int}|null */
    public function position(): ?array
    {
        return $this->position;
    }

    /** Flota wpływa na sektor: staje na nim i go odwiedza. */
    public function moveTo(int $x, int $y): void
    {
        $this->position = ['x' => $x, 'y' => $y];
        $this->visited["$x:$y"] = true;
    }

    /** Odkrywa sektor; false = był już odkryty. */
    public function discover(int $x, int $y): bool
    {
        if ($this->isDiscovered($x, $y)) {
            return false;
        }
        $this->discovered["$x:$y"] = true;

        return true;
    }

    public function isDiscovered(int $x, int $y): bool
    {
        return isset($this->discovered["$x:$y"]);
    }

    public function isVisited(int $x, int $y): bool
    {
        return isset($this->visited["$x:$y"]);
    }

    /** @return list<string> odkryte sektory jako "x:y" */
    public function discoveredSectors(): array
    {
        return array_map('strval', array_keys($this->discovered));
    }

    /** @return list<string> odwiedzone sektory jako "x:y" */
    public function visitedSectors(): array
    {
        return array_map('strval', array_keys($this->visited));
    }
}
