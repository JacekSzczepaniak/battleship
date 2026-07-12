<?php

namespace App\Domain\Expedition;

use App\Domain\Shared\GameId;
use App\Domain\Shared\ProfileId;

/**
 * Profil kapitana: doświadczenie, flota (majątek) i rejestr bitew wypraw.
 * Inwarianty: XP nigdy nie maleje (doświadczenia się nie traci, także po
 * porażce), każda bitwa jest rozliczana dokładnie raz, a flota nigdy nie
 * blokuje gry na zawsze — tratwa jest darmowa (bezpiecznik anty-softlock).
 */
final class CaptainProfile
{
    private const STARTING_MATERIALS = 20;

    /** Szansa sztormu przy wpłynięciu w NIEZBADANY sektor (w %). */
    private const STORM_CHANCE = 20;

    /** Górny limit materiałów zabieranych przez sztorm. */
    private const STORM_MATERIALS_LOSS = 5;

    /** Nagroda kartograficzna: materiały za nowy sektor / bonus za odkrycie wyspy. */
    private const CARTOGRAPHY_SECTOR_REWARD = 1;
    private const CARTOGRAPHY_ISLAND_BONUS = 5;

    /** @var array<string, array{island: string, settled: bool, result: ?string, ships?: list<string>}> klucz: gameId */
    private array $battles = [];

    /** @var list<OwnedShip> */
    private array $fleet = [];

    private int $materials = 0;

    // --- Wolne morze ---

    /** Seed świata (deterministyczna mapa per kapitan). */
    private int $worldSeed = 0;

    /** @var array{x:int,y:int}|null pozycja na morzu; null = jeszcze nie zainicjalizowana (ensureWorldState) */
    private ?array $position = null;

    /** @var array<string,bool> odkryte sektory (klucz "x:y") */
    private array $discovered = [];

    /** Licznik ruchów — wejście do deterministycznych rzutów eventów podróży. */
    private int $moveCount = 0;

    private function __construct(
        private ProfileId $id,
        private string $name,
        private int $xp = 0,
    ) {
    }

