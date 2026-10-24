<?php

namespace App\Domain\Expedition;

/**
 * Mapa wolnego morza generowana deterministycznie z seeda świata (LCG —
 * żadnego globalnego RNG; ten sam seed = ten sam świat, co daje powtarzalne
 * testy i replay). Wyspy trasy lądują w pionowych pasmach coraz dalej od
 * startu — progresja przestrzenna odpowiada progresji rang. Startem wyprawy
 * jest sektor pierwszej wyspy (Zatoka Rozbitka). Każdy akwen ma własną mapę
 * (Sea::map()), która zna id i motyw swojego akwenu oraz sektory jego bram
 * na krawędziach mapy (Epic I).
 */
final class WorldMap
{
    public const WIDTH = 12;
    public const HEIGHT = 12;

    /**
     * Mapy pozostałych akwenów świata (#109): kontratak celuje w wyspę
     * dowolnego akwenu, więc profil musi znać jej przystanie, złoża i motyw.
     * Pusta lista = świat z jednym akwenem (testy, profile sprzed akwenów).
     *
     * @var list<WorldMap>
     */
    private array $otherSeas = [];

    /**
     * @param array<string, array{x:int,y:int}> $islands
     * @param array<string, Island>             $deposits    wyspy ze złożem (klucz: id)
     * @param array<string, bool>               $safeHarbors bezpieczne przystanie (klucz: id) — kontrataki ich nie dotyczą
     * @param array<string, array{x:int,y:int}> $gates       sektory bram akwenu (klucz: id bramy)
     * @param array{x:int,y:int}|null           $tradePort   sektor portu handlowego (#140); null = akwen bez portu
     */
    private function __construct(
        private array $islands,
        private array $deposits,
        private array $safeHarbors,
        private SeaTheme $theme,
        private string $seaId,
        private array $gates,
        private ?string $tradePortId = null,
        private ?array $tradePort = null,
    ) {
    }

    /**
     * @param list<string>           $islandIds   wyspy w kolejności trasy
     * @param list<Island>           $deposits    definicje wysp: złoża i bezpieczne przystanie — nie zmieniają rozkładu wysp
     * @param SeaTheme               $theme       motyw akwenu; domyślny = Morze Rozbitków
     * @param array<string, SeaEdge> $gates       bramy akwenu (klucz: id bramy) i krawędź, na której leżą
     * @param string|null            $tradePortId port handlowy akwenu (#140) — osobny rzut, nie zmienia rozkładu wysp ani bram
     */
    public static function generate(int $seed, array $islandIds, array $deposits = [], SeaTheme $theme = new SeaTheme(), string $seaId = Sea::START, array $gates = [], ?string $tradePortId = null): self
    {
        $state = $seed & 0x7FFFFFFF;
        $next = static function (int $mod) use (&$state): int {
            $state = (1103515245 * $state + 12345) & 0x7FFFFFFF;

            return $state % max(1, $mod);
        };

        $count = max(1, count($islandIds));
        $bandWidth = max(1, intdiv(self::WIDTH, $count));

        $islands = [];
        foreach ($islandIds as $i => $id) {
            $islands[$id] = [
                'x' => min(self::WIDTH - 1, $i * $bandWidth + $next($bandWidth)),
                'y' => $next(self::HEIGHT),
            ];
        }

        $withDeposit = [];
        $safeHarbors = [];
        foreach ($deposits as $island) {
            if ($island->hasDeposit() && isset($islands[$island->id])) {
                $withDeposit[$island->id] = $island;
            }
            if ($island->safeHarbor) {
                $safeHarbors[$island->id] = true;
            }
        }

        $gateSectors = [];
        foreach ($gates as $gateId => $edge) {
            $gateSectors[$gateId] = self::gateSector($seed, (string) $gateId, $edge, [...array_values($islands), ...array_values($gateSectors)]);
        }

        $tradePort = null !== $tradePortId
            ? self::tradePortSector($seed, $tradePortId, array_values($islands), array_values($gateSectors))
            : null;

        return new self($islands, $withDeposit, $safeHarbors, $theme, $seaId, $gateSectors, $tradePortId, $tradePort);
    }

    /**
     * Sektor portu handlowego (#140): miejsce z seeda akwenu i id portu we
     * wnętrzu mapy (krawędzie zostają bramom), przesunięte dalej, gdy sektor
     * zajmuje brama albo wyspa lub jej sąsiedztwo. Wyspy nie stoją obok
     * siebie, więc ich znaczniki na mapie mogą wychodzić na sąsiednią wodę
     * (#131) — port też ich nie zasłania.
     *
     * @param list<array{x:int,y:int}> $islands
     * @param list<array{x:int,y:int}> $gates
     *
     * @return array{x:int,y:int}
     */
    private static function tradePortSector(int $seed, string $portId, array $islands, array $gates): array
    {
        $width = self::WIDTH - 2;
        $cells = $width * (self::HEIGHT - 2);
        $offset = crc32("{$seed}:{$portId}") % $cells;
        for ($i = 0; $i < $cells; ++$i) {
            $cell = ($offset + $i) % $cells;
            $sector = ['x' => 1 + $cell % $width, 'y' => 1 + intdiv($cell, $width)];
            $nearIsland = array_filter($islands, static fn (array $pos) => max(abs($pos['x'] - $sector['x']), abs($pos['y'] - $sector['y'])) <= 1);
            if ([] === $nearIsland && !in_array($sector, $gates, true)) {
                return $sector;
            }
        }

        throw new \DomainException('No free sector for trade port');
    }

