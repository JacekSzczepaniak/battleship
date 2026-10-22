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

    /**
     * Kontrataki (#78): po zdobyciu wyspy albo rozstrzygnięciu kontrataku
     * tyle tików spokoju, potem szansa zapowiedzi na tik (w %); uderzenie
     * następuje tyle tików po zapowiedzi.
     */
    private const COUNTERATTACK_CALM_TICKS = 8;
    private const COUNTERATTACK_CHANCE = 15;
    private const COUNTERATTACK_WARNING_TICKS = 12;

    /** @var array<string, array{island: string, settled: bool, result: ?string, kind?: string, ships?: list<string>, enemySunk?: int}> klucz: gameId */
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
     * Wyspy pod banderą kapitana: pierwsza wygrana bitwa o wyspę ją zdobywa
     * (GAME_DESIGN.md, filar III). Tik zdobycia odmierza spokój przed
     * kontratakiem (#78).
     *
     * @var array<string, int> klucz: id wyspy, wartość: tik zdobycia
     */
    private array $ownedIslands = [];

    /**
     * Tik, do którego naliczono urobek złoża wyspy; ustawiany przy zdobyciu
     * wyspy — złoże pracuje, dopóki wyspa jest pod banderą.
     *
     * @var array<string, int> klucz: id wyspy
     */
    private array $depositTicks = [];

    /**
     * Budynki osady (Epic F): stoją także po utracie wyspy, ale działają tylko
     * pod banderą.
     *
     * @var array<string, list<BuildingType>> klucz: id wyspy
     */
    private array $buildings = [];

    /**
     * Kontratak w toku (najwyżej jeden): wyspa celu, tik zapowiedzi, tik
     * uderzenia i gra obrony, gdy kapitan ją przyjął — rozpoczęta obrona
     * wstrzymuje termin do rozliczenia bitwy.
     *
     * @var array{island: string, announcedAt: int, strikeAt: int, defense: ?string}|null
     */
    private ?array $counterattack = null;

    /** Tik ostatniego zdobycia albo rozstrzygnięcia kontrataku — od niego liczy się spokój. */
    private int $counterattackCalmFrom = 0;

    /** Tik, do którego rozegrano rzuty kontrataków (leniwie, jak urobek złóż). */
    private int $counterattackRolledTo = 0;

    /**
     * Ostatnie rozstrzygnięcie kontrataku — do komunikatu w UI, zwłaszcza
     * o utracie wyspy albo grabieży pod fortem (#81) pod nieobecność kapitana.
     *
     * @var array{island: string, tick: int, held: bool, absent: bool, plundered: bool, stockpileLost: array{wood:int, steel:int, gold:int}}|null
     */
    private ?array $lastCounterattack = null;

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

    // --- Osada: budynki na posiadanych wyspach ---

    /** @return list<BuildingType> budynki stojące na wyspie (także nieczynne, bez bandery) */
    public function buildingsOn(string $islandId): array
    {
        return $this->buildings[$islandId] ?? [];
    }

    /** @return array<string, list<BuildingType>> budynki wszystkich wysp (klucz: id wyspy) */
    public function buildings(): array
    {
        return $this->buildings;
    }

    /** Budynek stoi i działa — wyspa jest pod banderą kapitana. */
    public function hasActiveBuilding(string $islandId, BuildingType $type): bool
    {
        return $this->ownsIsland($islandId) && in_array($type, $this->buildingsOn($islandId), true);
    }

    /**
     * Dlaczego budynku nie da się teraz postawić; null = można budować.
     * Kolejność jak w budowie: bandera, jeden budynek typu, warunek miejsca,
     * obecność kapitana, koszt (magazyn wyspy i ładownia).
     */
    public function buildingBlocker(BuildingType $type, Island $island, WorldMap $world): ?BuildingBlock
    {
        return match (true) {
            !$this->ownsIsland($island->id) => BuildingBlock::NotOwned,
            in_array($type, $this->buildingsOn($island->id), true) => BuildingBlock::AlreadyBuilt,
            !$type->fitsSite($island) => BuildingBlock::Site,
            !$this->isAt($world, $island->id) => BuildingBlock::NotPresent,
            !$this->stockpile($island->id)->add($this->cargo)->covers($type->buildCost()) => BuildingBlock::Resources,
            default => null,
        };
    }

    /**
     * Stawia budynek na posiadanej wyspie, na której stoi kapitan. Budowa jest
     * natychmiastowa i nie kosztuje tiku, płatność jak przy budowie statku.
     */
    public function buildBuilding(BuildingType $type, Island $island, WorldMap $world): void
    {
        // urobek złoża do bieżącego tiku liczy się jeszcze bez nowego budynku
        $this->ensureWorldState($world);

        $blocker = $this->buildingBlocker($type, $island, $world);
        if (null !== $blocker) {
            throw new \DomainException($blocker->message());
        }

        $this->pay($island->id, $type->buildCost());
        $this->buildings[$island->id][] = $type;
    }

    /** Urobek złoża na tik pod banderą, z budynkami stojącymi na wyspie (tartak, kopalnia). */
    public function depositYield(Island $island): Resources
    {
        $yield = $island->depositYield;
        foreach ($this->buildingsOn($island->id) as $building) {
            $yield = $yield->add($building->depositBoost($island->depositYield));
        }

        return $yield;
    }

    /**
     * Efektywny poziom stoczni: poziom z katalogu plus rozbudowa (#82), która
     * działa tylko pod banderą. Ten poziom sprawdzają budowa i remont statku.
     */
    public function shipyardLevel(Island $island): int
    {
        $level = $island->shipyardLevel;
        if ($this->ownsIsland($island->id)) {
            foreach ($this->buildingsOn($island->id) as $building) {
                $level += $building->shipyardBoost();
            }
        }

        return min($level, Island::MAX_SHIPYARD_LEVEL);
    }

    /** Ile urobku może czekać w magazynie wyspy: limit rośnie razem z urobkiem. */
    public function depositCap(Island $island): Resources
    {
        return $this->depositYield($island)->times(Island::DEPOSIT_BUFFER_TICKS);
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
        $this->rollCounterattacks($world);
        $this->accrueDeposits($world);
    }

    /**
     * Rozgrywa kontrataki tik po tiku od ostatniego rzutu (leniwie, jak
     * urobek złóż — deterministycznie z seed+tik, bez zegara). Każda akcja
     * zmieniająca bandery woła wcześniej ensureWorldState(), więc rzut dla
     * tiku widzi stan profilu z tego tiku. Uderzenie bez rozpoczętej obrony
     * odbiera wyspę, a pod fortem ją plądruje (nieobecność nie kosztuje floty).
     */
    private function rollCounterattacks(WorldMap $world): void
    {
        for ($t = $this->counterattackRolledTo + 1; $t <= $this->tick; ++$t) {
            if (null !== $this->counterattack) {
                if (null === $this->counterattack['defense'] && $t >= $this->counterattack['strikeAt']) {
                    $this->resolveCounterattack($this->counterattack['strikeAt'], held: false, absent: true, world: $world);
                }
                continue;
            }
            if ($t - $this->counterattackCalmFrom <= self::COUNTERATTACK_CALM_TICKS) {
                continue;
            }
            $targets = array_values(array_filter(
                array_keys($this->ownedIslands),
                static fn (string $id) => !$world->isSafeHarbor($id),
            ));
            if ([] === $targets || crc32("{$this->worldSeed}:{$t}:counterattack") % 100 >= self::COUNTERATTACK_CHANCE) {
                continue;
            }
            sort($targets);
            $this->counterattack = [
                'island' => $targets[crc32("{$this->worldSeed}:{$t}:counterattack-target") % count($targets)],
                'announcedAt' => $t,
                'strikeAt' => $t + self::COUNTERATTACK_WARNING_TICKS,
                'defense' => null,
            ];
        }
        $this->counterattackRolledTo = max($this->counterattackRolledTo, $this->tick);
    }

    /**
     * Rozstrzyga kontratak w toku: obroniona wyspa zostaje pod banderą,
     * utracona traci banderę, złoże i połowę magazynu. Nieobecność na wyspie
     * z czynnym fortem (#81) kończy się grabieżą: przepada połowa magazynu,
     * a bandera, złoże i budynki zostają. Przegranej obrony fort nie łagodzi.
     * Od tego tiku liczy się spokój przed następną zapowiedzią.
     */
    private function resolveCounterattack(int $tick, bool $held, bool $absent, ?WorldMap $world = null): void
    {
        if (null === $this->counterattack) {
            return;
        }
        $islandId = $this->counterattack['island'];
        $this->counterattack = null;
        $this->counterattackCalmFrom = $tick;

        $plundered = !$held && $absent && $this->hasActiveBuilding($islandId, BuildingType::Fort);
        $lost = Resources::none();
        if (!$held) {
            // urobek złoża należy się do tiku uderzenia — potem złoże staje, chyba że fort utrzymał wyspę
            $island = $world?->deposits()[$islandId] ?? null;
            if (null !== $island) {
                $this->accrueDeposit($islandId, $island, $tick);
            }
            $stock = $this->stockpile($islandId);
            $lost = new Resources(intdiv($stock->wood, 2), intdiv($stock->steel, 2), intdiv($stock->gold, 2));
            $this->stockpiles[$islandId] = $stock->subtract($lost);
            if (!$plundered) {
                unset($this->ownedIslands[$islandId], $this->depositTicks[$islandId]);
            }
        }

        $this->lastCounterattack = ['island' => $islandId, 'tick' => $tick, 'held' => $held, 'absent' => $absent, 'plundered' => $plundered, 'stockpileLost' => $lost->toArray()];
    }

    /**
     * Złoża wysp pod banderą produkują co tik do magazynu wyspy, aż urobek
     * sięgnie limitu (depositCap()). Tartak i kopalnia podwajają urobek i limit.
     * Liczone leniwie z różnicy tików — deterministycznie, bez zegara. Wyspa
     * bez bandery nie produkuje (jej budynki też nie); posiadana bez tiku
     * naliczenia zaczyna od bieżącego, bez wyrównania wstecz.
     */
    private function accrueDeposits(WorldMap $world): void
    {
        foreach ($world->deposits() as $islandId => $island) {
            if (!$this->ownsIsland($islandId)) {
                continue;
            }
            if (!isset($this->depositTicks[$islandId])) {
                $this->depositTicks[$islandId] = $this->tick;
                continue;
            }
            $this->accrueDeposit($islandId, $island, $this->tick);
        }
    }

    /** Urobek złoża jednej wyspy od ostatniego naliczenia do danego tiku. */
    private function accrueDeposit(string $islandId, Island $island, int $upTo): void
    {
        $ticks = $upTo - ($this->depositTicks[$islandId] ?? $upTo);
        if ($ticks <= 0) {
            return;
        }
        $stock = $this->stockpile($islandId)->toArray();
        $cap = $this->depositCap($island)->toArray();
        $output = $this->depositYield($island)->times($ticks)->toArray();
        foreach ($output as $resource => $amount) {
            $output[$resource] = min($amount, max(0, $cap[$resource] - $stock[$resource]));
        }

        $this->stockpiles[$islandId] = $this->stockpile($islandId)->add(Resources::fromArray($output));
        $this->depositTicks[$islandId] = $upTo;
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
     * kosztuje tik od razu przy starcie — porzucona też zajęła czas. Wyspa
     * pod banderą nie jest areną: walczy się tam tylko w obronie (#78).
     *
     * @param list<string> $shipIds statki, które wypłynęły (do rozliczenia strat)
     */
    public function startBattle(string $islandId, GameId $gameId, array $shipIds = []): void
    {
        if ($this->ownsIsland($islandId)) {
            throw new \DomainException('Island already captured');
        }
        $this->registerBattle($islandId, $gameId, BattleKind::Capture, $shipIds);
    }

    /**
     * Przyjmuje bitwę obronną z kontratakiem w toku na wyspie celu, w dowolnym
     * tiku okna zapowiedzi (obecność sprawdza warstwa aplikacji). Jedna obrona
     * na kontratak; rozpoczęta wstrzymuje termin uderzenia do rozliczenia.
     *
     * @param list<string> $shipIds statki, które wypłynęły (do rozliczenia strat)
     */
    public function startDefense(string $islandId, GameId $gameId, array $shipIds = []): void
    {
        $this->assertCanDefend($islandId);
        $this->registerBattle($islandId, $gameId, BattleKind::Defense, $shipIds);
        $this->counterattack['defense'] = (string) $gameId;
    }

    /** Rzuca błąd domenowy, gdy na wyspie nie da się teraz przyjąć bitwy obronnej. */
    public function assertCanDefend(string $islandId): void
    {
        $counterattack = $this->counterattack;
        if (null === $counterattack || $counterattack['island'] !== $islandId || $this->tick >= $counterattack['strikeAt']) {
            throw new \DomainException('No counterattack on this island');
        }
        if (null !== $counterattack['defense']) {
            throw new \DomainException('Defense already started');
        }
    }

    /** @param list<string> $shipIds */
    private function registerBattle(string $islandId, GameId $gameId, BattleKind $kind, array $shipIds): void
    {
        $key = (string) $gameId;
        if (isset($this->battles[$key])) {
            throw new \DomainException('Battle already registered');
        }

        $this->battles[$key] = ['island' => $islandId, 'settled' => false, 'result' => null, 'kind' => $kind->value, 'ships' => $shipIds];
        ++$this->tick;
    }

    /** Wyspa, o którą toczy się dana gra; null gdy gra nie należy do profilu. */
    public function islandFor(GameId $gameId): ?string
    {
        return $this->battles[(string) $gameId]['island'] ?? null;
    }

    /**
     * Rozlicza bitwę: XP i surowce nigdy nie giną (przyznawane też za porażkę),
     * łup trafia do magazynu wyspy, o którą była bitwa. Wygrane zdobycie wyspy
     * bez bandery stawia banderę i uruchamia złoże. Obrona rozstrzyga kontratak:
     * wygrana zostawia banderę, przegrana odbiera wyspę i połowę magazynu
     * (przed łupem za porażkę). Straty dotykają floty —
     * zatopione statki po wygranej wymagają remontu, po przegranej są stracone;
     * ładunek ponad nową pojemność przepada. Idempotentne: druga próba zwraca zera.
     *
     * @param list<int> $sunkLengths    długości statków gracza zatopionych w bitwie
     * @param int       $enemyShipsSunk liczba jednostek wroga zatopionych w bitwie (statystyka kapitana)
     *
     * @return array{xp:int, loot:Resources, lost:list<string>, damaged:list<string>, cargoLost:Resources, captured:bool, defense: array{held:bool, stockpileLost:Resources}|null}
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
            return ['xp' => 0, 'loot' => Resources::none(), 'lost' => [], 'damaged' => [], 'cargoLost' => Resources::none(), 'captured' => false, 'defense' => null];
        }

        $battle['settled'] = true;
        $battle['result'] = $result;
        $battle['enemySunk'] = $enemyShipsSunk;
        $this->battles[$key] = $battle;

        $this->xp += $xpAward;
        // każdy rodzaj bitwy rozstrzyga, co jego wynik robi z banderą
        [$captured, $defense] = match (self::battleKind($battle)) {
            BattleKind::Capture => ['won' === $result && !$this->ownsIsland($battle['island']), null],
            BattleKind::Defense => [false, $this->settleDefense($key, 'won' === $result)],
        };
        if ($captured) {
            $this->ownedIslands[$battle['island']] = $this->tick;
            // złoże (jeśli wyspa je ma) rusza od tiku zdobycia
            $this->depositTicks[$battle['island']] = $this->tick;
            $this->counterattackCalmFrom = $this->tick;
        }
        $this->stockpiles[$battle['island']] = $this->stockpile($battle['island'])->add($loot);

        [$lost, $damaged] = $this->applyLosses($battle['ships'] ?? [], $result, $sunkLengths);
        $cargoLost = $this->trimCargo();

        return ['xp' => $xpAward, 'loot' => $loot, 'lost' => $lost, 'damaged' => $damaged, 'cargoLost' => $cargoLost, 'captured' => $captured, 'defense' => $defense];
    }

    /**
     * Rozstrzyga kontratak, którego obronę toczyła dana gra. Urobek złoża do
     * bieżącego tiku nalicza wcześniej ensureWorldState() (warstwa aplikacji).
     *
     * @return array{held:bool, stockpileLost:Resources}
     */
    private function settleDefense(string $gameKey, bool $held): array
    {
        if (null === $this->counterattack || $this->counterattack['defense'] !== $gameKey) {
            throw new \LogicException('Defense battle without its counterattack');
        }
        $this->resolveCounterattack($this->tick, held: $held, absent: false);

        return ['held' => $held, 'stockpileLost' => Resources::fromArray($this->lastCounterattack['stockpileLost'] ?? [])];
    }

    /**
     * Kontratak w toku: wyspa celu, tik zapowiedzi i uderzenia, gra obrony
     * (null = obrona nierozpoczęta); null = spokój.
     *
     * @return array{island: string, announcedAt: int, strikeAt: int, defense: ?string}|null
     */
    public function counterattack(): ?array
    {
        return $this->counterattack;
    }

    /** @return array{island: string, tick: int, held: bool, absent: bool, plundered: bool, stockpileLost: array{wood:int, steel:int, gold:int}}|null */
    public function lastCounterattack(): ?array
    {
        return $this->lastCounterattack;
    }

    /** @param array{kind?: string} $battle rekord bez rodzaju pochodzi sprzed bander — to zdobycie */
    private static function battleKind(array $battle): BattleKind
    {
        return BattleKind::tryFrom($battle['kind'] ?? '') ?? BattleKind::Capture;
    }

    public function ownsIsland(string $islandId): bool
    {
        return isset($this->ownedIslands[$islandId]);
    }

    /**
     * Pełna bandera: wszystkie wyspy akwenu (z Zatoką Rozbitka) pod banderą
     * kapitana. Daje przewagę w bitwie obronnej i znika z utratą którejkolwiek
     * wyspy (#79). Dziś jest jeden akwen, więc liczy się cała mapa.
     */
    public function hasFullFlag(WorldMap $world): bool
    {
        $islands = array_keys($world->islands());

        return [] !== $islands && [] === array_diff($islands, array_keys($this->ownedIslands));
    }

    /** @return array<string, int> wyspy pod banderą: id wyspy → tik zdobycia */
    public function ownedIslands(): array
    {
        return $this->ownedIslands;
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

    /** @return array{wins:int, losses:int} rozliczone bitwy o daną wyspę, wszystkich rodzajów */
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

    /** @return array<string, int> tik ostatniego naliczenia urobku złoża (klucz: id wyspy) */
    public function depositTicks(): array
    {
        return $this->depositTicks;
    }

    /**
     * Stan kontrataków do snapshotu.
     *
     * @return array{pending: array{island: string, announcedAt: int, strikeAt: int, defense: ?string}|null, calmFrom: int, rolledTo: int, last: array{island: string, tick: int, held: bool, absent: bool, plundered: bool, stockpileLost: array{wood:int, steel:int, gold:int}}|null}
     */
    public function counterattackState(): array
    {
        return ['pending' => $this->counterattack, 'calmFrom' => $this->counterattackCalmFrom, 'rolledTo' => $this->counterattackRolledTo, 'last' => $this->lastCounterattack];
    }

    /** Surowce, których ensureWorldState() jeszcze nie rozłożyło; null = brak. */
    public function unplacedResources(): ?Resources
    {
        return $this->unplacedResources;
    }

    /** @return array<string, array{island: string, settled: bool, result: ?string, kind?: string, ships?: list<string>, enemySunk?: int}> */
    public function battles(): array
    {
        return $this->battles;
    }

    /**
     * @param array<string, array{island: string, settled: bool, result: ?string, kind?: string, ships?: list<string>, enemySunk?: int}> $battles
     * @param list<OwnedShip>|null                                                                                                       $fleet        null = profil sprzed ekonomii — dostaje flotę startową
     * @param Resources|null                                                                                                             $cargo        null = profil sprzed ładowni (patrz $unplaced) albo sprzed ekonomii — drewno startowe do magazynu wyspy
     * @param int|null                                                                                                                   $worldSeed    null = seed wyprowadzony z id (stabilny dla starych profili)
     * @param array{x:int,y:int}|null                                                                                                    $position     null = start ustali ensureWorldState
     * @param list<string>|null                                                                                                          $discovered   sektory "x:y"
     * @param int|null                                                                                                                   $tick         null = profil sprzed tiku: rejsy + zarejestrowane bitwy
     * @param list<string>|null                                                                                                          $visited      null = profil sprzed odwiedzin: tylko obecna pozycja
     * @param array<string, Resources>                                                                                                   $stockpiles   magazyny wysp
     * @param Resources|null                                                                                                             $unplaced     surowce czekające na magazyn wyspy (też jeden magazyn profilu sprzed ładowni) — rozłożone przy ensureWorldState()
     * @param array<string, int>                                                                                                         $depositTicks tik ostatniego naliczenia urobku złoża (klucz: id wyspy)
     * @param array<string, int>                                                                                                         $ownedIslands wyspy pod banderą: id wyspy → tik zdobycia
     * @param array<string, list<BuildingType>>                                                                                          $buildings    budynki osady (klucz: id wyspy)
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
        array $depositTicks = [],
        array $ownedIslands = [],
        ?array $counterattacks = null,
        array $buildings = [],
    ): self {
        $self = new self($id, $name, max(0, $xp));
        $self->battles = $battles;
        $self->fleet = $fleet ?? self::startingFleet();
        $self->stockpiles = $stockpiles;
        $self->depositTicks = $depositTicks;
        $self->ownedIslands = $ownedIslands;
        $self->buildings = $buildings;
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
        $self->counterattack = $counterattacks['pending'] ?? null;
        $self->counterattackCalmFrom = $counterattacks['calmFrom'] ?? max([0, ...array_values($ownedIslands)]);
        $self->counterattackRolledTo = $counterattacks['rolledTo'] ?? $self->tick;
        $self->lastCounterattack = $counterattacks['last'] ?? null;

        return $self;
    }
}
