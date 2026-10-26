<?php

namespace App\Domain\Expedition;

use App\Domain\Game\Orientation;
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

    /** Zapisanych zestawów floty (#125) na profil. */
    public const FLEET_PRESET_LIMIT = 5;

    /** Kuter remontowy (#152) naprawia poza stocznią tylko kadłuby do tylu pól. */
    public const FIELD_REPAIR_MAX_LENGTH = 3;

    /** Dziennik kapitana (#111) trzyma tyle ostatnich wpisów. */
    public const LOG_LIMIT = 50;

    /** Pojemne ładownie (#96): ładownia +50% bazowej. */
    public const LARGE_HOLDS_CARGO_BONUS_PERCENT = 50;

    /** Perki ekonomiczne (#162, D4 Epica K): ładownia, ceny, budowa, magazyn złóż. */
    public const LARGER_HOLD_PERK_PERCENT = 10;
    public const MARKET_DISCOUNT_PERK_PERCENT = 5;
    public const MASTER_TRADER_PERK_PERCENT = 10;
    public const CHEAPER_SHIPBUILDING_PERK_PERCENT = 10;
    public const DEPOSIT_STORAGE_PERK_TICKS = 5;

    /** Perki bojowe (#161, D4 Epica K): remont statku uszkodzonego w wygranej bitwie, łup z bitew. */
    public const REPAIR_DISCOUNT_PERK_PERCENT = 20;
    public const LOOT_BONUS_PERK_PERCENT = 20;

    /** @var array<string, array{island: string, settled: bool, result: ?string, kind?: string, ships?: list<string>, enemySunk?: int, stronghold?: bool, encounter?: string, encounterAt?: array{tick:int, x:int, y:int}, raidPenalty?: int}> klucz: gameId */
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
     * Mapy akwenów (#143, Epic J D5): przedmiot w profilu, warunek bramy
     * „wody nieznane”. Mapy nie zużywa się i nie traci.
     *
     * @var array<string, int> klucz: id akwenu, do którego prowadzi mapa; wartość: tik zdobycia
     */
    private array $seaCharts = [];

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

    /**
     * Zapisane zestawy floty z rozmieszczeniem (#125), najwyżej FLEET_PRESET_LIMIT.
     *
     * @var array<string, FleetPreset> klucz: id zestawu
     */
    private array $fleetPresets = [];

    /** Tik = akcja morska (rejs, bitwa); zegar świata — GAME_DESIGN.md, filar II. */
    private int $tick = 0;

    /** Spotkanie z rejsu czekające na decyzję (#141); null = droga wolna. */
    private ?Encounter $encounter = null;

    /** Naprawy kutrów remontowych poza stocznią (#152); kapitan wyłącza je, żeby oszczędzać ładunek. */
    private bool $fieldRepairs = true;

    /** Ekspedycja w głąb dzikiej wyspy w toku (#144); null = żadna nie trwa. */
    private ?InlandExpedition $inland = null;

    /**
     * Koniec ostatniej ekspedycji na każdej dzikiej wyspie (#144): od niego
     * wyspa odpoczywa WildIsland::REST_TICKS tików.
     *
     * @var array<string, int> klucz: id dzikiej wyspy, wartość: tik końca
     */
    private array $wildVisits = [];

    /**
     * Technologie z artefaktów i legendy z ekspedycji (#144): leżą też
     * w $researched, tu jest tylko ścieżka questowa dla drzewka.
     *
     * @var array<string, int> klucz: id technologii, wartość: tik znalezienia
     */
    private array $artifacts = [];

    /** Reputacja na osi kupiec ↔ korsarz (#142): ceny rynku, embargo i łowcy nagród. */
    private Reputation $reputation;

    /** Liczniki akcji specjalizacji bojowej i ekonomicznej (Epic K, #160). */
    private Specializations $specializations;

    /**
     * Pochodzenie ładunku do punktów za transport (#160): ile jednostek
     * załadowano na danej wyspie i nadal jest w ładowni. Ładunek bez
     * pochodzenia (łup, kartografia, handel) nie ma wpisu. Suma nie
     * przekracza ładowni: straty zabierają najpierw ładunek bez pochodzenia.
     *
     * @var array<string, int> klucz: id wyspy (albo portu handlowego)
     */
    private array $cargoOrigins = [];

    private function __construct(
        private ProfileId $id,
        private string $name,
        private int $xp = 0,
    ) {
        $this->reputation = new Reputation();
        $this->specializations = new Specializations();
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

    /**
     * Ładownia statku danego typu: premie w procentach ładowni bazowej się
     * sumują — Pojemne ładownie +50%, perk Większa ładownia +10% (#162) —
     * z zaokrągleniem w dół.
     */
    public function shipCargoCapacity(ShipType $type): int
    {
        $percent = 100
            + ($this->hasResearched(Technology::PojemneLadownie) ? self::LARGE_HOLDS_CARGO_BONUS_PERCENT : 0)
            + ($this->hasPerk(Perk::LargerHold) ? self::LARGER_HOLD_PERK_PERCENT : 0);

        return intdiv($type->cargoCapacity() * $percent, 100);
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
        $this->setCargo($this->cargo->add($amount));
        $this->cargoOrigins[$islandId] = ($this->cargoOrigins[$islandId] ?? 0) + $amount->total();
        $this->passTicks();
    }

    /**
     * Rozładunek z ładowni do magazynu wyspy. Dokowanie kosztuje tik.
     * Jednostki załadowane na innych wyspach to faktyczny transport (#160):
     * rozładunek zdejmuje najpierw je, potem ładunek z tej wyspy.
     */
    public function unloadCargo(string $islandId, Resources $amount): void
    {
        if ($amount->isZero()) {
            throw new \InvalidArgumentException('Nothing to transfer');
        }

        $cargo = $this->cargo->subtract($amount);
        // pochodzenie przed przycięciem w setCargo(), które nie wie, gdzie się rozładowuje
        $transported = $this->takeCargoOrigins($amount->total(), $islandId);
        $this->setCargo($cargo);
        $this->stockpiles[$islandId] = $this->stockpile($islandId)->add($amount);
        $this->passTicks();
        $this->earn(SpecializationSource::Transport, $transported);
    }

    /**
     * Zdejmuje rozładowane jednostki z pochodzenia: najpierw z innych wysp,
     * potem z wyspy rozładunku (reszta to ładunek bez pochodzenia).
     *
     * @return int jednostki przewiezione z innych wysp
     */
    private function takeCargoOrigins(int $units, string $islandId): int
    {
        $transported = 0;
        foreach ($this->cargoOrigins as $origin => $held) {
            if ($origin === $islandId || 0 === $units) {
                continue;
            }
            $taken = min($held, $units);
            $this->cargoOrigins[$origin] -= $taken;
            $units -= $taken;
            $transported += $taken;
        }
        if (isset($this->cargoOrigins[$islandId])) {
            $this->cargoOrigins[$islandId] -= min($this->cargoOrigins[$islandId], $units);
        }
        $this->cargoOrigins = array_filter($this->cargoOrigins);

        return $transported;
    }

    /**
     * Jedyne miejsce zmiany ładowni: gdy ładunku jest mniej niż jednostek
     * z pochodzeniem, nadwyżka pochodzenia przepada od najstarszych wpisów.
     * Strata zabiera więc najpierw ładunek bez pochodzenia.
     */
    private function setCargo(Resources $cargo): void
    {
        $this->cargo = $cargo;
        $excess = array_sum($this->cargoOrigins) - $cargo->total();
        foreach ($this->cargoOrigins as $origin => $held) {
            if ($excess <= 0) {
                break;
            }
            $taken = min($held, $excess);
            $this->cargoOrigins[$origin] -= $taken;
            $excess -= $taken;
        }
        $this->cargoOrigins = array_filter($this->cargoOrigins);
    }

    /** @return array<string, int> jednostki ładowni załadowane na danej wyspie (klucz: id wyspy) */
    public function cargoOrigins(): array
    {
        return $this->cargoOrigins;
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
        $this->setCargo($this->cargo->subtract($cost->subtract($fromStock)));
    }

    /** Ładunek ponad pojemność (po utracie statku) przepada; zwraca, co przepadło. */
    private function trimCargo(): Resources
    {
        [$kept, $overflow] = $this->cargo->fitInto($this->cargoCapacity());
        $this->setCargo($kept);

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
        // (kuter remontowy bez broni, #152, płynie razem z tratwami)
        $priority = [ShipType::Lotniskowiec, ShipType::Niszczyciel, ShipType::Kuter, ShipType::Tratwa];
        $rank = static fn (OwnedShip $s) => $s->isArmed() ? array_search($s->type, $priority, true) : count($priority);
        $candidates = array_values(array_filter($this->activeFleet(), static fn (OwnedShip $s) => in_array($s->type, $priority, true)));
        usort($candidates, static fn (OwnedShip $a, OwnedShip $b) => $rank($a) <=> $rank($b));

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
     * wozi ładunek); eskortuje go sprawny statek z bronią (kuter remontowy jej nie ma, #152). Reguła nie dotyczy
     * floty bez transportowca, więc same tratwy nadal walczą (bezpiecznik
     * anty-softlock).
     *
     * @param list<OwnedShip> $ships
     */
    public static function isUnescortedConvoy(array $ships): bool
    {
        $hasTransport = false;
        foreach ($ships as $ship) {
            if ($ship->isArmed() && !$ship->isDamaged()) {
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
        $this->pay($islandId, $this->shipBuildCost($type));

        $ship = OwnedShip::build($type, $variant);
        $this->fleet[] = $ship;
        $this->record($this->tick, LogEvent::ShipBuilt, ['ship' => $type->value, 'variant' => $ship->variant?->value, 'island' => $islandId]);

        return $ship;
    }

    /**
     * Koszt budowy statku dla kapitana: koszt typu, z perkiem Tańsza budowa
     * (#162) −10% każdego surowca, z zaokrągleniem w dół. Złom liczy się dalej
     * od kosztu typu.
     */
    public function shipBuildCost(ShipType $type): Resources
    {
        $cost = $type->buildCost();
        if (!$this->hasPerk(Perk::CheaperShipbuilding)) {
            return $cost;
        }
        $percent = 100 - self::CHEAPER_SHIPBUILDING_PERK_PERCENT;

        return Resources::fromArray(array_map(static fn (int $amount) => intdiv($amount * $percent, 100), $cost->toArray()));
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

        $this->pay($islandId, $this->repairCostOf($ship));
        $ship->repair();

        return $ship;
    }

    /**
     * Remont całej floty w stoczni (#151): uszkodzone statki od najtańszego,
     * każdy płacony jak pojedynczy remont (magazyn wyspy, potem ładownia).
     * Statek, którego stocznia nie wyremontuje albo na który zabrakło surowców,
     * zostaje uszkodzony, a remont idzie dalej — droższy statek może kosztować
     * inne surowce. Obecność przy wyspie sprawdza warstwa aplikacji; akcja nie kosztuje tiku.
     */
    public function repairFleet(string $islandId, int $shipyardLevel): FleetRepair
    {
        $before = $this->stockpile($islandId)->add($this->cargo);
        $repaired = [];
        $skipped = [];
        foreach ($this->repairQueue() as $ship) {
            if ($shipyardLevel < $ship->type->requiredShipyardLevel()) {
                $skipped[] = ['ship' => $ship, 'reason' => RepairSkip::ShipyardTooLow];
                continue;
            }
            if (!$this->stockpile($islandId)->add($this->cargo)->covers($this->repairCostOf($ship))) {
                $skipped[] = ['ship' => $ship, 'reason' => RepairSkip::NotEnoughResources];
                continue;
            }
            $this->pay($islandId, $this->repairCostOf($ship));
            $ship->repair();
            $repaired[] = $ship;
        }

        return new FleetRepair($repaired, $skipped, $before->subtract($this->stockpile($islandId)->add($this->cargo)));
    }

    /**
     * Uszkodzone statki w kolejności remontu: od najtańszego (suma surowców
     * z Dokiem remontowym), przy równym koszcie w kolejności floty.
     *
     * @return list<OwnedShip>
     */
    public function repairQueue(): array
    {
        $damaged = array_values(array_filter($this->fleet, static fn (OwnedShip $s) => $s->isDamaged()));
        usort($damaged, fn (OwnedShip $a, OwnedShip $b) => $this->repairCostOf($a)->total() <=> $this->repairCostOf($b)->total());

        return $damaged;
    }

    public function fieldRepairsEnabled(): bool
    {
        return $this->fieldRepairs;
    }

    /** Przełącznik napraw kutrów remontowych poza stocznią (#152). */
    public function setFieldRepairs(bool $enabled): void
    {
        $this->fieldRepairs = $enabled;
    }

    /**
     * Upływ czasu: każdy tik (rejs, przeładunek, start bitwy, handel) daje
     * kutrom remontowym okazję do napraw (#152).
     */
    private function passTicks(int $ticks = 1): void
    {
        for ($i = 0; $i < $ticks; ++$i) {
            ++$this->tick;
            $this->repairInField();
        }
    }

    /**
     * Naprawy poza stocznią (#152): każdy sprawny kuter remontowy naprawia na
     * tiku jeden uszkodzony statek do FIELD_REPAIR_MAX_LENGTH pól, od
     * najmniejszego, za koszt remontu (repairCost) z ładowni. Statek, na który
     * brakuje surowców w ładowni, czeka; kuter próbuje następnego. Uszkodzony
     * kuter nie naprawia, także siebie — może go naprawić inny kuter. Bitwa
     * nie tyka, więc w jej trakcie nic się nie naprawia.
     */
    private function repairInField(): void
    {
        if (!$this->fieldRepairs) {
            return;
        }
        $repairers = count(array_filter($this->fleet, static fn (OwnedShip $s) => $s->isFieldRepairer() && !$s->isDamaged()));
        $queue = array_values(array_filter(
            $this->repairQueue(),
            static fn (OwnedShip $s) => $s->type->length() <= self::FIELD_REPAIR_MAX_LENGTH,
        ));
        // od najmniejszego kadłuba; przy równej długości kolejność remontu
        usort($queue, static fn (OwnedShip $a, OwnedShip $b) => $a->type->length() <=> $b->type->length());

        foreach ($queue as $ship) {
            if (0 === $repairers) {
                return;
            }
            $cost = $this->repairCostOf($ship);
            if (!$this->cargo->covers($cost)) {
                continue;
            }
            $this->setCargo($this->cargo->subtract($cost));
            $ship->repair();
            --$repairers;
            $this->record($this->tick, LogEvent::FieldRepair, ['ship' => $ship->type->value, 'variant' => $ship->variant?->value, 'paid' => $cost->toArray()]);
        }
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

    /**
     * Koszt remontu tego egzemplarza: koszt typu (z Dokiem remontowym), a dla
     * statku uszkodzonego w wygranej bitwie z perkiem „remont po wygranej”
     * mniej o REPAIR_DISCOUNT_PERK_PERCENT, z zaokrągleniem w dół (#161).
     */
    public function repairCostOf(OwnedShip $ship): Resources
    {
        $cost = $this->repairCost($ship->type);
        if (!$ship->isBattleDamaged() || !$this->hasPerk(Perk::RepairDiscount)) {
            return $cost;
        }

        return Resources::fromArray(array_map(
            static fn (int $amount) => intdiv($amount * (100 - self::REPAIR_DISCOUNT_PERK_PERCENT), 100),
            $cost->toArray(),
        ));
    }

    /**
     * Zwrot ze złomowania (#124): połowa kosztu budowy typu (wariant kosztuje
     * tyle co typ), a za uszkodzony statek mniej o koszt remontu z Dokiem
     * remontowym — każdy surowiec nie mniej niż zero. Tratwa nic nie zwraca.
     */
    public function scrapValue(OwnedShip $ship): Resources
    {
        $value = $ship->type->scrapValue()->toArray();
        if ($ship->isDamaged()) {
            $repair = $this->repairCostOf($ship)->toArray();
            foreach ($value as $resource => $amount) {
                $value[$resource] = max(0, $amount - $repair[$resource]);
            }
        }

        return Resources::fromArray($value);
    }

    /** Powód, dla którego statku nie da się zezłomować; null = można (stocznię sprawdza warstwa aplikacji). */
    public function scrapBlocker(OwnedShip $ship): ?ScrapBlock
    {
        foreach ($this->battles as $battle) {
            if (!$battle['settled'] && in_array($ship->id, $battle['ships'] ?? [], true)) {
                return ScrapBlock::InBattle;
            }
        }
        if (!$ship->isDamaged() && 1 === count($this->activeFleet())) {
            return ScrapBlock::LastSeaworthy;
        }

        return null;
    }

    /**
     * Złomuje statek w stoczni wyspy (obecność i stocznię sprawdza warstwa
     * aplikacji). Zwrot trafia do magazynu wyspy, a akcja nie kosztuje tiku.
     * Ładunek, który nie mieści się już w ładowni, kapitan zostawia w magazynie.
     */
    public function scrapShip(string $shipId, string $islandId): Resources
    {
        $ship = $this->shipById($shipId) ?? throw new \DomainException('Ship not found');
        $block = $this->scrapBlocker($ship);
        if (null !== $block) {
            throw new \DomainException($block->message());
        }

        $refund = $this->scrapValue($ship);
        $this->fleet = array_values(array_filter($this->fleet, static fn (OwnedShip $s) => $s->id !== $shipId));
        // ładunek ponad pojemność floty bez złomowanego transportowca zostaje w porcie
        $unloaded = $this->trimCargo();
        $this->stockpiles[$islandId] = $this->stockpile($islandId)->add($refund)->add($unloaded);
        $this->record($this->tick, LogEvent::ShipScrapped, [
            'ship' => $ship->type->value,
            'variant' => $ship->variant?->value,
            'island' => $islandId,
            'damaged' => $ship->isDamaged() ?: null,
            'refund' => $refund->toArray(),
            'unloaded' => $unloaded->isZero() ? null : $unloaded->toArray(),
        ]);

        return $refund;
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

    /**
     * Ile urobku może czekać w magazynie wyspy: limit rośnie razem z urobkiem,
     * Spichlerze go podwajają, perk Magazyn złóż (#162) dokłada tiki produkcji.
     */
    public function depositCap(Island $island, SeaTheme $theme = new SeaTheme()): Resources
    {
        $bufferTicks = $this->hasResearched(Technology::Spichlerze) ? Island::DEPOSIT_BUFFER_TICKS_GRANARY : Island::DEPOSIT_BUFFER_TICKS;
        if ($this->hasPerk(Perk::DepositStorage)) {
            $bufferTicks += self::DEPOSIT_STORAGE_PERK_TICKS;
        }

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
     * technologia ze zdobyczy albo z questu (nie bada się ich na uczelni, #121, #144),
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
        if (TechUnlock::Quest === $technology->unlock()) {
            return ResearchBlock::QuestOnly;
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

    /**
     * Promień odkrywania wokół floty: z motywu akwenu, a legenda „Czarna perła”
     * (#144) dokłada 1 na wszystkich akwenach (na Morzu Mgieł 1 zamiast 0).
     */
    public function visibility(SeaTheme $theme = new SeaTheme()): int
    {
        return $theme->visibility + ($this->hasResearched(Technology::CzarnaPerla) ? 1 : 0);
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
        // urobek odłożony w magazynie (limit już przycięty) to punkty ekonomiczne (#160)
        $this->earn(SpecializationSource::Mining, array_sum($output));
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
        $this->setCargo($this->cargo->add($fit));
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
     * Rejs bez sztormu może skończyć się spotkaniem z handlarzem albo
     * piratem (#141); dopóki kapitan nie zdecyduje, flota nie płynie dalej.
     *
     * @return array{discoveredNow: list<string>, cartography: int, cartographyLost: int, event: array<string,mixed>|null, encounter: array{type:string, tick:int, sea:string, x:int, y:int}|null}
     */
    public function sail(int $x, int $y, WorldMap $world): array
    {
        $this->ensureWorldState($world);
        if (null !== $this->encounter) {
            throw new \DomainException('Encounter pending');
        }
        if (null !== $this->inland) {
            throw new \DomainException('Inland expedition in progress');
        }

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
        $this->passTicks();

        // rejs o 2 sektory odkrywa też sektor po drodze: krok od startu w stronę
        // celu (przy ruchu 2+1 po skosie). Przy widoczności 1 leży on i tak
        // w sąsiedztwie celu; liczy się na Morzu Mgieł z widocznością 0 (#108)
        $passed = 2 === $distance
            ? [[$position['x'] + ($x <=> $position['x']), $position['y'] + ($y <=> $position['y'])]]
            : [];

        $outcome = $this->arrive($x, $y, $world, $passed);
        // w jednym rejsie najwyżej jedno zdarzenie: sztorm albo spotkanie
        $outcome['encounter'] = null === $outcome['event'] ? $this->rollEncounter($x, $y, $world)?->toArray() : null;

        return $outcome;
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
        $this->setCargo($this->cargo->add($fit));
        $this->earn(SpecializationSource::Discovery, count($discoveredNow));

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
        $this->setCargo($this->cargo->subtract($lost));

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
     *                                  kapitana na akwenie (1 = jak przed akwenami, 0 = tylko sektor) w granicach mapy
     */
    private function neighborhood(int $x, int $y, WorldMap $world): array
    {
        $radius = $this->visibility($world->theme());
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
        $this->passTicks();
        $this->record($this->tick, LogEvent::GatePassed, ['gate' => $passage->gate->id, 'from' => $passage->from, 'to' => $passage->to]);
        $this->sea = $passage->to;
        $outcome = $this->arrive($arrival['x'], $arrival['y'], $to);
        // urobek, badanie i kontrataki akwenu docelowego do bieżącego tiku
        $this->ensureWorldState($to);

        return $outcome;
    }

    // --- Port handlowy i rynek (#140) ---

    /** Czy flota stoi na sektorze portu handlowego bieżącego akwenu. */
    public function isAtTradePort(WorldMap $world): bool
    {
        $this->ensureWorldState($world);
        $pos = $world->tradePortPosition();

        return null !== $pos && $pos === $this->position();
    }

    /**
     * Mnożnik ceny rynku w procentach (100 = kurs bazowy akwenu): reputacja
     * kupiec ↔ korsarz (#142) razy perk ceny (#162), z zaokrągleniem w dół.
     * Jedyne miejsce, gdzie te dwa mnożniki się łączą — z niego biorą rynek,
     * sprzedaż statku, mapa akwenu i handlarz.
     */
    public function marketPricePercent(): int
    {
        return max(1, intdiv($this->reputation->pricePercent() * $this->marketPerkPercent(), MarketRate::BASE_PRICE_PERCENT));
    }

    /** Mnożnik ceny z perków ekonomicznych (#162): 100, od poziomu 2 95, od poziomu 5 90. */
    public function marketPerkPercent(): int
    {
        return MarketRate::BASE_PRICE_PERCENT - match (true) {
            $this->hasPerk(Perk::MasterTrader) => self::MASTER_TRADER_PERK_PERCENT,
            $this->hasPerk(Perk::MarketDiscount) => self::MARKET_DISCOUNT_PERK_PERCENT,
            default => 0,
        };
    }

    /**
     * Kupno albo sprzedaż towaru za złoto (obecność w porcie sprawdza warstwa
     * aplikacji): sprzedaż w porcjach po 1 złoto, kupno liczone od całego
     * zlecenia (#142). Handel podnosi reputację, embargo go blokuje. Płaci się z magazynu portu, a brakującą
     * resztę bierze z ładowni; to, co kapitan dostaje, trafia do ładowni.
     * Transakcja bez środków albo bez miejsca w ładowni jest odrzucana.
     * Kosztuje tik jak przeładunek.
     *
     * @return array{paid: Resources, received: Resources}
     */
    public function trade(TradePort $port, MarketSide $side, MarketGood $good, int $gold): array
    {
        if ($gold < 1) {
            throw new \InvalidArgumentException('Trade amount must be positive');
        }
        $this->assertNoEmbargo();
        $percent = $this->marketPricePercent();
        $coins = new Resources(gold: $gold);
        [$paid, $received] = match ($side) {
            MarketSide::Buy => [$coins, $good->resources($port->rate->buyTotal($good, $gold, $percent))],
            MarketSide::Sell => [$good->resources($gold * $port->rate->sellAmount($good, $percent)), $coins],
        };

        $stock = $this->stockpile($port->id);
        if (!$stock->add($this->cargo)->covers($paid)) {
            throw new \DomainException('Not enough resources');
        }
        // płatność najpierw z magazynu: z ładowni ubywa tylko reszta
        $fromCargo = $paid->subtract(new Resources(min($stock->wood, $paid->wood), min($stock->steel, $paid->steel), min($stock->gold, $paid->gold)));
        if ($this->cargo->total() - $fromCargo->total() + $received->total() > $this->cargoCapacity()) {
            throw new \DomainException('Cargo capacity exceeded');
        }

        $this->pay($port->id, $paid);
        $this->setCargo($this->cargo->add($received));
        $this->passTicks();
        $this->record($this->tick, LogEvent::MarketTrade, [
            'island' => $port->id,
            'side' => $side->value,
            'good' => $good->value,
            'paid' => $paid->toArray(),
            'received' => $received->toArray(),
        ]);
        $this->changeReputation(Reputation::TRADE);
        $this->earn(SpecializationSource::MarketTurnover, $gold);

        return ['paid' => $paid, 'received' => $received];
    }

    /**
     * Ile złota da sprzedaż statku: zwrot jak przy złomowaniu (#124), a drewno
     * i stal przeliczone po kursie sprzedaży rynku akwenu.
     */
    public function shipSaleValue(OwnedShip $ship, MarketRate $rate): int
    {
        return $rate->goldValue($this->scrapValue($ship), $this->marketPricePercent());
    }

    /**
     * Sprzedaje statek za złoto w porcie handlowym (obecność sprawdza warstwa
     * aplikacji); stocznia nie jest potrzebna. Blokady jak przy złomowaniu.
     * Złoto trafia do ładowni, która po sprzedaży musi pomieścić cały ładunek —
     * inaczej transakcja jest odrzucana. Kosztuje tik.
     */
    public function sellShip(string $shipId, TradePort $port): int
    {
        $ship = $this->shipById($shipId) ?? throw new \DomainException('Ship not found');
        $this->assertNoEmbargo();
        $block = $this->scrapBlocker($ship);
        if (null !== $block) {
            throw new \DomainException($block->message());
        }

        $gold = $this->shipSaleValue($ship, $port->rate);
        $capacityAfter = $this->cargoCapacity() - $this->shipCargoCapacity($ship->type);
        if ($this->cargo->total() + $gold > $capacityAfter) {
            throw new \DomainException('Cargo capacity exceeded');
        }

        $this->fleet = array_values(array_filter($this->fleet, static fn (OwnedShip $s) => $s->id !== $shipId));
        $this->setCargo($this->cargo->add(new Resources(gold: $gold)));
        $this->passTicks();
        $this->record($this->tick, LogEvent::ShipSold, [
            'ship' => $ship->type->value,
            'variant' => $ship->variant?->value,
            'island' => $port->id,
            'damaged' => $ship->isDamaged() ?: null,
            'gold' => $gold,
        ]);

        return $gold;
    }

    // --- Mapy akwenów (#143) ---

    public function hasSeaChart(string $seaId): bool
    {
        return isset($this->seaCharts[$seaId]);
    }

    /** @return array<string, int> mapy akwenów: id akwenu → tik zdobycia */
    public function seaCharts(): array
    {
        return $this->seaCharts;
    }

    /**
     * Cena mapy z oferty portu w złocie, z mnożnikiem ceny rynku kapitana
     * (reputacja z #142 zmieni ją tak jak kursy towarów).
     */
    public function chartPrice(SeaChartOffer $offer): int
    {
        return $offer->priceFor($this->marketPricePercent());
    }

    /**
     * Kupno mapy akwenu na rynku portu handlowego (obecność w porcie sprawdza
     * warstwa aplikacji). Złoto najpierw z magazynu portu, reszta z ładowni.
     * Mapa nie zajmuje miejsca w ładowni. Kosztuje tik jak transakcja rynku.
     *
     * @return int zapłacone złoto
     */
    public function buySeaChart(TradePort $port): int
    {
        $offer = $port->chart ?? throw new \DomainException('No sea chart for sale here');
        $this->assertNoEmbargo();
        if ($this->hasSeaChart($offer->seaId)) {
            throw new \DomainException('Sea chart already owned');
        }
        $price = $this->chartPrice($offer);
        $this->pay($port->id, new Resources(gold: $price));
        $this->passTicks();
        $this->grantSeaChart($offer->seaId, SeaChartSource::Market, ['island' => $port->id, 'gold' => $price]);

        return $price;
    }

    /**
     * Przyznaje mapę akwenu (#143): zakup na rynku albo nagroda z ekspedycji
     * (#144 woła to z SeaChartSource::Expedition). Mapy nie ma w łupach
     * z bitew. Nie kosztuje tiku — tik liczy akcja, która mapę daje.
     *
     * @param array<string, mixed> $logData dodatkowe dane wpisu w dzienniku (np. port i cena)
     *
     * @return bool false = kapitan już ma tę mapę (nic się nie zmienia)
     */
    public function grantSeaChart(string $seaId, SeaChartSource $source, array $logData = []): bool
    {
        if ('' === $seaId) {
            throw new \InvalidArgumentException('Sea chart needs a sea');
        }
        if ($this->hasSeaChart($seaId)) {
            return false;
        }
        $this->seaCharts[$seaId] = $this->tick;
        $this->record($this->tick, LogEvent::ChartAcquired, ['sea' => $seaId, 'source' => $source->value] + $logData);

        return true;
    }

    // --- Ekspedycje w głąb dzikich wysp (#144) ---

    /** Ekspedycja w toku; null = żadna nie trwa. */
    public function inlandExpedition(): ?InlandExpedition
    {
        return $this->inland;
    }

    /** @return array<string, int> koniec ostatniej ekspedycji: id dzikiej wyspy → tik */
    public function wildVisits(): array
    {
        return $this->wildVisits;
    }

    /** @return array<string, int> technologie z questu (artefakty i legenda): id technologii → tik */
    public function artifacts(): array
    {
        return $this->artifacts;
    }

    /** Czy technologia przyszła z ekspedycji (artefakt albo legenda), a nie z uczelni czy abordażu. */
    public function hasArtifact(Technology $technology): bool
    {
        return isset($this->artifacts[$technology->value]);
    }

    /** Czy flota stoi na sektorze dzikiej wyspy bieżącego akwenu. */
    public function isAtWildIsland(WorldMap $world): bool
    {
        $this->ensureWorldState($world);
        $pos = $world->wildIslandPosition();

        return null !== $pos && $pos === $this->position();
    }

    /** Tik, od którego dzika wyspa przyjmie następną ekspedycję (odpoczynek po poprzedniej). */
    public function wildIslandReadyAt(string $islandId): int
    {
        return isset($this->wildVisits[$islandId]) ? $this->wildVisits[$islandId] + WildIsland::REST_TICKS : 0;
    }

    /**
     * Dlaczego ekspedycji nie da się teraz zacząć; null = można. Kolejność:
     * obecność, trwająca ekspedycja, ranga, odpoczynek wyspy, sprawna flota
     * (porażka uszkadza statek, więc ryzyko musi coś kosztować).
     */
    public function inlandBlocker(WildIsland $island, WorldMap $world): ?InlandBlock
    {
        return match (true) {
            $world->wildIslandId() !== $island->id || !$this->isAtWildIsland($world) => InlandBlock::NotPresent,
            null !== $this->inland => InlandBlock::InProgress,
            !$island->isAccessibleFor($this->rank()) => InlandBlock::Rank,
            $this->tick < $this->wildIslandReadyAt($island->id) => InlandBlock::Resting,
            [] === $this->activeFleet() => InlandBlock::NoSeaworthyShips,
            default => null,
        };
    }

    /**
     * Wyprawa w głąb dzikiej wyspy, na której stoi flota: od razu etap 1.
     *
     * @return array{stage: int, result: string, reward: Resources, collected: Resources, damagedShip: ?string, treasure: ?array{type: string, technology?: string, sea?: string, gold?: int}, received: ?Resources, lost: ?Resources}
     */
    public function startInlandExpedition(WildIsland $island, WorldMap $world): array
    {
        $blocker = $this->inlandBlocker($island, $world);
        if (null !== $blocker) {
            throw new \DomainException($blocker->message($island));
        }
        $this->inland = new InlandExpedition($island->id, 0, Resources::none(), $this->tick);

        return $this->inlandStage($island);
    }

    /**
     * „Głębiej”: następny etap ekspedycji w toku.
     *
     * @return array{stage: int, result: string, reward: Resources, collected: Resources, damagedShip: ?string, treasure: ?array{type: string, technology?: string, sea?: string, gold?: int}, received: ?Resources, lost: ?Resources}
     */
    public function goDeeperInland(WildIsland $island, WorldMap $world): array
    {
        $this->ensureWorldState($world);
        $this->pendingInland($island);

        return $this->inlandStage($island);
    }

    /**
     * „Wracaj”: zebrane nagrody trafiają do ładowni (nadmiar ponad pojemność
     * przepada, na dzikiej wyspie nie ma magazynu). Powrót nie kosztuje tiku.
     *
     * @return array{received: Resources, lost: Resources}
     */
    public function returnFromInland(WildIsland $island): array
    {
        $inland = $this->pendingInland($island);

        return $this->finishInland($island, $inland, null);
    }

    private function pendingInland(WildIsland $island): InlandExpedition
    {
        $inland = $this->inland ?? throw new \DomainException('No inland expedition');
        if ($inland->island !== $island->id) {
            throw new \DomainException('No inland expedition');
        }

        return $inland;
    }

    /**
     * Etap ekspedycji za 1 tik: rzut ryzyka z seeda i tiku. Porażka zabiera
     * wszystko zebrane w tej ekspedycji i uszkadza jeden sprawny statek floty.
     * Udany etap dokłada nagrodę; po etapie 3 rzut na skarb i powrót.
     *
     * @return array{stage: int, result: string, reward: Resources, collected: Resources, damagedShip: ?string, treasure: ?array{type: string, technology?: string, sea?: string, gold?: int}, received: ?Resources, lost: ?Resources}
     */
    private function inlandStage(WildIsland $island): array
    {
        $inland = $this->inland ?? throw new \LogicException('No inland expedition');
        $stage = $inland->stage + 1;
        if ($stage > WildIsland::STAGES) {
            throw new \DomainException('No deeper stage');
        }
        $this->passTicks();

        if (crc32("{$this->worldSeed}:{$this->tick}:inland:{$island->id}:{$stage}") % 100 < WildIsland::risk($stage)) {
            $active = $this->activeFleet();
            $ship = [] !== $active ? $active[crc32("{$this->worldSeed}:{$this->tick}:inland-damage") % count($active)] : null;
            $ship?->markDamaged();
            $this->inland = null;
            $this->wildVisits[$island->id] = $this->tick;
            $this->record($this->tick, LogEvent::InlandFailed, [
                'island' => $island->id,
                'stage' => $stage,
                'lost' => $inland->collected->isZero() ? null : $inland->collected->toArray(),
                'ship' => $ship?->type->value,
            ]);

            return ['stage' => $stage, 'result' => 'failed', 'reward' => Resources::none(), 'collected' => Resources::none(), 'damagedShip' => $ship?->type->value, 'treasure' => null, 'received' => null, 'lost' => $inland->collected];
        }

        $reward = $island->reward($stage);
        $inland = $inland->passed($stage, $reward);
        if ($stage < WildIsland::STAGES) {
            $this->inland = $inland;

            return ['stage' => $stage, 'result' => 'passed', 'reward' => $reward, 'collected' => $inland->collected, 'damagedShip' => null, 'treasure' => null, 'received' => null, 'lost' => null];
        }

        $treasure = $this->rollTreasure($island);
        $finish = $this->finishInland($island, $inland, $treasure);

        return ['stage' => $stage, 'result' => 'completed', 'reward' => $reward, 'collected' => $inland->collected, 'damagedShip' => null, 'treasure' => $treasure, 'received' => $finish['received'], 'lost' => $finish['lost']];
    }

    /**
     * Skarb etapu 3: jeden rzut z seeda i tiku po szansach wyspy. Skarb, którego
     * kapitan już ma (mapa, legenda) albo którego nie ma z czego wylosować
     * (wszystkie kandydaci na artefakt zbadane), zamienia się w złoto.
     * Mapę przyznaje grantSeaChart, a technologia działa od razu.
     *
     * @return array{type: string, technology?: string, sea?: string, gold?: int}|null
     */
    private function rollTreasure(WildIsland $island): ?array
    {
        $kind = $island->treasureFor(crc32("{$this->worldSeed}:{$this->tick}:inland-treasure:{$island->id}") % 100);
        $data = ['island' => $island->id];

        switch ($kind) {
            case null:
                return null;
            case InlandTreasure::Chart:
                if ($this->grantSeaChart(StaticSeaGraph::BLACK_PEARL, SeaChartSource::Expedition, $data)) {
                    return ['type' => $kind->value, 'sea' => StaticSeaGraph::BLACK_PEARL];
                }
                break;
            case InlandTreasure::Legend:
                if (!$this->hasResearched(Technology::CzarnaPerla)) {
                    $this->grantArtifact(Technology::CzarnaPerla, $island);

                    return ['type' => $kind->value, 'technology' => Technology::CzarnaPerla->value];
                }
                break;
            case InlandTreasure::Artifact:
                $pool = $this->artifactCandidates();
                if ([] !== $pool) {
                    $technology = $pool[crc32("{$this->worldSeed}:{$this->tick}:artifact") % count($pool)];
                    $this->grantArtifact($technology, $island);

                    return ['type' => $kind->value, 'technology' => $technology->value];
                }
                break;
        }

        return ['type' => $kind->value, 'gold' => WildIsland::TREASURE_FALLBACK_GOLD];
    }

    /**
     * Technologie, które może dać artefakt: kandydaci z poziomów 1–2 uczelni,
     * jeszcze niezbadani i nie badani w tej chwili (badanie w toku nie przepada).
     *
     * @return list<Technology>
     */
    public function artifactCandidates(): array
    {
        $inProgress = $this->research['technology'] ?? null;

        return array_values(array_filter(
            Technology::cases(),
            fn (Technology $t) => $t->isArtifactCandidate() && !$this->hasResearched($t) && $t !== $inProgress,
        ));
    }

    /** Technologia z questu działa od razu: bez uczelni, kosztu i tików (#144, Epic J D7). */
    private function grantArtifact(Technology $technology, WildIsland $island): void
    {
        $this->researched[] = $technology;
        $this->artifacts[$technology->value] = $this->tick;
        $this->record($this->tick, LogEvent::ArtifactFound, ['technology' => $technology->value, 'island' => $island->id]);
    }

    /**
     * Koniec udanej ekspedycji: zebrane nagrody (i złoto zamiast posiadanego
     * skarbu) do ładowni, odpoczynek wyspy od tego tiku.
     *
     * @param array{type: string, technology?: string, sea?: string, gold?: int}|null $treasure
     *
     * @return array{received: Resources, lost: Resources}
     */
    private function finishInland(WildIsland $island, InlandExpedition $inland, ?array $treasure): array
    {
        $loot = $inland->collected->add(new Resources(gold: $treasure['gold'] ?? 0));
        [$received, $lost] = $loot->fitInto($this->cargoCapacity() - $this->cargo->total());
        $this->setCargo($this->cargo->add($received));
        $this->inland = null;
        $this->wildVisits[$island->id] = $this->tick;
        $this->record($this->tick, LogEvent::InlandReturned, [
            'island' => $island->id,
            'stage' => $inland->stage,
            'received' => $received->isZero() ? null : $received->toArray(),
            'lost' => $lost->isZero() ? null : $lost->toArray(),
            'treasure' => $treasure['type'] ?? null,
        ]);

        return ['received' => $received, 'lost' => $lost];
    }

    // --- Spotkania na morzu (#141) ---

    public function encounter(): ?Encounter
    {
        return $this->encounter;
    }

    /**
     * Spotkanie po rejsie: około 8% szansy z seeda i tiku, jak sztorm. Nie na
     * sektorze wyspy, bramy ani dzikiej wyspy (#144). 60% handlarz, 40% pirat.
     * Przy embargu (#142) zamiast handlarza przychodzą łowcy nagród — ale tylko
     * do floty, która ma uzbrojony sprawny statek, bo z łowcami nie ma innego
     * wyjścia niż bitwa.
     */
    private function rollEncounter(int $x, int $y, WorldMap $world): ?Encounter
    {
        if (null !== $world->islandAt($x, $y) || null !== $world->gateAt($x, $y) || ['x' => $x, 'y' => $y] === $world->wildIslandPosition()) {
            return null;
        }
        if (crc32("{$this->worldSeed}:{$this->tick}:encounter") % 100 >= EncounterCatalog::CHANCE) {
            return null;
        }

        $type = crc32("{$this->worldSeed}:{$this->tick}:encounter-type") % 100 < EncounterCatalog::MERCHANT_SHARE
            ? EncounterType::Merchant
            : EncounterType::Pirate;
        if (EncounterType::Merchant === $type && $this->reputation->isEmbargo()) {
            if (!$this->canFight()) {
                return null;
            }
            $type = EncounterType::BountyHunters;
        }
        $this->encounter = new Encounter($type, $this->tick, $this->sea, $x, $y);
        $this->record($this->tick, LogEvent::EncounterMet, ['encounter' => $type->value]);

        return $this->encounter;
    }

    private function pendingEncounter(EncounterType $type): Encounter
    {
        $encounter = $this->encounter ?? throw new \DomainException('No encounter pending');
        if ($encounter->type !== $type) {
            throw new \DomainException('Not available in this encounter');
        }

        return $encounter;
    }

    /** Co zabierze ucieczka przed piratem: procent każdego surowca w ładowni, w dół. */
    public function fleeCargoLoss(): Resources
    {
        $percent = EncounterCatalog::FLEE_CARGO_PERCENT;

        return new Resources(
            intdiv($this->cargo->wood * $percent, 100),
            intdiv($this->cargo->steel * $percent, 100),
            intdiv($this->cargo->gold * $percent, 100),
        );
    }

    /**
     * Decyzja bez bitwy i bez zlecenia: minięcie handlarza (bez skutków),
     * ucieczka przed piratem (tik i część ładunku) albo okup w złocie
     * z ładowni. Kończy spotkanie.
     *
     * @return array{cargoLost: Resources, goldPaid: int}
     */
    public function decideEncounter(EncounterDecision $decision): array
    {
        $cargoLost = Resources::none();
        $goldPaid = 0;
        switch ($decision) {
            case EncounterDecision::Pass:
                $encounter = $this->pendingEncounter(EncounterType::Merchant);
                break;
            case EncounterDecision::Flee:
                $encounter = $this->pendingEncounter(EncounterType::Pirate);
                $cargoLost = $this->fleeCargoLoss();
                $this->setCargo($this->cargo->subtract($cargoLost));
                $this->passTicks(EncounterCatalog::FLEE_TICKS);
                break;
            case EncounterDecision::Ransom:
                $encounter = $this->pendingEncounter(EncounterType::Pirate);
                $goldPaid = EncounterCatalog::ransom($encounter->sea);
                if ($this->cargo->gold < $goldPaid) {
                    throw new \DomainException('Not enough gold for ransom');
                }
                $this->setCargo($this->cargo->subtract(new Resources(gold: $goldPaid)));
                break;
        }

        $this->encounter = null;
        $this->record($this->tick, LogEvent::EncounterResolved, [
            'encounter' => $encounter->type->value,
            'decision' => $decision->value,
            'cargoLost' => $cargoLost->isZero() ? null : $cargoLost->toArray(),
            'gold' => $goldPaid > 0 ? $goldPaid : null,
        ]);

        return ['cargoLost' => $cargoLost, 'goldPaid' => $goldPaid];
    }

    /**
     * Ceny handlarza: mnożnik ceny rynku (reputacja #142 i perk #162)
     * podniesiony o marżę handlarza.
     */
    public function merchantPrices(MarketRate $rate): MerchantPrices
    {
        return new MerchantPrices($rate, intdiv($this->marketPricePercent() * (100 + $this->merchantSurchargePercent()), 100));
    }

    /** Marża handlarza na morzu w procentach; perk Mistrz handlu (#162) ją znosi. */
    public function merchantSurchargePercent(): int
    {
        return $this->hasPerk(Perk::MasterTrader) ? 0 : EncounterCatalog::MERCHANT_WORSE_PERCENT;
    }

    /**
     * Handel na miejscu z handlarzem: jedno zlecenie sprzedaży i kupna
     * drewna i stali za złoto z ładowni, po kursie rynku akwenu z marżą
     * handlarza. Rozliczane naraz (złoto ze sprzedaży opłaca kupno),
     * kosztuje tik i kończy spotkanie. Wynik musi się zmieścić w ładowni.
     *
     * @return array{goldReceived: int, goldPaid: int}
     */
    public function tradeWithMerchant(Resources $sell, Resources $buy, MarketRate $rate): array
    {
        $encounter = $this->pendingEncounter(EncounterType::Merchant);
        $this->assertNoEmbargo();
        if (0 !== $sell->gold || 0 !== $buy->gold) {
            throw new \DomainException('Merchant trades wood and steel for gold');
        }
        if ($sell->isZero() && $buy->isZero()) {
            throw new \DomainException('Empty trade order');
        }

        $prices = $this->merchantPrices($rate);
        $goldReceived = $prices->sellValue($sell);
        $goldPaid = $prices->buyCost($buy);
        $cargo = $this->cargo->subtract($sell)
            ->add(new Resources(gold: $goldReceived))
            ->subtract(new Resources(gold: $goldPaid))
            ->add($buy);
        if ($cargo->total() > $this->cargoCapacity()) {
            throw new \DomainException('Cargo capacity exceeded');
        }

        $this->setCargo($cargo);
        $this->passTicks(EncounterCatalog::TRADE_TICKS);
        $this->encounter = null;
        $this->record($this->tick, LogEvent::EncounterResolved, [
            'encounter' => $encounter->type->value,
            'decision' => 'trade',
            'sold' => $sell->isZero() ? null : $sell->toArray(),
            'bought' => $buy->isZero() ? null : $buy->toArray(),
            'goldReceived' => $goldReceived,
            'goldPaid' => $goldPaid,
        ]);
        $this->changeReputation(Reputation::TRADE);
        $this->earn(SpecializationSource::MarketTurnover, $goldReceived + $goldPaid);

        return ['goldReceived' => $goldReceived, 'goldPaid' => $goldPaid];
    }

    /**
     * Rejestruje bitwę spotkania: napad na handlarza albo walkę z piratem.
     * W rejestrze zamiast wyspy stoi akwen, a rodzaj spotkania pod kluczem
     * 'encounter'. Start bitwy kończy spotkanie — porzucona bitwa nie blokuje
     * rejsu. Napad na handlarza kosztuje reputację już przy starcie, bez
     * względu na wynik (#142).
     *
     * @param list<string> $shipIds statki, które wypłynęły (do rozliczenia strat)
     */
    public function startEncounterBattle(GameId $gameId, array $shipIds = []): Encounter
    {
        $encounter = $this->encounter ?? throw new \DomainException('No encounter pending');
        $this->registerBattle($encounter->sea, $gameId, BattleKind::Encounter, $shipIds);
        $key = (string) $gameId;
        $this->battles[$key]['encounter'] = $encounter->type->value;
        // miejsce i czas spotkania: anulowana bitwa przywraca je do decyzji (#166)
        $this->battles[$key]['encounterAt'] = ['tick' => $encounter->tick, 'x' => $encounter->x, 'y' => $encounter->y];
        $this->encounter = null;
        if (EncounterType::Merchant === $encounter->type) {
            $before = $this->reputation->value;
            $this->changeReputation(Reputation::RAID);
            // faktyczna kara (przy granicy osi mniejsza) — tyle zwraca anulowanie
            $this->battles[$key]['raidPenalty'] = $before - $this->reputation->value;
        }

        return $encounter;
    }

    /** Rodzaj spotkania, którego bitwę toczy dana gra; null = gra spoza spotkania. */
    public function encounterFor(GameId $gameId): ?EncounterType
    {
        return EncounterType::tryFrom($this->battles[(string) $gameId]['encounter'] ?? '');
    }

    // --- Reputacja kupiec ↔ korsarz (#142) ---

    public function reputation(): Reputation
    {
        return $this->reputation;
    }

    /** Embargo: port odmawia handlu, a zamiast handlarza przychodzą łowcy nagród. */
    public function isUnderEmbargo(): bool
    {
        return $this->reputation->isEmbargo();
    }

    private function assertNoEmbargo(): void
    {
        if ($this->reputation->isEmbargo()) {
            throw new \DomainException('Embargo: no trade with a corsair');
        }
    }

    /** Czy flota stanie do bitwy z łowcami nagród: ma sprawny statek z bronią. */
    private function canFight(): bool
    {
        return [] !== array_filter($this->activeFleet(), static fn (OwnedShip $s) => $s->isArmed());
    }

    /** Zmiana reputacji w zakresie osi; przejście progu trafia do dziennika. */
    private function changeReputation(int $delta): void
    {
        $before = $this->reputation;
        $this->reputation = $before->change($delta);
        if ($before->tier() !== $this->reputation->tier()) {
            $this->record($this->tick, LogEvent::ReputationTier, [
                'from' => $before->tier()->value,
                'to' => $this->reputation->tier()->value,
                'reputation' => $this->reputation->value,
            ]);
        }
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
        $this->passTicks();
    }

    /**
     * Anuluje nierozstawioną bitwę wyprawy, żeby kapitan mógł zmienić skład
     * (#166). Czy gra się już zaczęła, sprawdza warstwa aplikacji. Bitwa znika
     * z rejestru, więc jej statki wracają do floty; wydany tik przepada, nic
     * innego się nie cofa. Obronę można podjąć ponownie — chyba że termin
     * uderzenia minął w trakcie, wtedy kontratak rozstrzyga się od razu jak
     * przy nieobecności. Spotkanie na morzu wraca do decyzji, a kara
     * reputacji za napad wraca do kapitana, bo napadu nie było.
     *
     * @return list<string> statki, które miały płynąć
     */
    public function cancelBattle(GameId $gameId, WorldMap $world): array
    {
        $key = (string) $gameId;
        $battle = $this->battles[$key] ?? throw new \DomainException('Battle not registered for this profile');
        if ($battle['settled']) {
            throw new \DomainException('Battle already settled');
        }

        unset($this->battles[$key]);
        $kind = self::battleKind($battle);
        if (BattleKind::Defense === $kind && null !== $this->counterattack && $this->counterattack['defense'] === $key) {
            $this->counterattack['defense'] = null;
            if ($this->tick >= $this->counterattack['strikeAt']) {
                $this->resolveCounterattack($this->counterattack['strikeAt'], held: false, absent: true, world: $world);
            }
        }
        if (BattleKind::Encounter === $kind) {
            $type = EncounterType::tryFrom($battle['encounter'] ?? '');
            $at = $battle['encounterAt'] ?? null;
            // nowe spotkanie (flota popłynęła mimo bitwy) ma pierwszeństwo przed przywróconym
            if (null !== $type && null !== $at && null === $this->encounter) {
                $this->encounter = new Encounter($type, $at['tick'], $battle['island'], $at['x'], $at['y']);
            }
            if (($battle['raidPenalty'] ?? 0) > 0) {
                $this->changeReputation($battle['raidPenalty']);
            }
        }

        return $battle['ships'] ?? [];
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
     * przepada; tak samo łup bitwy spotkania na morzu (#141). Wygrana potyczka
     * na twierdzy oblega wyspę, a banderę stawia wygrane oblężenie; przegrane
     * zostawia oblężenie (#120). Straty dotykają floty —
     * zatopione statki po wygranej wymagają remontu, po przegranej są stracone;
     * ładunek ponad nową pojemność przepada. Idempotentne: druga próba zwraca zera.
     *
     * @param list<int>               $sunkLengths    długości statków gracza zatopionych w bitwie
     * @param int                     $enemyShipsSunk liczba jednostek wroga zatopionych w bitwie (statystyka kapitana)
     * @param array<int, ShipVariant> $sunkVariants   wariant zatopionego statku, klucz = indeks w $sunkLengths;
     *                                                bez wpisu stratę ponosi dowolny statek tej długości
     * @param ShipType|null           $boardedType    typ statku wroga zdobytego abordażem (#118); po wygranej czeka na decyzję
     *
     * @return array{xp:int, loot:Resources, lost:list<string>, damaged:list<string>, cargoLost:Resources, captured:bool, besieged:bool, defense: array{held:bool, stockpileLost:Resources}|null, gateOpened:bool, prize:?Prize, lootBonus:Resources}
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
            return ['xp' => 0, 'loot' => Resources::none(), 'lost' => [], 'damaged' => [], 'cargoLost' => Resources::none(), 'captured' => false, 'besieged' => false, 'defense' => null, 'gateOpened' => false, 'prize' => null, 'lootBonus' => Resources::none()];
        }

        // perk „łup z bitew” (#161): premia wchodzi do łupu przed magazynem i ładownią
        $lootBonus = $this->lootBonus($loot);
        $loot = $loot->add($lootBonus);

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
            BattleKind::Guardian, BattleKind::Encounter => [false, null],
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
        // na morzu nie ma magazynu: łup strażnika i spotkania ładuje flota
        $atSea = in_array($kind, [BattleKind::Guardian, BattleKind::Encounter], true);
        if (!$atSea) {
            $this->stockpiles[$battle['island']] = $this->stockpile($battle['island'])->add($loot);
        }
        if (BattleKind::Encounter === $kind) {
            $this->record($this->tick, LogEvent::EncounterBattle, ['encounter' => $battle['encounter'] ?? null, 'result' => $result, 'sea' => $battle['island']]);
            if ($won && EncounterType::Pirate->value === ($battle['encounter'] ?? null)) {
                $this->changeReputation(Reputation::PIRATE_DEFEATED);
            }
        }

        [$lost, $damaged] = $this->applyLosses($battle['ships'] ?? [], $result, $sunkLengths, $sunkVariants);
        $cargoLost = $this->trimCargo();
        if ($atSea) {
            // łup strażnika i spotkania ładuje flota, która przetrwała bitwę
            [$fit, $overflow] = $loot->fitInto($this->cargoCapacity() - $this->cargo->total());
            $this->setCargo($this->cargo->add($fit));
            $cargoLost = $cargoLost->add($overflow);
        }

        $prize = null;
        if ('won' === $result && null !== $boardedType) {
            $prize = Prize::capture($boardedType, $boardedVariant, $this->tick);
            $this->prizes[$prize->id] = $prize;
            $place = match ($kind) {
                BattleKind::Guardian => 'gate',
                BattleKind::Encounter => 'sea',
                default => 'island',
            };
            $this->record($this->tick, LogEvent::ShipBoarded, ['ship' => $boardedType->value, 'variant' => $boardedVariant?->value, $place => $battle['island']]);
        }
        $this->earnFromBattle($kind, $won, $enemyShipsSunk, null !== $prize);

        return ['xp' => $xpAward, 'loot' => $loot, 'lost' => $lost, 'damaged' => $damaged, 'cargoLost' => $cargoLost, 'captured' => $captured, 'besieged' => $besieged, 'defense' => $defense, 'gateOpened' => $gateOpened, 'prize' => $prize, 'lootBonus' => $lootBonus];
    }

    /** Premia perku „łup z bitew” (#161): LOOT_BONUS_PERK_PERCENT każdego surowca, w górę; bez perku zero. */
    private function lootBonus(Resources $loot): Resources
    {
        if (!$this->hasPerk(Perk::LootBonus)) {
            return Resources::none();
        }

        return Resources::fromArray(array_map(
            static fn (int $amount) => intdiv($amount * self::LOOT_BONUS_PERK_PERCENT + 99, 100),
            $loot->toArray(),
        ));
    }

    /**
     * Punkty bojowe z rozliczonej bitwy (D3): wygrana każdego rodzaju, do niej
     * premie za abordaż, obronę i oblężenie (sumują się, decyzja Jacka
     * 25.10.2026), oraz każdy zatopiony wróg, także w przegranej.
     */
    private function earnFromBattle(BattleKind $kind, bool $won, int $enemyShipsSunk, bool $boarded): void
    {
        $this->earn(SpecializationSource::EnemySunk, $enemyShipsSunk);
        if (!$won) {
            return;
        }
        $this->earn(SpecializationSource::Win, 1);
        if ($boarded) {
            $this->earn(SpecializationSource::Boarding, 1);
        }
        match ($kind) {
            BattleKind::Defense => $this->earn(SpecializationSource::Defense, 1),
            BattleKind::Siege => $this->earn(SpecializationSource::Siege, 1),
            default => null,
        };
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
                $this->setCargo($this->cargo->add($refund));
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
                    $ship->markBattleDamaged();
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

    // --- Zestawy floty (#125) ---

    /** @return list<FleetPreset> zapisane zestawy w kolejności zapisu */
    public function fleetPresets(): array
    {
        return array_values($this->fleetPresets);
    }

    /**
     * Zapisuje zestaw floty: statki muszą być we flocie (sprawność nie gra roli —
     * zestaw opisuje ulubione ustawienie, wczytanie pominie niesprawne), pozycje
     * spełniają zasady planszy. Zestaw o tej samej nazwie (bez względu na
     * wielkość liter) jest nadpisywany i zachowuje id; nowy ponad limit — odrzucany.
     * Zapis nie kosztuje tiku: to porządek w kajucie, nie akcja morska.
     *
     * @param list<array{shipId: string, x: int, y: int, o: Orientation}> $placements
     */
    public function saveFleetPreset(string $name, int $boardWidth, int $boardHeight, array $placements): FleetPreset
    {
        $ships = [];
        foreach ($placements as $placement) {
            $ship = $this->shipById($placement['shipId']) ?? throw new \DomainException('Ship not found');
            $ships[] = ['shipId' => $ship->id, 'type' => $ship->type] + $placement;
        }

        $existing = $this->fleetPresetNamed($name);
        if (null === $existing && count($this->fleetPresets) >= self::FLEET_PRESET_LIMIT) {
            throw new \DomainException('Fleet preset limit exceeded');
        }

        $preset = FleetPreset::create($existing?->id, $name, $boardWidth, $boardHeight, $ships);
        $this->fleetPresets[$preset->id] = $preset;

        return $preset;
    }

    public function renameFleetPreset(string $presetId, string $name): FleetPreset
    {
        $preset = $this->fleetPresets[$presetId] ?? throw new \DomainException('Fleet preset not found');
        $other = $this->fleetPresetNamed($name);
        if (null !== $other && $other->id !== $presetId) {
            throw new \DomainException('Fleet preset name taken');
        }

        return $this->fleetPresets[$presetId] = $preset->renamed($name);
    }

    public function deleteFleetPreset(string $presetId): void
    {
        if (!isset($this->fleetPresets[$presetId])) {
            throw new \DomainException('Fleet preset not found');
        }
        unset($this->fleetPresets[$presetId]);
    }

    private function fleetPresetNamed(string $name): ?FleetPreset
    {
        foreach ($this->fleetPresets as $preset) {
            if ($preset->hasName($name)) {
                return $preset;
            }
        }

        return null;
    }

    // --- Specjalizacje (Epic K, #160) ---

    public function specializations(): Specializations
    {
        return $this->specializations;
    }

    public function specializationLevel(SpecializationTrack $track): int
    {
        return $this->specializations->level($track);
    }

    /** Czy perk działa: poziom jego ścieżki sięgnął poziomu perka. API dla efektów z #161 i #162. */
    public function hasPerk(Perk $perk): bool
    {
        return $this->specializationLevel($perk->track()) >= $perk->level();
    }

    /** @return list<Perk> perki zdobyte na obu ścieżkach */
    public function perks(): array
    {
        return array_values(array_filter(Perk::cases(), fn (Perk $perk) => $this->hasPerk($perk)));
    }

    /**
     * Nalicza jednostki akcji specjalizacji (D3). Każdy przekroczony próg
     * ścieżki trafia do dziennika osobnym wpisem, także przy awansie o dwa
     * poziomy jedną akcją.
     */
    private function earn(SpecializationSource $source, int $units): void
    {
        if ($units <= 0) {
            return;
        }
        $track = $source->track();
        $before = $this->specializations->level($track);
        $this->specializations = $this->specializations->add($source, $units);
        for ($level = $before + 1; $level <= $this->specializations->level($track); ++$level) {
            $this->record($this->tick, LogEvent::SpecializationLevel, ['track' => $track->value, 'level' => $level]);
        }
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

    /** @return array<string, array{island: string, settled: bool, result: ?string, kind?: string, ships?: list<string>, enemySunk?: int, stronghold?: bool, encounter?: string, encounterAt?: array{tick:int, x:int, y:int}, raidPenalty?: int}> */
    public function battles(): array
    {
        return $this->battles;
    }

    /**
     * @param array<string, array{island: string, settled: bool, result: ?string, kind?: string, ships?: list<string>, enemySunk?: int, stronghold?: bool}> $battles
     * @param list<OwnedShip>|null                                                                                                                          $fleet           null = profil sprzed ekonomii — dostaje flotę startową
     * @param Resources|null                                                                                                                                $cargo           null = profil sprzed ładowni (patrz $unplaced) albo sprzed ekonomii — drewno startowe do magazynu wyspy
     * @param int|null                                                                                                                                      $worldSeed       null = seed wyprowadzony z id (stabilny dla starych profili)
     * @param array{x:int,y:int}|null                                                                                                                       $position        pozycja na akwenie $sea; null = start ustali ensureWorldState
     * @param list<string>|null                                                                                                                             $discovered      sektory "x:y" akwenu $sea
     * @param int|null                                                                                                                                      $tick            null = profil sprzed tiku: rejsy + zarejestrowane bitwy
     * @param list<string>|null                                                                                                                             $visited         sektory akwenu $sea; null = profil sprzed odwiedzin: tylko obecna pozycja
     * @param array<string, Resources>                                                                                                                      $stockpiles      magazyny wysp
     * @param Resources|null                                                                                                                                $unplaced        surowce czekające na magazyn wyspy (też jeden magazyn profilu sprzed ładowni) — rozłożone przy ensureWorldState()
     * @param array<string, int>                                                                                                                            $depositTicks    tik ostatniego naliczenia urobku złoża (klucz: id wyspy)
     * @param array<string, int>                                                                                                                            $ownedIslands    wyspy pod banderą: id wyspy → tik zdobycia
     * @param array<string, list<BuildingType>>                                                                                                             $buildings       budynki osady (klucz: id wyspy)
     * @param list<Technology>                                                                                                                              $researched      zbadane technologie
     * @param array{technology: Technology, island: string, progress: int, countedTo: int}|null                                                             $research        badanie w toku
     * @param string|null                                                                                                                                   $sea             akwen floty; null = profil sprzed akwenów: Morze Rozbitków
     * @param array<string, SeaState>                                                                                                                       $seaStates       stan pozostałych akwenów (klucz: id akwenu); stan akwenu $sea dają $position, $discovered i $visited
     * @param array<string, int>                                                                                                                            $openedGates     bramy otwarte na stałe: id bramy → tik otwarcia
     * @param list<LogEntry>                                                                                                                                $log             dziennik kapitana, najstarsze pierwsze; profil sprzed dziennika zaczyna od pustego
     * @param list<Prize>                                                                                                                                   $prizes          statki zdobyte abordażem, czekające na decyzję (#118)
     * @param array<string, int>                                                                                                                            $besieged        oblężone twierdze: id wyspy → tik wygranej potyczki (#120)
     * @param list<FleetPreset>                                                                                                                             $fleetPresets    zapisane zestawy floty (#125)
     * @param Encounter|null                                                                                                                                $encounter       spotkanie czekające na decyzję (#141)
     * @param bool                                                                                                                                          $fieldRepairs    naprawy kutrów remontowych włączone (#152)
     * @param array<string, int>                                                                                                                            $seaCharts       mapy akwenów: id akwenu → tik zdobycia (#143)
     * @param InlandExpedition|null                                                                                                                         $inland          ekspedycja w głąb dzikiej wyspy w toku (#144)
     * @param array<string, int>                                                                                                                            $wildVisits      koniec ostatniej ekspedycji: id dzikiej wyspy → tik (#144)
     * @param array<string, int>                                                                                                                            $artifacts       technologie z questu: id technologii → tik (#144), tylko spośród $researched
     * @param int                                                                                                                                           $reputation      reputacja kupiec ↔ korsarz (#142); spoza osi przycięta
     * @param Specializations|null                                                                                                                          $specializations liczniki specjalizacji (#160); null = profil sprzed specjalizacji, start od zera (D7)
     * @param array<string, int>                                                                                                                            $cargoOrigins    jednostki ładowni załadowane na wyspach (#160); suma przycięta do ładowni
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
        array $fleetPresets = [],
        ?Encounter $encounter = null,
        bool $fieldRepairs = true,
        array $seaCharts = [],
        ?InlandExpedition $inland = null,
        array $wildVisits = [],
        array $artifacts = [],
        int $reputation = 0,
        ?Specializations $specializations = null,
        array $cargoOrigins = [],
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
        $self->seaCharts = $seaCharts;
        // zdobyta twierdza nie bywa oblężona
        $self->besieged = array_diff_key($besieged, $ownedIslands);
        foreach ($prizes as $prize) {
            $self->prizes[$prize->id] = $prize;
        }
        foreach (array_slice($fleetPresets, 0, self::FLEET_PRESET_LIMIT) as $preset) {
            $self->fleetPresets[$preset->id] = $preset;
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
        $self->encounter = $encounter;
        $self->fieldRepairs = $fieldRepairs;
        $self->inland = $inland;
        $self->wildVisits = $wildVisits;
        $self->artifacts = array_filter(
            $artifacts,
            static fn (string $technology) => in_array(Technology::tryFrom($technology), $researched, true),
            ARRAY_FILTER_USE_KEY,
        );
        $self->reputation = Reputation::clamped($reputation);
        $self->specializations = $specializations ?? new Specializations();
        $self->cargoOrigins = array_filter(array_map(static fn (int $units) => max(0, $units), $cargoOrigins));
        $self->setCargo($self->cargo);

        return $self;
    }
}
