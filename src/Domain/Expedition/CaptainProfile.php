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

    /**
     * Fale zabierają procent ładunku, ale nie mniej niż minimum drewna (o ile
     * jest w ładowni). Konwój bez eskorty traci dwukrotność obu wartości.
     */
    private const STORM_CARGO_LOSS_PERCENT = 10;
    private const STORM_MIN_WOOD_LOSS = 5;

    /** Zasięg rejsu w sektorach (większa z różnic x i y); z Żeglugą dwusektorową dalszy. */
    private const SAIL_RANGE = 1;
    private const SAIL_RANGE_TWO_SECTOR = 2;

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

    /** Dziennik kapitana (#111) trzyma tyle ostatnich wpisów. */
    public const LOG_LIMIT = 50;

    /** @var array<string, array{island: string, settled: bool, result: ?string, kind?: string, ships?: list<string>, enemySunk?: int, stronghold?: bool}> klucz: gameId */
    private array $battles = [];

    /**
     * Twierdze po wygranej potyczce, czekające na oblężenie (#120): id wyspy →
     * tik potyczki. Przegrane oblężenie stanu nie zdejmuje, wygrane zamienia go
     * w banderę.
     *
     * @var array<string, int>
     */
    private array $besieged = [];

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
     * Zbadane technologie (Epic G): działają na stałe od tiku ukończenia.
     *
     * @var list<Technology>
     */
    private array $researched = [];

    /**
     * Badanie w toku (najwyżej jedno, niezależnie od liczby uczelni): wyspa
     * uczelni, tiki pod banderą zaliczone do postępu i tik, do którego je
     * policzono. Bez bandery na wyspie badanie stoi, ale dalej jest tym jednym.
     *
     * @var array{technology: Technology, island: string, progress: int, countedTo: int}|null
     */
    private ?array $research = null;

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

    /** Akwen, w którym stoi flota (Epic I) — flota jest jedna, więc akwen bieżący też. */
    private string $sea = Sea::START;

    /**
     * @var array<string, SeaState> pozycja, odkryte i odwiedzone sektory osobno dla
     *                              każdego akwenu (klucz: id akwenu); brak pozycji
     *                              bieżącego akwenu = stan do inicjalizacji (ensureWorldState)
     */
    private array $seaStates = [];

    /**
     * Bramy otwarte na stałe (Epic I): strzeżoną cieśninę otwiera pierwsza
     * wygrana ze strażnikiem — w obie strony, bo obie krawędzie mają wspólne id.
     *
     * @var array<string, int> klucz: id bramy, wartość: tik otwarcia
     */
    private array $openedGates = [];

    /**
     * Dziennik kapitana (#111): ostatnie ważne zdarzenia w kolejności tików,
     * najstarsze pierwsze, najwyżej LOG_LIMIT wpisów.
     *
     * @var list<LogEntry>
     */
    private array $log = [];

    /** Licznik ruchów — wejście do deterministycznych rzutów eventów podróży. */
    private int $moveCount = 0;

    /**
     * Statki zdobyte abordażem, czekające na decyzję kapitana (#118); klucz = id zdobyczy.
     *
     * @var array<string, Prize>
     */
    private array $prizes = [];

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

    /**
     * Pojemność ładowni = suma pojemności wszystkich statków floty. Uszkodzony
     * statek wozi dalej — uszkodzenie odbiera broń, nie ładownię (#92).
     */
    public function cargoCapacity(): int
    {
        return array_sum(array_map(fn (OwnedShip $s) => $this->shipCargoCapacity($s->type), $this->fleet));
    }

    /** Ładownia statku danego typu: Pojemne ładownie dają +50%, z zaokrągleniem w dół. */
    public function shipCargoCapacity(ShipType $type): int
    {
        $capacity = $type->cargoCapacity();

        return $this->hasResearched(Technology::PojemneLadownie) ? intdiv($capacity * 3, 2) : $capacity;
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

    /** Ładunek ponad pojemność (po utracie statku) przepada; zwraca, co przepadło. */
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
     * Konwój = statki z co najmniej jednym transportowcem (także uszkodzonym —
     * wozi ładunek); eskortuje go sprawny statek z bronią. Reguła nie dotyczy
     * floty bez transportowca, więc same tratwy nadal walczą (bezpiecznik
     * anty-softlock).
     *
     * @param list<OwnedShip> $ships
     */
    public static function isUnescortedConvoy(array $ships): bool
    {
        $hasTransport = false;
        foreach ($ships as $ship) {
            if ($ship->type->isArmed() && !$ship->isDamaged()) {
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
     * Buduje statek: bramka rangi, wariant odblokowany technologią + koszt
     * typu z magazynu wyspy i ładowni (obecność i stocznię sprawdza warstwa
     * aplikacji). Bez wskazanego wariantu statek dostaje domyślny.
     */
    public function buildShip(ShipType $type, string $islandId, ?ShipVariant $variant = null): OwnedShip
    {
        if (!$this->rank()->atLeast($type->requiredRank())) {
            throw new \DomainException(sprintf('Ship type locked: requires rank %s', $type->requiredRank()->value));
        }
        if (null !== $variant && $variant->type() !== $type) {
            throw new \InvalidArgumentException('Ship variant not available for this ship type');
        }
        $technology = $variant?->requiredTechnology();
        if (null !== $technology && !$this->hasResearched($technology)) {
            throw new \DomainException(sprintf('Ship variant locked: requires technology %s', $technology->value));
        }
        $this->pay($islandId, $type->buildCost());

        $ship = OwnedShip::build($type, $variant);
        $this->fleet[] = $ship;
        $this->record($this->tick, LogEvent::ShipBuilt, ['ship' => $type->value, 'variant' => $ship->variant?->value, 'island' => $islandId]);

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

        $this->pay($islandId, $this->repairCost($ship->type));
        $ship->repair();

        return $ship;
    }

    /** Koszt remontu statku danego typu: Dok remontowy daje połowę ceny, z zaokrągleniem w górę. */
    public function repairCost(ShipType $type): Resources
    {
        $cost = $type->repairCost();
        if (!$this->hasResearched(Technology::DokRemontowy)) {
            return $cost;
        }

        return Resources::fromArray(array_map(static fn (int $amount) => intdiv($amount + 1, 2), $cost->toArray()));
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
        $this->record($this->tick, LogEvent::BuildingBuilt, ['building' => $type->value, 'island' => $island->id]);
    }

    /**
     * Urobek złoża na tik pod banderą: Lepsze narzędzia dokładają 1 jednostkę
     * każdego surowca złoża, mnożnik złóż akwenu (Epic I) mnoży ten urobek,
     * a budynki stojące na wyspie (tartak, kopalnia) podwajają już powiększony
     * urobek. Domyślny motyw = Morze Rozbitków (×1).
     */
    public function depositYield(Island $island, SeaTheme $theme = new SeaTheme()): Resources
    {
        $base = $island->depositYield;
        if ($this->hasResearched(Technology::LepszeNarzedzia)) {
            $base = Resources::fromArray(array_map(static fn (int $amount) => $amount > 0 ? $amount + 1 : 0, $base->toArray()));
        }
        $base = $base->times($theme->depositMultiplier);
        $yield = $base;
        foreach ($this->buildingsOn($island->id) as $building) {
            $yield = $yield->add($building->depositBoost($base));
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

    /** Ile urobku może czekać w magazynie wyspy: limit rośnie razem z urobkiem, Spichlerze go podwajają. */
    public function depositCap(Island $island, SeaTheme $theme = new SeaTheme()): Resources
    {
        $bufferTicks = $this->hasResearched(Technology::Spichlerze) ? Island::DEPOSIT_BUFFER_TICKS_GRANARY : Island::DEPOSIT_BUFFER_TICKS;

        return $this->depositYield($island, $theme)->times($bufferTicks);
    }

    // --- Badania: uczelnia i drzewko technologii (Epic G) ---

    /** @return list<Technology> */
    public function researched(): array
    {
        return $this->researched;
    }

    public function hasResearched(Technology $technology): bool
    {
        return in_array($technology, $this->researched, true);
    }

    /**
     * Badanie w toku; null = żadne nie trwa.
     *
     * @return array{technology: Technology, island: string, progress: int, countedTo: int}|null
     */
    public function research(): ?array
    {
        return $this->research;
    }

    /** Badanie stoi: wyspa jego uczelni straciła banderę. Wznawia je odbicie wyspy. */
    public function isResearchPaused(): bool
    {
        return null !== $this->research && !$this->ownsIsland($this->research['island']);
    }

    /**
     * Dlaczego badania nie da się teraz zacząć na wyspie (null = kapitan na
     * morzu); null = można badać. Kolejność: zbadana, trwa inne badanie,
     * technologia ze zdobyczy (nie bada się jej na uczelni, #121),
     * reguła drzewka (poprzedni poziom, ranga), czynna uczelnia, obecność
     * kapitana, koszt (magazyn wyspy i ładownia).
     */
    public function researchBlocker(Technology $technology, ?Island $island, WorldMap $world): ?ResearchBlock
    {
        if ($this->hasResearched($technology)) {
            return ResearchBlock::Researched;
        }
        if (TechUnlock::Boarding === $technology->unlock()) {
            return ResearchBlock::BoardingOnly;
        }
        if (null !== $this->research) {
            return ResearchBlock::Busy;
        }
        $level = $technology->academyLevel();
        $tree = $level->treeBlocker($technology->branch(), $this->researched, $this->rank());
        if (null !== $tree) {
            return $tree;
        }

        return match (true) {
            null === $island => ResearchBlock::NotPresent,
            !$this->hasActiveBuilding($island->id, BuildingType::Uczelnia) => ResearchBlock::NoAcademy,
            !$this->isAt($world, $island->id) => ResearchBlock::NotPresent,
            !$this->stockpile($island->id)->add($this->cargo)->covers($level->researchCost()) => ResearchBlock::Resources,
            default => null,
        };
    }

    /**
     * Zaczyna badanie w czynnej uczelni wyspy, na której stoi kapitan.
     * Płatność jak za budynek; start nie kosztuje tiku, postęp liczą tiki
     * pod banderą od bieżącego.
     */
    public function startResearch(Technology $technology, Island $island, WorldMap $world): void
    {
        $this->ensureWorldState($world);

        $blocker = $this->researchBlocker($technology, $island, $world);
        if (null !== $blocker) {
            throw new \DomainException($blocker->message($technology));
        }

        $this->pay($island->id, $technology->academyLevel()->researchCost());
        $this->research = ['technology' => $technology, 'island' => $island->id, 'progress' => 0, 'countedTo' => $this->tick];
    }

    /** Ile tików pod banderą brakuje do końca badania w toku; null = żadne nie trwa. */
    public function researchTicksLeft(): ?int
    {
        if (null === $this->research) {
            return null;
        }

        return $this->research['technology']->academyLevel()->researchTicks() - $this->research['progress'];
    }

    /**
     * Postęp badania do danego tiku: liczą się tylko tiki z banderą na wyspie
     * uczelni. Wołane przed każdą zmianą bandery, więc odcinek od ostatniego
     * naliczenia ma jedną banderę. Ukończone badanie działa od tiku ukończenia,
     * dlatego urobek złóż do tego tiku nalicza się jeszcze po staremu.
     */
    private function accrueResearch(int $upTo, ?WorldMap $world): void
    {
        if (null === $this->research || $upTo <= $this->research['countedTo']) {
            return;
        }
        if (!$this->ownsIsland($this->research['island'])) {
            $this->research['countedTo'] = $upTo;

            return;
        }

        $doneAt = $this->research['countedTo'] + $this->researchTicksLeft();
        if ($doneAt > $upTo) {
            $this->research['progress'] += $upTo - $this->research['countedTo'];
            $this->research['countedTo'] = $upTo;

            return;
        }

        if (null !== $world) {
            foreach ($world->deposits() as $islandId => $island) {
                if ($this->ownsIsland($islandId) && isset($this->depositTicks[$islandId])) {
                    $this->accrueDeposit($islandId, $island, $doneAt, $world->theme());
                }
            }
        }
        $this->researched[] = $this->research['technology'];
        $this->record($doneAt, LogEvent::ResearchCompleted, ['technology' => $this->research['technology']->value, 'island' => $this->research['island']]);
        $this->research = null;
    }

    /**
     * Szansa sztormu przy pierwszym wpłynięciu na sektor (w %): z motywu
     * akwenu, a Mapy sztormowe ją połowią. Domyślny motyw = Morze Rozbitków.
     */
    public function stormChance(SeaTheme $theme = new SeaTheme()): int
    {
        return $this->hasResearched(Technology::MapySztormowe) ? intdiv($theme->stormChance, 2) : $theme->stormChance;
    }

    /** Najdalszy sektor osiągalny w jednym rejsie (za 1 tik). */
    public function sailRange(): int
    {
        return $this->hasResearched(Technology::ZeglugaDwusektorowa) ? self::SAIL_RANGE_TWO_SECTOR : self::SAIL_RANGE;
    }

    // --- Wolne morze: żegluga, mgła świata, kartografia ---

    public function worldSeed(): int
    {
        return $this->worldSeed;
    }

    /** Akwen, w którym stoi flota. */
    public function currentSea(): string
    {
        return $this->sea;
    }

    /** @return array<string, SeaState> stan kapitana na każdym akwenie (klucz: id akwenu) */
    public function seaStates(): array
    {
        return $this->seaStates;
    }

    /** Stan bieżącego akwenu. */
    private function here(): SeaState
    {
        return $this->seaStates[$this->sea] ??= new SeaState();
    }

    /** Czy stan morski (pozycja/mgła) bieżącego akwenu został już zainicjalizowany. */
    public function hasWorldState(): bool
    {
        return null !== ($this->seaStates[$this->sea] ?? null)?->position();
    }

    /**
     * Inicjalizuje stan morski bieżącego akwenu (start = sektor pierwszej
     * wyspy + widoczność akwenu), jeśli profil jeszcze go nie ma (nowy albo
     * sprzed wolnego morza). Mapa musi być mapą akwenu, w którym stoi flota.
     */
    public function ensureWorldState(WorldMap $world): void
    {
        if ($world->seaId() !== $this->sea) {
            throw new \LogicException("Fleet is in sea {$this->sea}, not {$world->seaId()}");
        }
        if (!$this->hasWorldState()) {
            $start = $world->startPosition();
            $this->here()->moveTo($start['x'], $start['y']);
            foreach ($this->neighborhood($start['x'], $start['y'], $world) as [$x, $y]) {
                $this->here()->discover($x, $y);
            }
        }

        $this->placeUnplacedResources($world);
        $this->rollCounterattacks($world);
        $this->accrueResearch($this->tick, $world);
        $this->accrueDeposits($world);
    }

    /**
     * Rozgrywa kontrataki tik po tiku od ostatniego rzutu (leniwie, jak
     * urobek złóż — deterministycznie z seed+tik, bez zegara). Każda akcja
     * zmieniająca bandery woła wcześniej ensureWorldState(), więc rzut dla
     * tiku widzi stan profilu z tego tiku. Uderzenie bez rozpoczętej obrony
     * odbiera wyspę, a pod fortem ją plądruje (nieobecność nie kosztuje floty).
     * Celem jest wyspa z dowolnego akwenu, który zna mapa (WorldMap::otherSeas(),
     * #109), więc rzut nie zależy od tego, gdzie stoi flota.
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
            // cele z całego świata (#109): przystań rozpoznaje mapa akwenu wyspy,
            // a wyspa spoza znanych map nie jest celem
            $targets = array_values(array_filter(
                array_map('strval', array_keys($this->ownedIslands)),
                static fn (string $id) => !($world->seaOf($id)?->isSafeHarbor($id) ?? true),
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
            $this->record($t, LogEvent::CounterattackAnnounced, ['island' => $this->counterattack['island'], 'strikeAt' => $this->counterattack['strikeAt']]);
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
            // badanie w uczelni wyspy liczy tiki pod banderą do tiku uderzenia
            $this->accrueResearch($tick, $world);
            // urobek złoża należy się do tiku uderzenia — potem złoże staje, chyba że fort utrzymał wyspę;
            // złoże i motyw z akwenu wyspy, także gdy flota stoi na innym (#109)
            $islandSea = $world?->seaOf($islandId);
            $island = $islandSea?->deposits()[$islandId] ?? null;
            if (null !== $island) {
                $this->accrueDeposit($islandId, $island, $tick, $islandSea->theme());
            }
            $stock = $this->stockpile($islandId);
            $lost = new Resources(intdiv($stock->wood, 2), intdiv($stock->steel, 2), intdiv($stock->gold, 2));
            $this->stockpiles[$islandId] = $stock->subtract($lost);
            if (!$plundered) {
                unset($this->ownedIslands[$islandId], $this->depositTicks[$islandId]);
            }
        }

        $this->lastCounterattack = ['island' => $islandId, 'tick' => $tick, 'held' => $held, 'absent' => $absent, 'plundered' => $plundered, 'stockpileLost' => $lost->toArray()];
        if ($held) {
            $this->record($tick, LogEvent::IslandDefended, ['island' => $islandId]);
        } elseif ($plundered) {
            $this->record($tick, LogEvent::IslandPlundered, ['island' => $islandId, 'stockpileLost' => $lost->toArray()]);
        } else {
            $this->record($tick, LogEvent::IslandLost, ['island' => $islandId, 'absent' => $absent, 'stockpileLost' => $lost->toArray()]);
        }
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
            $this->accrueDeposit($islandId, $island, $this->tick, $world->theme());
        }
    }

    /** Urobek złoża jednej wyspy (z akwenu o danym motywie) od ostatniego naliczenia do danego tiku. */
    private function accrueDeposit(string $islandId, Island $island, int $upTo, SeaTheme $theme): void
    {
        $ticks = $upTo - ($this->depositTicks[$islandId] ?? $upTo);
        if ($ticks <= 0) {
            return;
        }
        $stock = $this->stockpile($islandId)->toArray();
        $cap = $this->depositCap($island, $theme)->toArray();
        $output = $this->depositYield($island, $theme)->times($ticks)->toArray();
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

        $position = $this->position();
        $here = $world->islandAt($position['x'], $position['y']);
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

    /** @return array{x:int,y:int} pozycja floty na bieżącym akwenie */
    public function position(): array
    {
        return $this->here()->position() ?? throw new \DomainException('World state not initialized');
    }

    /** Czy sektor bieżącego akwenu jest odkryty. */
    public function isDiscovered(int $x, int $y): bool
    {
        return $this->here()->isDiscovered($x, $y);
    }

    /** @return list<string> odkryte sektory bieżącego akwenu jako "x:y" */
    public function discoveredSectors(): array
    {
        return $this->here()->discoveredSectors();
    }

    /** Czy kapitan był na sektorze bieżącego akwenu. */
    public function isVisited(int $x, int $y): bool
    {
        return $this->here()->isVisited($x, $y);
    }

    /** @return list<string> odwiedzone sektory bieżącego akwenu jako "x:y" */
    public function visitedSectors(): array
    {
        return $this->here()->visitedSectors();
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

        return null !== $pos && $pos === $this->position();
    }

    /**
     * Rejs za 1 tik na sektor w zasięgu sailRange(): sąsiedni, a z Żeglugą
     * dwusektorową także odległy o 2. Sektor, przez który flota przepływa,
     * zostaje odkryty (także przy widoczności 0, #108), ale nie odwiedzony
     * i nie grozi sztormem. Pierwsze wpłynięcie na sektor grozi sztormem
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
        $position = $this->position();
        $distance = max(abs($x - $position['x']), abs($y - $position['y']));
        if (0 === $distance || (1 === $this->sailRange() && $distance > 1)) {
            throw new \DomainException('Can only sail to an adjacent sector');
        }
        if ($distance > $this->sailRange()) {
            throw new \DomainException('Sector beyond sailing range');
        }

        ++$this->moveCount;
        ++$this->tick;

        // rejs o 2 sektory odkrywa też sektor po drodze: krok od startu w stronę
        // celu (przy ruchu 2+1 po skosie). Przy widoczności 1 leży on i tak
        // w sąsiedztwie celu; liczy się na Morzu Mgieł z widocznością 0 (#108)
        $passed = 2 === $distance
            ? [[$position['x'] + ($x <=> $position['x']), $position['y'] + ($y <=> $position['y'])]]
            : [];

        return $this->arrive($x, $y, $world, $passed);
    }

    /**
     * Wpłynięcie floty na sektor bieżącego akwenu po rejsie albo przejściu
     * przez bramę: sztorm z motywu akwenu, odkrycie sąsiedztwa i kartografia.
     *
     * @param list<array{0:int,1:int}> $passed sektory minięte po drodze (odkrywane bez sztormu)
     *
     * @return array{discoveredNow: list<string>, cartography: int, cartographyLost: int, event: array<string,mixed>|null}
     */
    private function arrive(int $x, int $y, WorldMap $world, array $passed = []): array
    {
        // sztorm łapie w drodze — tylko przy pierwszym wpłynięciu na sektor.
        // Nie „nieodkryty”: cel rejsu bywa odkryty wcześniej (sąsiedztwo, #68),
        // a przy widoczności 0 nieodkryty jest każdy nowy cel
        $event = $this->isVisited($x, $y) ? null : $this->rollStorm($world->theme());
        if (null !== $event) {
            $this->record($this->tick, LogEvent::Storm, array_diff_key($event, ['type' => true]));
        }

        $this->here()->moveTo($x, $y);

        $discoveredNow = [];
        $cartography = 0;
        foreach ([...$passed, ...$this->neighborhood($x, $y, $world)] as [$nx, $ny]) {
            if (!$this->here()->discover($nx, $ny)) {
                continue;
            }
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
    private function rollStorm(SeaTheme $theme): ?array
    {
        if (crc32("{$this->worldSeed}:{$this->tick}") % 100 >= $this->stormChance($theme)) {
            return null;
        }

        $effectRoll = crc32("{$this->worldSeed}:{$this->tick}:effect");
        $active = $this->activeFleet();

        if (1 === $effectRoll % 2 && [] !== $active) {
            $ship = $active[$effectRoll % count($active)];
            // uszkodzony statek traci broń, ładownię zachowuje — ładunek zostaje
            $ship->markDamaged();

            return ['type' => 'storm', 'effect' => 'ship-damaged', 'ship' => $ship->type->value];
        }

        $unescorted = self::isUnescortedConvoy($this->fleet);
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

    /**
     * @return list<array{0:int,1:int}> sektor + sąsiedztwo w promieniu widoczności
     *                                  akwenu (1 = jak przed akwenami, 0 = tylko sektor) w granicach mapy
     */
    private function neighborhood(int $x, int $y, WorldMap $world): array
    {
        $radius = $world->theme()->visibility;
        $out = [];
        for ($dx = -$radius; $dx <= $radius; ++$dx) {
            for ($dy = -$radius; $dy <= $radius; ++$dy) {
                if ($world->isInside($x + $dx, $y + $dy)) {
                    $out[] = [$x + $dx, $y + $dy];
                }
            }
        }

        return $out;
    }

    // --- Bramy akwenów (Epic I) ---

    public function isGateOpen(string $gateId): bool
    {
        return isset($this->openedGates[$gateId]);
    }

    /** @return array<string, int> bramy otwarte na stałe: id bramy → tik otwarcia */
    public function openedGates(): array
    {
        return $this->openedGates;
    }

    /** Dlaczego przejście jest zamknięte dla kapitana; null = można przepłynąć. */
    public function gateBlocker(SeaPassage $passage): ?GateBlock
    {
        return $passage->gate->condition->blocker($this, $passage->gate->id);
    }

    /** Czy flota stoi na sektorze bramy bieżącego akwenu. */
    public function isAtGate(WorldMap $world, string $gateId): bool
    {
        $this->ensureWorldState($world);
        $pos = $world->gatePosition($gateId);

        return null !== $pos && $pos === $this->position();
    }

    /**
     * Przejście przez otwartą bramę z sektora bramy: 1 tik, flota ląduje na
     * sektorze tej samej bramy w akwenie docelowym. Wpłynięcie liczy się jak
     * rejs: sztorm z motywu akwenu docelowego przy pierwszym wpłynięciu na
     * sektor (konwój bez eskorty traci podwójnie), odkrycie sąsiedztwa
     * i kartografia. Stan akwenu, który flota opuszcza, czeka na powrót.
     *
     * @return array{discoveredNow: list<string>, cartography: int, cartographyLost: int, event: array<string,mixed>|null}
     */
    public function passGate(SeaPassage $passage, WorldMap $from, WorldMap $to): array
    {
        if ($passage->from !== $from->seaId() || $passage->to !== $to->seaId()) {
            throw new \LogicException('Sea maps do not match the passage');
        }
        if (!$this->isAtGate($from, $passage->gate->id)) {
            throw new \DomainException('Not at gate');
        }
        $blocker = $this->gateBlocker($passage);
        if (null !== $blocker) {
            throw new \DomainException($blocker->message());
        }
        $arrival = $to->gatePosition($passage->gate->id) ?? throw new \LogicException('Gate has no sector on the target sea');

        ++$this->moveCount;
        ++$this->tick;
        $this->record($this->tick, LogEvent::GatePassed, ['gate' => $passage->gate->id, 'from' => $passage->from, 'to' => $passage->to]);
        $this->sea = $passage->to;
        $outcome = $this->arrive($arrival['x'], $arrival['y'], $to);
        // urobek, badanie i kontrataki akwenu docelowego do bieżącego tiku
        $this->ensureWorldState($to);

        return $outcome;
    }

    // --- Bitwy ---

    /**
     * Rejestruje rozpoczętą bitwę o wyspę (jedna gra = jedna bitwa). Bitwa
     * kosztuje tik od razu przy starcie — porzucona też zajęła czas. Wyspa
     * pod banderą nie jest areną: walczy się tam tylko w obronie (#78).
     * Na twierdzy to potyczka z flotą strażniczą: wygrana oblega wyspę, więc
     * oblężonej nie atakuje się drugi raz (#120).
     *
     * @param list<string> $shipIds    statki, które wypłynęły (do rozliczenia strat)
     * @param bool         $stronghold wyspa z fortem — wygrana nie stawia bandery, tylko oblega
     */
    public function startBattle(string $islandId, GameId $gameId, array $shipIds = [], bool $stronghold = false): void
    {
        if ($this->ownsIsland($islandId)) {
            throw new \DomainException('Island already captured');
        }
        if ($this->isBesieged($islandId)) {
            throw new \DomainException('Island already besieged');
        }
        $this->registerBattle($islandId, $gameId, BattleKind::Capture, $shipIds);
        if ($stronghold) {
            $this->battles[(string) $gameId]['stronghold'] = true;
        }
    }

    /**
     * Rejestruje oblężenie fortu oblężonej twierdzy (#120). Oblężenie zaczyna
     * się osobną akcją po wygranej potyczce i można je powtarzać aż do zdobycia.
     *
     * @param list<string> $shipIds statki, które wypłynęły (do rozliczenia strat)
     */
    public function startSiege(string $islandId, GameId $gameId, array $shipIds = []): void
    {
        if ($this->ownsIsland($islandId)) {
            throw new \DomainException('Island already captured');
        }
        if (!$this->isBesieged($islandId)) {
            throw new \DomainException('Island not besieged');
        }
        $this->registerBattle($islandId, $gameId, BattleKind::Siege, $shipIds);
    }

    public function isBesieged(string $islandId): bool
    {
        return isset($this->besieged[$islandId]);
    }

    /** @return array<string, int> oblężone twierdze: id wyspy → tik wygranej potyczki */
    public function besiegedIslands(): array
    {
        return $this->besieged;
    }

    /** Liczba rozpoczętych oblężeń wyspy — numer kolejnej próby daje nowy układ fortu. */
    public function siegeAttempts(string $islandId): int
    {
        return count(array_filter(
            $this->battles,
            static fn (array $battle) => $battle['island'] === $islandId && BattleKind::Siege === self::battleKind($battle),
        ));
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

    /**
     * Rejestruje bitwę ze strażnikiem bramy. W rejestrze zamiast wyspy stoi
     * id bramy; strażnik pilnuje bramy tylko do pierwszej przegranej.
     *
     * @param list<string> $shipIds statki, które wypłynęły (do rozliczenia strat)
     */
    public function startGuardianBattle(string $gateId, GameId $gameId, array $shipIds = []): void
    {
        if ($this->isGateOpen($gateId)) {
            throw new \DomainException('Gate already open');
        }
        $this->registerBattle($gateId, $gameId, BattleKind::Guardian, $shipIds);
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

    /** Wyspa (przy bitwie strażnika: brama), o którą toczy się dana gra; null gdy gra nie należy do profilu. */
    public function islandFor(GameId $gameId): ?string
    {
        return $this->battles[(string) $gameId]['island'] ?? null;
    }

    /** Rodzaj zarejestrowanej bitwy; null gdy gra nie należy do profilu. */
    public function battleKindFor(GameId $gameId): ?BattleKind
    {
        $battle = $this->battles[(string) $gameId] ?? null;

        return null !== $battle ? self::battleKind($battle) : null;
    }

    /**
     * Rozlicza bitwę: XP i surowce nigdy nie giną (przyznawane też za porażkę),
     * łup trafia do magazynu wyspy, o którą była bitwa. Wygrane zdobycie wyspy
     * bez bandery stawia banderę i uruchamia złoże. Obrona rozstrzyga kontratak:
     * wygrana zostawia banderę, przegrana odbiera wyspę i połowę magazynu
     * (przed łupem za porażkę). Wygrana ze strażnikiem otwiera bramę na stałe;
     * jego łup trafia do ładowni, bo przy bramie nie ma magazynu — nadmiar
     * przepada. Wygrana potyczka na twierdzy oblega wyspę, a banderę stawia
     * wygrane oblężenie; przegrane zostawia oblężenie (#120). Straty dotykają floty —
     * zatopione statki po wygranej wymagają remontu, po przegranej są stracone;
     * ładunek ponad nową pojemność przepada. Idempotentne: druga próba zwraca zera.
     *
     * @param list<int>               $sunkLengths    długości statków gracza zatopionych w bitwie
     * @param int                     $enemyShipsSunk liczba jednostek wroga zatopionych w bitwie (statystyka kapitana)
     * @param array<int, ShipVariant> $sunkVariants   wariant zatopionego statku, klucz = indeks w $sunkLengths;
     *                                                bez wpisu stratę ponosi dowolny statek tej długości
     * @param ShipType|null           $boardedType    typ statku wroga zdobytego abordażem (#118); po wygranej czeka na decyzję
     *
     * @return array{xp:int, loot:Resources, lost:list<string>, damaged:list<string>, cargoLost:Resources, captured:bool, besieged:bool, defense: array{held:bool, stockpileLost:Resources}|null, gateOpened:bool, prize:?Prize}
     */
    public function settleBattle(GameId $gameId, string $result, int $xpAward, ?Resources $loot = null, array $sunkLengths = [], int $enemyShipsSunk = 0, array $sunkVariants = [], ?ShipType $boardedType = null, ?ShipVariant $boardedVariant = null): array
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
            return ['xp' => 0, 'loot' => Resources::none(), 'lost' => [], 'damaged' => [], 'cargoLost' => Resources::none(), 'captured' => false, 'besieged' => false, 'defense' => null, 'gateOpened' => false, 'prize' => null];
        }

        $battle['settled'] = true;
        $battle['result'] = $result;
        $battle['enemySunk'] = $enemyShipsSunk;
        $this->battles[$key] = $battle;

        $this->xp += $xpAward;
        // każdy rodzaj bitwy rozstrzyga, co jego wynik robi z banderą (i z bramą)
        $kind = self::battleKind($battle);
        $won = 'won' === $result;
        $free = !$this->ownsIsland($battle['island']);
        $stronghold = $battle['stronghold'] ?? false;
        [$captured, $defense] = match ($kind) {
            BattleKind::Capture => [$won && $free && !$stronghold, null],
            BattleKind::Defense => [false, $this->settleDefense($key, $won)],
            BattleKind::Guardian => [false, null],
            BattleKind::Siege => [$won && $free, null],
        };
        // potyczka na twierdzy oblega wyspę (#120)
        $besieged = BattleKind::Capture === $kind && $won && $free && $stronghold && !$this->isBesieged($battle['island']);
        if ($besieged) {
            $this->besieged[$battle['island']] = $this->tick;
            $this->record($this->tick, LogEvent::IslandBesieged, ['island' => $battle['island']]);
        }
        if (BattleKind::Siege === $kind && !$won) {
            $this->record($this->tick, LogEvent::SiegeFailed, ['island' => $battle['island']]);
        }
        $gateOpened = BattleKind::Guardian === $kind && 'won' === $result && !$this->isGateOpen($battle['island']);
        if ($gateOpened) {
            $this->openedGates[$battle['island']] = $this->tick;
            $this->record($this->tick, LogEvent::GateOpened, ['gate' => $battle['island']]);
        }
        if ($captured) {
            unset($this->besieged[$battle['island']]);
            // odbita wyspa wznawia badanie swojej uczelni od tiku zdobycia
            $this->accrueResearch($this->tick, null);
            $this->ownedIslands[$battle['island']] = $this->tick;
            // złoże (jeśli wyspa je ma) rusza od tiku zdobycia
            $this->depositTicks[$battle['island']] = $this->tick;
            $this->counterattackCalmFrom = $this->tick;
            $this->record($this->tick, LogEvent::IslandCaptured, ['island' => $battle['island']]);
        }
        if (BattleKind::Guardian !== $kind) {
            $this->stockpiles[$battle['island']] = $this->stockpile($battle['island'])->add($loot);
        }

        [$lost, $damaged] = $this->applyLosses($battle['ships'] ?? [], $result, $sunkLengths, $sunkVariants);
        $cargoLost = $this->trimCargo();
        if (BattleKind::Guardian === $kind) {
            // łup strażnika ładuje flota, która przetrwała bitwę
            [$fit, $overflow] = $loot->fitInto($this->cargoCapacity() - $this->cargo->total());
            $this->cargo = $this->cargo->add($fit);
            $cargoLost = $cargoLost->add($overflow);
        }

        $prize = null;
        if ('won' === $result && null !== $boardedType) {
            $prize = Prize::capture($boardedType, $boardedVariant, $this->tick);
            $this->prizes[$prize->id] = $prize;
            $place = BattleKind::Guardian === $kind ? 'gate' : 'island';
            $this->record($this->tick, LogEvent::ShipBoarded, ['ship' => $boardedType->value, 'variant' => $boardedVariant?->value, $place => $battle['island']]);
        }

        return ['xp' => $xpAward, 'loot' => $loot, 'lost' => $lost, 'damaged' => $damaged, 'cargoLost' => $cargoLost, 'captured' => $captured, 'besieged' => $besieged, 'defense' => $defense, 'gateOpened' => $gateOpened, 'prize' => $prize];
    }

    // --- Zdobyte statki (#118) ---

    /** @return list<Prize> statki zdobyte abordażem, czekające na decyzję */
    public function prizes(): array
    {
        return array_values($this->prizes);
    }

    /**
     * Technologia, którą da się odblokować, badając konstrukcję zdobyczy
     * (#121): wariant ze zdobyczy, którego kapitan jeszcze nie zna; null = nie
     * ma czego badać (statek bez wariantu, wariant z uczelni albo już znany).
     */
    public function studyableTechnology(Prize $prize): ?Technology
    {
        $technology = $prize->variant?->requiredTechnology();

        return null !== $technology && TechUnlock::Boarding === $technology->unlock() && !$this->hasResearched($technology)
            ? $technology
            : null;
    }

    /**
     * Decyzja o zdobytym statku, w dowolnym miejscu i czasie. Dołączenie daje
     * uszkodzony statek bez wymogu rangi (ranga ogranicza tylko budowę);
     * rozbiórka ładuje połowę kosztu budowy do ładowni, a nadmiar przepada;
     * zbadanie konstrukcji (#121) odblokowuje wariant w stoczni od razu,
     * bez kosztu i tików, a statek przepada.
     *
     * @return array{decision:string, prize:Prize, ship:?OwnedShip, refund:Resources, cargoLost:Resources, technology:?Technology}
     */
    public function decidePrize(string $prizeId, PrizeDecision $decision): array
    {
        $prize = $this->prizes[$prizeId] ?? throw new \DomainException('Prize not found');
        $technology = PrizeDecision::Study === $decision ? $this->studyableTechnology($prize) : null;
        if (PrizeDecision::Study === $decision && null === $technology) {
            throw new \DomainException('Prize cannot be studied');
        }
        unset($this->prizes[$prizeId]);

        $ship = null;
        $refund = Resources::none();
        $cargoLost = Resources::none();
        switch ($decision) {
            case PrizeDecision::Join:
                $ship = OwnedShip::build($prize->type, $prize->variant);
                $ship->markDamaged();
                $this->fleet[] = $ship;
                break;
            case PrizeDecision::Scrap:
                [$refund, $cargoLost] = $prize->scrapValue()->fitInto($this->cargoCapacity() - $this->cargo->total());
                $this->cargo = $this->cargo->add($refund);
                break;
            case PrizeDecision::Study:
                $this->researched[] = $technology;
                break;
        }

        $this->record($this->tick, LogEvent::PrizeDecided, [
            'ship' => $prize->type->value,
            'variant' => $prize->variant?->value,
            'decision' => $decision->value,
            'refund' => PrizeDecision::Scrap === $decision ? $refund->toArray() : null,
            'lost' => $cargoLost->isZero() ? null : $cargoLost->toArray(),
            'technology' => $technology?->value,
        ]);

        return ['decision' => $decision->value, 'prize' => $prize, 'ship' => $ship, 'refund' => $refund, 'cargoLost' => $cargoLost, 'technology' => $technology];
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
     * Pełna bandera akwenu danej mapy: wszystkie jego wyspy (z przystanią)
     * pod banderą kapitana. Daje przewagę w bitwie obronnej wyspy tego akwenu
     * i znika z utratą którejkolwiek jego wyspy (#79). Liczy się osobno na
     * każdym akwenie (#109): bandera Morza Rozbitków nie pomaga na Oceanie.
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
     * @param list<string>            $battleShipIds
     * @param list<int>               $sunkLengths
     * @param array<int, ShipVariant> $sunkVariants
     *
     * @return array{0: list<string>, 1: list<string>} [stracone typy, uszkodzone typy]
     */
    private function applyLosses(array $battleShipIds, string $result, array $sunkLengths, array $sunkVariants = []): array
    {
        $lost = [];
        $damaged = [];
        $processed = [];

        foreach ($sunkLengths as $i => $length) {
            $variant = $sunkVariants[$i] ?? null;
            foreach ($battleShipIds as $shipId) {
                if (isset($processed[$shipId])) {
                    continue;
                }
                $ship = $this->shipById($shipId);
                if (null === $ship || $ship->type->length() !== $length || $ship->isDamaged()) {
                    continue;
                }
                // zatopiony zwiadowca nie uszkadza niszczyciela torpedowego z tego samego składu
                if (null !== $variant && $ship->variant !== $variant) {
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

    // --- Dziennik kapitana (#111) ---

    /** @return list<LogEntry> ostatnie zdarzenia, najstarsze pierwsze */
    public function log(): array
    {
        return $this->log;
    }

    /**
     * Dopisuje zdarzenie do dziennika. Kontrataki i badania liczą się leniwie,
     * więc wpis może dotyczyć tiku wcześniejszego niż ostatni — trafia za
     * wpisy z tego samego albo wcześniejszego tiku. Nadmiar ponad LOG_LIMIT
     * odpada od najstarszych.
     *
     * @param array<string, mixed> $data
     */
    private function record(int $tick, LogEvent $event, array $data = []): void
    {
        $at = count($this->log);
        while ($at > 0 && $this->log[$at - 1]->tick > $tick) {
            --$at;
        }
        array_splice($this->log, $at, 0, [new LogEntry($tick, $event, array_filter($data, static fn ($v) => null !== $v))]);
        $this->log = array_slice($this->log, -self::LOG_LIMIT);
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

    /**
     * Stan badań do snapshotu.
     *
     * @return array{researched: list<string>, current: array{technology: string, island: string, progress: int, countedTo: int}|null}
     */
    public function researchState(): array
    {
        return [
            'researched' => array_map(static fn (Technology $t) => $t->value, $this->researched),
            'current' => null !== $this->research ? ['technology' => $this->research['technology']->value] + $this->research : null,
        ];
    }

    /** Surowce, których ensureWorldState() jeszcze nie rozłożyło; null = brak. */
    public function unplacedResources(): ?Resources
    {
        return $this->unplacedResources;
    }

    /** @return array<string, array{island: string, settled: bool, result: ?string, kind?: string, ships?: list<string>, enemySunk?: int, stronghold?: bool}> */
    public function battles(): array
    {
        return $this->battles;
    }

    /**
     * @param array<string, array{island: string, settled: bool, result: ?string, kind?: string, ships?: list<string>, enemySunk?: int, stronghold?: bool}> $battles
     * @param list<OwnedShip>|null                                                                                                                          $fleet        null = profil sprzed ekonomii — dostaje flotę startową
     * @param Resources|null                                                                                                                                $cargo        null = profil sprzed ładowni (patrz $unplaced) albo sprzed ekonomii — drewno startowe do magazynu wyspy
     * @param int|null                                                                                                                                      $worldSeed    null = seed wyprowadzony z id (stabilny dla starych profili)
     * @param array{x:int,y:int}|null                                                                                                                       $position     pozycja na akwenie $sea; null = start ustali ensureWorldState
     * @param list<string>|null                                                                                                                             $discovered   sektory "x:y" akwenu $sea
     * @param int|null                                                                                                                                      $tick         null = profil sprzed tiku: rejsy + zarejestrowane bitwy
     * @param list<string>|null                                                                                                                             $visited      sektory akwenu $sea; null = profil sprzed odwiedzin: tylko obecna pozycja
     * @param array<string, Resources>                                                                                                                      $stockpiles   magazyny wysp
     * @param Resources|null                                                                                                                                $unplaced     surowce czekające na magazyn wyspy (też jeden magazyn profilu sprzed ładowni) — rozłożone przy ensureWorldState()
     * @param array<string, int>                                                                                                                            $depositTicks tik ostatniego naliczenia urobku złoża (klucz: id wyspy)
     * @param array<string, int>                                                                                                                            $ownedIslands wyspy pod banderą: id wyspy → tik zdobycia
     * @param array<string, list<BuildingType>>                                                                                                             $buildings    budynki osady (klucz: id wyspy)
     * @param list<Technology>                                                                                                                              $researched   zbadane technologie
     * @param array{technology: Technology, island: string, progress: int, countedTo: int}|null                                                             $research     badanie w toku
     * @param string|null                                                                                                                                   $sea          akwen floty; null = profil sprzed akwenów: Morze Rozbitków
     * @param array<string, SeaState>                                                                                                                       $seaStates    stan pozostałych akwenów (klucz: id akwenu); stan akwenu $sea dają $position, $discovered i $visited
     * @param array<string, int>                                                                                                                            $openedGates  bramy otwarte na stałe: id bramy → tik otwarcia
     * @param list<LogEntry>                                                                                                                                $log          dziennik kapitana, najstarsze pierwsze; profil sprzed dziennika zaczyna od pustego
     * @param list<Prize>                                                                                                                                   $prizes       statki zdobyte abordażem, czekające na decyzję (#118)
     * @param array<string, int>                                                                                                                            $besieged     oblężone twierdze: id wyspy → tik wygranej potyczki (#120)
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
        array $researched = [],
        ?array $research = null,
        ?string $sea = null,
        array $seaStates = [],
        array $openedGates = [],
        array $log = [],
        array $prizes = [],
        array $besieged = [],
    ): self {
        $self = new self($id, $name, max(0, $xp));
        $self->battles = $battles;
        $self->fleet = $fleet ?? self::startingFleet();
        $self->stockpiles = $stockpiles;
        $self->depositTicks = $depositTicks;
        $self->ownedIslands = $ownedIslands;
        $self->buildings = $buildings;
        $self->researched = $researched;
        $self->research = $research;
        $self->unplacedResources = $unplaced ?? (null === $cargo ? new Resources(wood: self::STARTING_WOOD) : null);
        // ładunek ponad pojemność (np. zapisany, zanim tratwa straciła ładownię)
        // nie przepada — czeka na magazyn wyspy
        [$self->cargo, $overflow] = ($cargo ?? Resources::none())->fitInto($self->cargoCapacity());
        if (!$overflow->isZero()) {
            $self->unplacedResources = ($self->unplacedResources ?? Resources::none())->add($overflow);
        }
        $self->worldSeed = $worldSeed ?? (crc32((string) $id) & 0x7FFFFFFF);
        $self->sea = $sea ?? Sea::START;
        $self->seaStates = $seaStates;
        $self->openedGates = $openedGates;
        // zdobyta twierdza nie bywa oblężona
        $self->besieged = array_diff_key($besieged, $ownedIslands);
        foreach ($prizes as $prize) {
            $self->prizes[$prize->id] = $prize;
        }
        usort($log, static fn (LogEntry $a, LogEntry $b) => $a->tick <=> $b->tick);
        $self->log = array_slice($log, -self::LOG_LIMIT);
        // profil sprzed odwiedzin: znamy tylko obecną pozycję, trasy z przeszłości nie
        $self->seaStates[$self->sea] = new SeaState(
            $position,
            $discovered ?? [],
            $visited ?? (null !== $position ? ["{$position['x']}:{$position['y']}"] : []),
        );
        $self->moveCount = max(0, $moveCount);
        // profil sprzed tiku: rejsy + zarejestrowane bitwy, żeby zegar nie cofnął się względem historii
        $self->tick = max(0, $tick ?? $self->moveCount + count($battles));
        $self->counterattack = $counterattacks['pending'] ?? null;
        $self->counterattackCalmFrom = $counterattacks['calmFrom'] ?? max([0, ...array_values($ownedIslands)]);
        $self->counterattackRolledTo = $counterattacks['rolledTo'] ?? $self->tick;
        $self->lastCounterattack = $counterattacks['last'] ?? null;

        return $self;
    }
}