    /**
     * Sektor bramy na krawędzi mapy: miejsce na krawędzi z seeda akwenu i id
     * bramy (osobny rzut, więc rozkład wysp się nie zmienia), przesunięte
     * dalej wzdłuż krawędzi, gdy sektor jest zajęty przez wyspę albo inną bramę.
     *
     * @param list<array{x:int,y:int}> $taken
     *
     * @return array{x:int,y:int}
     */
    private static function gateSector(int $seed, string $gateId, SeaEdge $edge, array $taken): array
    {
        $length = match ($edge) {
            SeaEdge::East, SeaEdge::West => self::HEIGHT,
            SeaEdge::North, SeaEdge::South => self::WIDTH,
        };
        $offset = crc32("{$seed}:{$gateId}") % $length;
        for ($i = 0; $i < $length; ++$i) {
            $along = ($offset + $i) % $length;
            $sector = match ($edge) {
                SeaEdge::East => ['x' => self::WIDTH - 1, 'y' => $along],
                SeaEdge::West => ['x' => 0, 'y' => $along],
                SeaEdge::North => ['x' => $along, 'y' => 0],
                SeaEdge::South => ['x' => $along, 'y' => self::HEIGHT - 1],
            };
            if (!in_array($sector, $taken, true)) {
                return $sector;
            }
        }

        throw new \DomainException('No free sector for sea gate');
    }

    /**
     * Ta sama mapa ze znajomością pozostałych akwenów świata (#109). Mapa
     * własnego akwenu na liście jest pomijana, a dołączone mapy nie niosą
     * dalej swoich sąsiadów.
     */
    public function withOtherSeas(WorldMap ...$maps): self
    {
        $copy = clone $this;
        $copy->otherSeas = [];
        foreach ($maps as $map) {
            if ($map->seaId === $this->seaId) {
                continue;
            }
            $other = clone $map;
            $other->otherSeas = [];
            $copy->otherSeas[] = $other;
        }

        return $copy;
    }

    /** @return list<WorldMap> mapy pozostałych akwenów świata */
    public function otherSeas(): array
    {
        return $this->otherSeas;
    }

    /** Mapa akwenu, na którym leży wyspa: ta albo jedna z pozostałych; null = wyspa spoza znanego świata. */
    public function seaOf(string $islandId): ?self
    {
        foreach ([$this, ...$this->otherSeas] as $map) {
            if (null !== $map->islandPosition($islandId)) {
                return $map;
            }
        }

        return null;
    }

    /** Id akwenu, którego to mapa. */
    public function seaId(): string
    {
        return $this->seaId;
    }

    /** Motyw akwenu: sztorm, widoczność, mnożnik złóż. */
    public function theme(): SeaTheme
    {
        return $this->theme;
    }

    /** Czy wyspa jest bezpieczną przystanią (kontrataki jej nie dotyczą, #78). */
    public function isSafeHarbor(string $islandId): bool
    {
        return isset($this->safeHarbors[$islandId]);
    }

    /** @return array<string, Island> wyspy ze złożami na tej mapie */
    public function deposits(): array
    {
        return $this->deposits;
    }

    public function isInside(int $x, int $y): bool
    {
        return $x >= 0 && $y >= 0 && $x < self::WIDTH && $y < self::HEIGHT;
    }

    /** @return array{x:int,y:int} sektor startu wyprawy (pierwsza wyspa trasy) */
    public function startPosition(): array
    {
        $first = reset($this->islands);
        if (false === $first) {
            throw new \DomainException('World has no islands');
        }

        return $first;
    }

    /** Id wyspy stojącej na sektorze; null = otwarte morze. */
    public function islandAt(int $x, int $y): ?string
    {
        foreach ($this->islands as $id => $pos) {
            if ($pos['x'] === $x && $pos['y'] === $y) {
                return $id;
            }
        }

        return null;
    }

    /** @return array{x:int,y:int}|null */
    public function islandPosition(string $islandId): ?array
    {
        return $this->islands[$islandId] ?? null;
    }

    /** @return array<string, array{x:int,y:int}> */
    public function islands(): array
    {
        return $this->islands;
    }

    /** @return array<string, array{x:int,y:int}> sektory bram akwenu (klucz: id bramy) */
    public function gates(): array
    {
        return $this->gates;
    }

    /** @return array{x:int,y:int}|null sektor bramy; null = brama nie leży na tym akwenie */
    public function gatePosition(string $gateId): ?array
    {
        return $this->gates[$gateId] ?? null;
    }

    /** Id portu handlowego akwenu (#140); null = akwen bez portu. */
    public function tradePortId(): ?string
    {
        return $this->tradePortId;
    }

    /** @return array{x:int,y:int}|null sektor portu handlowego; null = akwen bez portu */
    public function tradePortPosition(): ?array
    {
        return $this->tradePort;
    }

    /** Id bramy na sektorze; null = brak bramy. */
    public function gateAt(int $x, int $y): ?string
    {
        foreach ($this->gates as $id => $pos) {
            if ($pos['x'] === $x && $pos['y'] === $y) {
                return $id;
            }
        }

        return null;
    }
}