    public static function create(string $name): self
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 40) {
            throw new \InvalidArgumentException('Profile name must be 1-40 characters');
        }

        $self = new self(ProfileId::new(), $name);
        $self->materials = self::STARTING_MATERIALS;
        $self->fleet = self::startingFleet();
        $self->worldSeed = crc32((string) $self->id) & 0x7FFFFFFF;

        return $self;
    }

    /**
     * Flota rozbitka: kuter + 3 tratwy — od pierwszej bitwy jest polowanie
     * (plansza samych jednomasztowców to czysta loteria).
     *
     * @return list<OwnedShip>
     */
    private static function startingFleet(): array
    {
        return [
            OwnedShip::build(ShipType::Kuter),
            OwnedShip::build(ShipType::Tratwa),
            OwnedShip::build(ShipType::Tratwa),
            OwnedShip::build(ShipType::Tratwa),
        ];
    }

    public function id(): ProfileId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function xp(): int
    {
        return $this->xp;
    }

    public function rank(): Rank
    {
        return Rank::fromXp($this->xp);
    }

    // --- Flota i materiały ---

    public function materials(): int
    {
        return $this->materials;
    }

    /** @return list<OwnedShip> */
    public function fleet(): array
    {
        return $this->fleet;
    }

    /** @return list<OwnedShip> statki zdolne do bitwy (nieuszkodzone) */
    public function activeFleet(): array
    {
        return array_values(array_filter($this->fleet, static fn (OwnedShip $s) => !$s->isDamaged()));
    }

    public function addMaterials(int $amount): void
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException('Negative materials amount');
        }
        $this->materials += $amount;
    }

    /** Buduje statek: bramka rangi + koszt w materiałach (stocznię sprawdza warstwa aplikacji). */
    public function buildShip(ShipType $type): OwnedShip
    {
        if (!$this->rank()->atLeast($type->requiredRank())) {
            throw new \DomainException(sprintf('Ship type locked: requires rank %s', $type->requiredRank()->value));
        }
        $this->spendMaterials($type->buildCost());

        $ship = OwnedShip::build($type);
        $this->fleet[] = $ship;

        return $ship;
    }

    /** Remontuje uszkodzony statek za materiały. */
    public function repairShip(string $shipId): OwnedShip
    {
        $ship = $this->shipById($shipId);
        if (null === $ship) {
            throw new \DomainException('Ship not found');
        }
        if (!$ship->isDamaged()) {
            throw new \DomainException('Ship is not damaged');
        }

        $this->spendMaterials($ship->type->repairCost());
        $ship->repair();

        return $ship;
    }

    private function shipById(string $shipId): ?OwnedShip
    {
        foreach ($this->fleet as $ship) {
            if ($ship->id === $shipId) {
                return $ship;
            }
        }

        return null;
    }

    private function spendMaterials(int $cost): void
    {
        if ($this->materials < $cost) {
            throw new \DomainException('Not enough materials');
        }
        $this->materials -= $cost;
    }

    // --- Wolne morze: żegluga, mgła świata, kartografia ---

    public function worldSeed(): int
    {
        return $this->worldSeed;
    }

    /** Czy stan morski (pozycja/mgła) został już zainicjalizowany. */
    public function hasWorldState(): bool
    {
        return null !== $this->position;
    }

    /**
     * Inicjalizuje stan morski (start = sektor pierwszej wyspy + sąsiedztwo),
     * jeśli profil jeszcze go nie ma (nowy albo sprzed wolnego morza).
     */
    public function ensureWorldState(WorldMap $world): void
    {
        if (null !== $this->position) {
            return;
        }

        $this->position = $world->startPosition();
        foreach ($this->neighborhood($this->position['x'], $this->position['y'], $world) as [$x, $y]) {
            $this->discovered["$x:$y"] = true;
        }
    }

    /** @return array{x:int,y:int} */
    public function position(): array
    {
        if (null === $this->position) {
            throw new \DomainException('World state not initialized');
        }

        return $this->position;
    }

    public function isDiscovered(int $x, int $y): bool
    {
        return isset($this->discovered["$x:$y"]);
    }

    /** @return list<string> odkryte sektory jako "x:y" */
    public function discoveredSectors(): array
    {
        return array_keys($this->discovered);
    }

    public function moveCount(): int
    {
        return $this->moveCount;
    }

    /** Czy kapitan stoi na sektorze danej wyspy. */
    public function isAt(WorldMap $world, string $islandId): bool
    {
        $this->ensureWorldState($world);
        $pos = $world->islandPosition($islandId);

        return null !== $pos && $pos === $this->position;
    }

    /**
     * Żegluga o jeden sektor. Wpłynięcie w niezbadane wody grozi sztormem
     * (deterministycznie z seed+moveCount — przeładowanie strony nie zmienia
     * losu); nowe sektory dają dochód kartograficzny. Żegluga NIGDY nie
     * wymaga sprawnej floty — uszkodzone statki dopłyną (bezpiecznik:
     * z morza zawsze wrócisz do stoczni).
     *
     * @return array{discoveredNow: list<string>, cartography: int, event: array<string,mixed>|null}
     */
    public function sail(int $x, int $y, WorldMap $world): array
    {
        $this->ensureWorldState($world);

        if (!$world->isInside($x, $y)) {
            throw new \DomainException('Sector out of bounds');
        }
        $dx = abs($x - $this->position['x']);
        $dy = abs($y - $this->position['y']);
        if (1 !== max($dx, $dy)) {
            throw new \DomainException('Can only sail to an adjacent sector');
        }

        ++$this->moveCount;

        // sztorm łapie w drodze — tylko na niezbadanych wodach
        $event = $this->isDiscovered($x, $y) ? null : $this->rollStorm();

        $this->position = ['x' => $x, 'y' => $y];

        $discoveredNow = [];
        $cartography = 0;
        foreach ($this->neighborhood($x, $y, $world) as [$nx, $ny]) {
            if ($this->isDiscovered($nx, $ny)) {
                continue;
            }
            $this->discovered["$nx:$ny"] = true;
            $discoveredNow[] = "$nx:$ny";
            $cartography += self::CARTOGRAPHY_SECTOR_REWARD;
            if (null !== $world->islandAt($nx, $ny)) {
                $cartography += self::CARTOGRAPHY_ISLAND_BONUS;
            }
        }
        $this->materials += $cartography;

        return ['discoveredNow' => $discoveredNow, 'cartography' => $cartography, 'event' => $event];
    }

    /** @return array<string,mixed>|null zdarzenie sztormu (deterministyczne) albo spokojna żegluga */
    private function rollStorm(): ?array
    {
        if (crc32("{$this->worldSeed}:{$this->moveCount}") % 100 >= self::STORM_CHANCE) {
            return null;
        }

        $effectRoll = crc32("{$this->worldSeed}:{$this->moveCount}:effect");
        $active = $this->activeFleet();

        if (1 === $effectRoll % 2 && [] !== $active) {
            $ship = $active[$effectRoll % count($active)];
            $ship->markDamaged();

            return ['type' => 'storm', 'effect' => 'ship-damaged', 'ship' => $ship->type->value];
        }

        $loss = min(self::STORM_MATERIALS_LOSS, $this->materials);
        $this->materials -= $loss;

        return ['type' => 'storm', 'effect' => 'materials-lost', 'materials' => $loss];
    }

    /** @return list<array{0:int,1:int}> sektor + sąsiedztwo (promień 1) w granicach mapy */
    private function neighborhood(int $x, int $y, WorldMap $world): array
    {
        $out = [];
        for ($dx = -1; $dx <= 1; ++$dx) {
            for ($dy = -1; $dy <= 1; ++$dy) {
                if ($world->isInside($x + $dx, $y + $dy)) {
                    $out[] = [$x + $dx, $y + $dy];
                }
            }
        }

        return $out;
    }

    // --- Bitwy ---

    /**
     * Rejestruje rozpoczętą bitwę o wyspę (jedna gra = jedna bitwa).
     *
     * @param list<string> $shipIds statki, które wypłynęły (do rozliczenia strat)
     */
    public function startBattle(string $islandId, GameId $gameId, array $shipIds = []): void
    {
        $key = (string) $gameId;
        if (isset($this->battles[$key])) {
            throw new \DomainException('Battle already registered');
        }

        $this->battles[$key] = ['island' => $islandId, 'settled' => false, 'result' => null, 'ships' => $shipIds];
    }

    /** Wyspa, o którą toczy się dana gra; null gdy gra nie należy do profilu. */
    public function islandFor(GameId $gameId): ?string
    {
        return $this->battles[(string) $gameId]['island'] ?? null;
    }

    /**
     * Rozlicza bitwę: XP i materiały nigdy nie giną (przyznawane też za porażkę),
     * straty dotykają floty — zatopione statki po wygranej wymagają remontu,
     * po przegranej są stracone. Idempotentne: druga próba zwraca zera.
     *
     * @param list<int> $sunkLengths długości statków gracza zatopionych w bitwie
     *
     * @return array{xp:int, materials:int, lost:list<string>, damaged:list<string>}
     */
    public function settleBattle(GameId $gameId, string $result, int $xpAward, int $materialsAward = 0, array $sunkLengths = []): array
    {
        if (!in_array($result, ['won', 'lost'], true)) {
            throw new \InvalidArgumentException('Result must be won|lost');
        }
        if ($xpAward < 0 || $materialsAward < 0) {
            throw new \InvalidArgumentException('Negative battle award');
        }

        $key = (string) $gameId;
        $battle = $this->battles[$key] ?? throw new \DomainException('Battle not registered for this profile');
        if ($battle['settled']) {
            return ['xp' => 0, 'materials' => 0, 'lost' => [], 'damaged' => []];
        }

        $battle['settled'] = true;
        $battle['result'] = $result;
        $this->battles[$key] = $battle;

        $this->xp += $xpAward;
        $this->materials += $materialsAward;

        [$lost, $damaged] = $this->applyLosses($battle['ships'] ?? [], $result, $sunkLengths);

        return ['xp' => $xpAward, 'materials' => $materialsAward, 'lost' => $lost, 'damaged' => $damaged];
    }

    /**
     * Dobiera zatopione statki bitwy do egzemplarzy floty po długości
     * (bitwa zna tylko długości) i aplikuje straty.
     *
     * @param list<string> $battleShipIds
     * @param list<int>    $sunkLengths
     *
     * @return array{0: list<string>, 1: list<string>} [stracone typy, uszkodzone typy]
     */
    private function applyLosses(array $battleShipIds, string $result, array $sunkLengths): array
    {
        $lost = [];
        $damaged = [];
        $processed = [];

        foreach ($sunkLengths as $length) {
            foreach ($battleShipIds as $shipId) {
                if (isset($processed[$shipId])) {
                    continue;
                }
                $ship = $this->shipById($shipId);
                if (null === $ship || $ship->type->length() !== $length || $ship->isDamaged()) {
                    continue;
                }

                $processed[$shipId] = true;
                if ('lost' === $result) {
                    $this->fleet = array_values(array_filter($this->fleet, static fn (OwnedShip $s) => $s->id !== $shipId));
                    $lost[] = $ship->type->value;
                } else {
                    $ship->markDamaged();
                    $damaged[] = $ship->type->value;
                }
                break;
            }
        }

        return [$lost, $damaged];
    }

    /** @return array{wins:int, losses:int} rozliczone bitwy o daną wyspę */
    public function battleStats(string $islandId): array
    {
        $wins = 0;
        $losses = 0;
        foreach ($this->battles as $battle) {
            if ($battle['island'] !== $islandId || !$battle['settled']) {
                continue;
            }
            'won' === $battle['result'] ? ++$wins : ++$losses;
        }

        return ['wins' => $wins, 'losses' => $losses];
    }

    // --- Snapshot (mapper persystencji) ---

    /** @return array<string, array{island: string, settled: bool, result: ?string, ships?: list<string>}> */
    public function battles(): array
    {
        return $this->battles;
    }

    /**
     * @param array<string, array{island: string, settled: bool, result: ?string, ships?: list<string>}> $battles
     * @param list<OwnedShip>|null                                                                       $fleet      null = profil sprzed ekonomii — dostaje flotę startową
     * @param int|null                                                                                   $materials  null = materiały startowe
     * @param int|null                                                                                   $worldSeed  null = seed wyprowadzony z id (stabilny dla starych profili)
     * @param array{x:int,y:int}|null                                                                    $position   null = start ustali ensureWorldState
     * @param list<string>|null                                                                          $discovered sektory "x:y"
     */
    public static function fromSnapshot(
        ProfileId $id,
        string $name,
        int $xp,
        array $battles,
        ?array $fleet = null,
        ?int $materials = null,
        ?int $worldSeed = null,
        ?array $position = null,
        ?array $discovered = null,
        int $moveCount = 0,
    ): self {
        $self = new self($id, $name, max(0, $xp));
        $self->battles = $battles;
        $self->fleet = $fleet ?? self::startingFleet();
        $self->materials = $materials ?? self::STARTING_MATERIALS;
        $self->worldSeed = $worldSeed ?? (crc32((string) $id) & 0x7FFFFFFF);
        $self->position = $position;
        $self->discovered = null !== $discovered ? array_fill_keys($discovered, true) : [];
        $self->moveCount = max(0, $moveCount);

        return $self;
    }
}
