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
    /**
     * Surowce na start: drewno na kuter (stal i złoto przychodzą z bitew).
     * Leży w magazynie wyspy startowej — ładownia floty startowej go nie uniesie.
     */
    private const STARTING_WOOD = 20;

    /** Szansa sztormu przy wpłynięciu w NIEZBADANY sektor (w %). */
    private const STORM_CHANCE = 20;

    /**
     * Fale zabierają procent ładunku, ale nie mniej niż minimum drewna (o ile
     * jest w ładowni). Konwój bez eskorty traci dwukrotność obu wartości.
     */
    private const STORM_CARGO_LOSS_PERCENT = 10;
    private const STORM_MIN_WOOD_LOSS = 5;

    /** Nagroda kartograficzna w drewnie: za nowy sektor / bonus za odkrycie wyspy. */
    private const CARTOGRAPHY_SECTOR_REWARD = 1;
    private const CARTOGRAPHY_ISLAND_BONUS = 5;

    /** @var array<string, array{island: string, settled: bool, result: ?string, ships?: list<string>, enemySunk?: int}> klucz: gameId */
    private array $battles = [];

    /** @var list<OwnedShip> */
    private array $fleet = [];

    /**
     * Surowce istnieją fizycznie (GAME_DESIGN.md, filar IV): w magazynach wysp
     * i w ładowni floty — bez globalnego portfela.
     *
     * @var array<string, Resources> klucz: id wyspy
     */
    private array $stockpiles = [];

    private Resources $cargo;

    /**
     * Surowce czekające na magazyn wyspy (drewno startowe, profil sprzed
     * ładowni, ładunek ponad pojemność po zmianie zasad) — rozkłada je
     * ensureWorldState(), które zna mapę.
     */
    private ?Resources $unplacedResources = null;

    // --- Wolne morze ---

    /** Seed świata (deterministyczna mapa per kapitan). */
    private int $worldSeed = 0;

    /** @var array{x:int,y:int}|null pozycja na morzu; null = jeszcze nie zainicjalizowana (ensureWorldState) */
    private ?array $position = null;

    /** @var array<string,bool> odkryte sektory (klucz "x:y") */
    private array $discovered = [];

    /**
     * @var array<string,bool> sektory, na których kapitan był (klucz "x:y"). Odkrycie
     *                         sięga sąsiedztwa, odwiedziny — tylko pozycji; sztorm
     *                         grozi przy pierwszym wpłynięciu na sektor
     */
    private array $visited = [];

    /** Licznik ruchów — wejście do deterministycznych rzutów eventów podróży. */
    private int $moveCount = 0;

    /** Tik = akcja morska (rejs, bitwa); zegar świata — GAME_DESIGN.md, filar II. */
    private int $tick = 0;

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
        $self->cargo = Resources::none();
        $self->unplacedResources = new Resources(wood: self::STARTING_WOOD);
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

    // --- Flota i surowce ---

    public function cargo(): Resources
    {
        return $this->cargo;
    }

    /** Pojemność ładowni = suma pojemności sprawnych statków. */
    public function cargoCapacity(): int
    {
        return array_sum(array_map(static fn (OwnedShip $s) => $s->type->cargoCapacity(), $this->activeFleet()));
    }

    public function stockpile(string $islandId): Resources
    {
        return $this->stockpiles[$islandId] ?? Resources::none();
    }

    /** @return array<string, Resources> niepuste magazyny wysp */
    public function stockpiles(): array
    {
        return array_filter($this->stockpiles, static fn (Resources $r) => !$r->isZero());
    }

    /**
     * Załadunek z magazynu wyspy do ładowni (kapitan stoi na wyspie — sprawdza
     * warstwa aplikacji). Dokowanie kosztuje tik.
     */
    public function loadCargo(string $islandId, Resources $amount): void
    {
        if ($amount->isZero()) {
            throw new \InvalidArgumentException('Nothing to transfer');
        }
        $stock = $this->stockpile($islandId)->subtract($amount);
        if ($this->cargo->total() + $amount->total() > $this->cargoCapacity()) {
            throw new \DomainException('Cargo capacity exceeded');
        }

        $this->stockpiles[$islandId] = $stock;
        $this->cargo = $this->cargo->add($amount);
        ++$this->tick;
    }

    /** Rozładunek z ładowni do magazynu wyspy. Dokowanie kosztuje tik. */
    public function unloadCargo(string $islandId, Resources $amount): void
    {
        if ($amount->isZero()) {
            throw new \InvalidArgumentException('Nothing to transfer');
        }

        $this->cargo = $this->cargo->subtract($amount);
        $this->stockpiles[$islandId] = $this->stockpile($islandId)->add($amount);
        ++$this->tick;
    }

    /**
     * Płatność w porcie: najpierw magazyn wyspy, brakującą resztę bierze
     * z ładowni zadokowanej floty.
     */
    private function pay(string $islandId, Resources $cost): void
    {
        $stock = $this->stockpile($islandId);
        if (!$stock->add($this->cargo)->covers($cost)) {
            throw new \DomainException('Not enough resources');
        }

        $fromStock = new Resources(
            min($stock->wood, $cost->wood),
            min($stock->steel, $cost->steel),
            min($stock->gold, $cost->gold),
        );
        $this->stockpiles[$islandId] = $stock->subtract($fromStock);
        $this->cargo = $this->cargo->subtract($cost->subtract($fromStock));
    }

    /** Ładunek ponad pojemność (np. po utracie statku) przepada; zwraca, co przepadło. */
    private function trimCargo(): Resources
    {
        [$kept, $overflow] = $this->cargo->fitInto($this->cargoCapacity());
        $this->cargo = $kept;

        return $overflow;
    }

    /** @return list<OwnedShip> */
    public function fleet(): array
    {
        return $this->fleet;
    }

    /**
     * Skład bitewny w limicie pól planszy: wskazane statki albo — gdy nie
     * wskazano — domyślny dobór (nośniki broni od największego, potem tratwy).
     * Statki spoza składu czekają w porcie. Konwój bez eskorty nie atakuje.
     *
     * @param list<string>|null $shipIds
     *
     * @return list<OwnedShip>
     */
    public function battleSquad(?array $shipIds, int $cellLimit): array
    {
        if (null === $shipIds) {
            // sprawne są tylko transportowce — konwój bez eskorty
            return $this->defaultSquad($cellLimit) ?: throw new \DomainException('Convoy needs an escort');
        }
        if ([] === $shipIds || count($shipIds) !== count(array_unique($shipIds))) {
            throw new \InvalidArgumentException('Squad must list distinct ships');
        }

        $squad = [];
        foreach ($shipIds as $shipId) {
            $ship = $this->shipById($shipId) ?? throw new \DomainException('Ship not found');
            if ($ship->isDamaged()) {
                throw new \DomainException('Ship not seaworthy');
            }
            $squad[] = $ship;
        }
        if (self::squadCells($squad) > $cellLimit) {
            throw new \DomainException('Squad exceeds island limit');
        }
        if (self::isUnescortedConvoy($squad)) {
            throw new \DomainException('Convoy needs an escort');
        }

        return $squad;
    }

    /** @return list<OwnedShip> */
    private function defaultSquad(int $cellLimit): array
    {
        // kolejność doboru: nośniki broni od największego, potem tratwy;
        // transportowiec płynie do bitwy tylko wskazany wprost
        $priority = [ShipType::Lotniskowiec, ShipType::Niszczyciel, ShipType::Kuter, ShipType::Tratwa];
        $candidates = array_values(array_filter($this->activeFleet(), static fn (OwnedShip $s) => in_array($s->type, $priority, true)));
        usort($candidates, static fn (OwnedShip $a, OwnedShip $b) => array_search($a->type, $priority, true) <=> array_search($b->type, $priority, true));

        $squad = [];
        $cells = 0;
        foreach ($candidates as $ship) {
            if ($cells + $ship->type->length() <= $cellLimit) {
                $squad[] = $ship;
                $cells += $ship->type->length();
            }
        }

        return $squad;
    }

    /** @param list<OwnedShip> $ships */
    public static function squadCells(array $ships): int
    {
        return array_sum(array_map(static fn (OwnedShip $s) => $s->type->length(), $ships));
    }

    /**
     * Konwój = statki z co najmniej jednym transportowcem; eskortuje go statek
     * z bronią. Reguła nie dotyczy floty bez transportowca, więc same tratwy
     * nadal walczą (bezpiecznik anty-softlock).
     *
     * @param list<OwnedShip> $ships sprawne statki
     */
    public static function isUnescortedConvoy(array $ships): bool
    {
        $hasTransport = false;
        foreach ($ships as $ship) {
            if ($ship->type->isArmed()) {
                return false;
            }
            $hasTransport = $hasTransport || ShipType::Transportowiec === $ship->type;
        }

        return $hasTransport;
    }

    /** @return list<OwnedShip> statki zdolne do bitwy (nieuszkodzone) */
    public function activeFleet(): array
    {
        return array_values(array_filter($this->fleet, static fn (OwnedShip $s) => !$s->isDamaged()));
    }

    /**
     * Buduje statek: bramka rangi + koszt z magazynu wyspy i ładowni
     * (obecność i stocznię sprawdza warstwa aplikacji).
     */
    public function buildShip(ShipType $type, string $islandId): OwnedShip
    {
        if (!$this->rank()->atLeast($type->requiredRank())) {
            throw new \DomainException(sprintf('Ship type locked: requires rank %s', $type->requiredRank()->value));
        }
        $this->pay($islandId, $type->buildCost());

        $ship = OwnedShip::build($type);
        $this->fleet[] = $ship;

        return $ship;
    }

    /** Remontuje uszkodzony statek za surowce z magazynu wyspy i ładowni. */
    public function repairShip(string $shipId, string $islandId): OwnedShip
    {
        $ship = $this->shipById($shipId);
        if (null === $ship) {
            throw new \DomainException('Ship not found');
        }
        if (!$ship->isDamaged()) {
            throw new \DomainException('Ship is not damaged');
        }

        $this->pay($islandId, $ship->type->repairCost());
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
        if (null === $this->position) {
            $this->position = $world->startPosition();
            $this->visited["{$this->position['x']}:{$this->position['y']}"] = true;
            foreach ($this->neighborhood($this->position['x'], $this->position['y'], $world) as [$x, $y]) {
                $this->discovered["$x:$y"] = true;
            }
        }

        $this->placeUnplacedResources($world);
    }

    /**
     * Surowce bez miejsca (patrz $unplacedResources) trafiają do magazynu
     * wyspy, na której stoi kapitan; na morzu do ładowni, a nadwyżka do
     * magazynu wyspy startowej.
     */
    private function placeUnplacedResources(WorldMap $world): void
    {
        if (null === $this->unplacedResources) {
            return;
        }
        $legacy = $this->unplacedResources;
        $this->unplacedResources = null;

        $here = $world->islandAt($this->position['x'], $this->position['y']);
        if (null !== $here) {
            $this->stockpiles[$here] = $this->stockpile($here)->add($legacy);

            return;
        }

        [$fit, $rest] = $legacy->fitInto($this->cargoCapacity() - $this->cargo->total());
        $this->cargo = $this->cargo->add($fit);
        if (!$rest->isZero()) {
            $start = $world->startPosition();
            $startIsland = $world->islandAt($start['x'], $start['y']) ?? throw new \LogicException('Start sector has no island');
            $this->stockpiles[$startIsland] = $this->stockpile($startIsland)->add($rest);
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

    public function isVisited(int $x, int $y): bool
    {
        return isset($this->visited["$x:$y"]);
    }

    /** @return list<string> odwiedzone sektory jako "x:y" */
    public function visitedSectors(): array
    {
        return array_keys($this->visited);
    }

    public function moveCount(): int
    {
        return $this->moveCount;
    }

    public function tick(): int
    {
        return $this->tick;
    }

    /** Czy kapitan stoi na sektorze danej wyspy. */
    public function isAt(WorldMap $world, string $islandId): bool
    {
        $this->ensureWorldState($world);
        $pos = $world->islandPosition($islandId);

        return null !== $pos && $pos === $this->position;
    }

    /**
     * Żegluga o jeden sektor. Pierwsze wpłynięcie na sektor grozi sztormem
     * (deterministycznie z seed+tik — przeładowanie strony nie zmienia
     * losu); powrót na odwiedzony sektor jest bezpieczny. Nowo odkryte
     * sektory dają dochód kartograficzny. Żegluga NIGDY nie
     * wymaga sprawnej floty — uszkodzone statki dopłyną (bezpiecznik:
     * z morza zawsze wrócisz do stoczni).
     *
     * Dochód kartograficzny trafia do ładowni; nadmiar ponad pojemność przepada.
     *
     * @return array{discoveredNow: list<string>, cartography: int, cartographyLost: int, event: array<string,mixed>|null}
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
        ++$this->tick;

        // sztorm łapie w drodze — tylko przy pierwszym wpłynięciu na sektor.
        // Nie „nieodkryty”: cel rejsu jest zawsze odkryty, bo przybycie odkrywa
        // całe sąsiedztwo (#68)
        $event = $this->isVisited($x, $y) ? null : $this->rollStorm();

        $this->position = ['x' => $x, 'y' => $y];
        $this->visited["$x:$y"] = true;

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
        [$fit, $lost] = (new Resources(wood: $cartography))->fitInto($this->cargoCapacity() - $this->cargo->total());
        $this->cargo = $this->cargo->add($fit);

        return ['discoveredNow' => $discoveredNow, 'cartography' => $cartography, 'cartographyLost' => $lost->wood, 'event' => $event];
    }

    /** @return array<string,mixed>|null zdarzenie sztormu (deterministyczne) albo spokojna żegluga */
    private function rollStorm(): ?array
    {
        if (crc32("{$this->worldSeed}:{$this->tick}") % 100 >= self::STORM_CHANCE) {
            return null;
        }

        $effectRoll = crc32("{$this->worldSeed}:{$this->tick}:effect");
        $active = $this->activeFleet();

        if (1 === $effectRoll % 2 && [] !== $active) {
            $ship = $active[$effectRoll % count($active)];
            $ship->markDamaged();
            // uszkodzony statek nie wiezie ładunku — nadmiar idzie za burtę
            $overboard = $this->trimCargo();

            return ['type' => 'storm', 'effect' => 'ship-damaged', 'ship' => $ship->type->value, 'cargoLost' => $overboard->toArray()];
        }

        $unescorted = self::isUnescortedConvoy($active);
        $multiplier = $unescorted ? 2 : 1;
        $units = max(
            intdiv($this->cargo->total() * self::STORM_CARGO_LOSS_PERCENT * $multiplier, 100),
            min(self::STORM_MIN_WOOD_LOSS * $multiplier, $this->cargo->wood),
        );
        $lost = self::takeCheapestFirst($this->cargo, $units);
        $this->cargo = $this->cargo->subtract($lost);

        return ['type' => 'storm', 'effect' => 'cargo-lost', 'cargoLost' => $lost->toArray(), 'unescorted' => $unescorted];
    }

    /** Fale zabierają najpierw drewno, potem stal, na końcu złoto. */
    private static function takeCheapestFirst(Resources $from, int $units): Resources
    {
        $units = min($units, $from->total());
        $wood = min($from->wood, $units);
        $steel = min($from->steel, $units - $wood);

        return new Resources($wood, $steel, $units - $wood - $steel);
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
     * Rejestruje rozpoczętą bitwę o wyspę (jedna gra = jedna bitwa). Bitwa
     * kosztuje tik od razu przy starcie — porzucona też zajęła czas.
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
        ++$this->tick;
    }

    /** Wyspa, o którą toczy się dana gra; null gdy gra nie należy do profilu. */
    public function islandFor(GameId $gameId): ?string
    {
        return $this->battles[(string) $gameId]['island'] ?? null;
    }

    /**
     * Rozlicza bitwę: XP i surowce nigdy nie giną (przyznawane też za porażkę),
     * łup trafia do magazynu wyspy, o którą była bitwa. Straty dotykają floty —
     * zatopione statki po wygranej wymagają remontu, po przegranej są stracone;
     * ładunek ponad nową pojemność przepada. Idempotentne: druga próba zwraca zera.
     *
     * @param list<int> $sunkLengths    długości statków gracza zatopionych w bitwie
     * @param int       $enemyShipsSunk liczba jednostek wroga zatopionych w bitwie (statystyka kapitana)
     *
     * @return array{xp:int, loot:Resources, lost:list<string>, damaged:list<string>, cargoLost:Resources}
     */
    public function settleBattle(GameId $gameId, string $result, int $xpAward, ?Resources $loot = null, array $sunkLengths = [], int $enemyShipsSunk = 0): array
    {
        $loot ??= Resources::none();
        if (!in_array($result, ['won', 'lost'], true)) {
            throw new \InvalidArgumentException('Result must be won|lost');
        }
        if ($xpAward < 0) {
            throw new \InvalidArgumentException('Negative battle award');
        }
        if ($enemyShipsSunk < 0) {
            throw new \InvalidArgumentException('Negative sunk ship count');
        }

        $key = (string) $gameId;
        $battle = $this->battles[$key] ?? throw new \DomainException('Battle not registered for this profile');
        if ($battle['settled']) {
            return ['xp' => 0, 'loot' => Resources::none(), 'lost' => [], 'damaged' => [], 'cargoLost' => Resources::none()];
        }

        $battle['settled'] = true;
        $battle['result'] = $result;
        $battle['enemySunk'] = $enemyShipsSunk;
        $this->battles[$key] = $battle;

        $this->xp += $xpAward;
        $this->stockpiles[$battle['island']] = $this->stockpile($battle['island'])->add($loot);

        [$lost, $damaged] = $this->applyLosses($battle['ships'] ?? [], $result, $sunkLengths);
        $cargoLost = $this->trimCargo();

        return ['xp' => $xpAward, 'loot' => $loot, 'lost' => $lost, 'damaged' => $damaged, 'cargoLost' => $cargoLost];
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

    /** Jednostki wroga zatopione we wszystkich rozliczonych bitwach (rekordy sprzed licznika liczą się jako 0). */
    public function enemyShipsSunk(): int
    {
        $sunk = 0;
        foreach ($this->battles as $battle) {
            if ($battle['settled']) {
                $sunk += $battle['enemySunk'] ?? 0;
            }
        }

        return $sunk;
    }

    // --- Snapshot (mapper persystencji) ---

    /** Surowce, których ensureWorldState() jeszcze nie rozłożyło; null = brak. */
    public function unplacedResources(): ?Resources
    {
        return $this->unplacedResources;
    }

    /** @return array<string, array{island: string, settled: bool, result: ?string, ships?: list<string>, enemySunk?: int}> */
    public function battles(): array
    {
        return $this->battles;
    }

    /**
     * @param array<string, array{island: string, settled: bool, result: ?string, ships?: list<string>, enemySunk?: int}> $battles
     * @param list<OwnedShip>|null                                                                                        $fleet      null = profil sprzed ekonomii — dostaje flotę startową
     * @param Resources|null                                                                                              $cargo      null = profil sprzed ładowni (patrz $unplaced) albo sprzed ekonomii — drewno startowe do magazynu wyspy
     * @param int|null                                                                                                    $worldSeed  null = seed wyprowadzony z id (stabilny dla starych profili)
     * @param array{x:int,y:int}|null                                                                                     $position   null = start ustali ensureWorldState
     * @param list<string>|null                                                                                           $discovered sektory "x:y"
     * @param int|null                                                                                                    $tick       null = profil sprzed tiku: rejsy + zarejestrowane bitwy
     * @param list<string>|null                                                                                           $visited    null = profil sprzed odwiedzin: tylko obecna pozycja
     * @param array<string, Resources>                                                                                    $stockpiles magazyny wysp
     * @param Resources|null                                                                                              $unplaced   surowce czekające na magazyn wyspy (też jeden magazyn profilu sprzed ładowni) — rozłożone przy ensureWorldState()
     */
    public static function fromSnapshot(
        ProfileId $id,
        string $name,
        int $xp,
        array $battles,
        ?array $fleet = null,
        ?Resources $cargo = null,
        ?int $worldSeed = null,
        ?array $position = null,
        ?array $discovered = null,
        int $moveCount = 0,
        ?int $tick = null,
        ?array $visited = null,
        array $stockpiles = [],
        ?Resources $unplaced = null,
    ): self {
        $self = new self($id, $name, max(0, $xp));
        $self->battles = $battles;
        $self->fleet = $fleet ?? self::startingFleet();
        $self->stockpiles = $stockpiles;
        $self->unplacedResources = $unplaced ?? (null === $cargo ? new Resources(wood: self::STARTING_WOOD) : null);
        // ładunek ponad pojemność (np. zapisany, zanim tratwa straciła ładownię)
        // nie przepada — czeka na magazyn wyspy
        [$self->cargo, $overflow] = ($cargo ?? Resources::none())->fitInto($self->cargoCapacity());
        if (!$overflow->isZero()) {
            $self->unplacedResources = ($self->unplacedResources ?? Resources::none())->add($overflow);
        }
        $self->worldSeed = $worldSeed ?? (crc32((string) $id) & 0x7FFFFFFF);
        $self->position = $position;
        $self->discovered = null !== $discovered ? array_fill_keys($discovered, true) : [];
        $self->moveCount = max(0, $moveCount);
        // profil sprzed tiku: rejsy + zarejestrowane bitwy, żeby zegar nie cofnął się względem historii
        $self->tick = max(0, $tick ?? $self->moveCount + count($battles));
        // profil sprzed odwiedzin: znamy tylko obecną pozycję, trasy z przeszłości nie
        $self->visited = null !== $visited
            ? array_fill_keys($visited, true)
            : (null !== $position ? ["{$position['x']}:{$position['y']}" => true] : []);

        return $self;
    }
}
