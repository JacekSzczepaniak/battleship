<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\BuildingType;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\IslandCatalog;
use App\Domain\Expedition\LogEntry;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\SeaGraph;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\ShipVariant;
use App\Domain\Expedition\TechBranch;
use App\Domain\Expedition\TechLevel;
use App\Domain\Expedition\Technology;
use App\Domain\Expedition\WorldMap;
use App\Domain\Shared\ProfileId;

final class GetExpedition
{
    public function __construct(
        private ProfileRepository $profiles,
        private IslandCatalog $islands,
        private WorldFactory $worldFactory,
        private SeaGraph $seas,
        private SeaRoute $route,
    ) {
    }

    /**
     * Stan wyprawy: profil (XP, ranga, ładownia, liczba bander), flota, katalogi
     * typów statków (do UI stoczni) i budynków (do panelu osady), wyspy
     * z kłódkami, banderami, stoczniami, złożami i budynkami, kontratak
     * w toku i ostatnie rozstrzygnięcie (#78), drzewko badań (Epic G) oraz bramy
     * bieżącego akwenu z powodem blokady i strażnikiem (#107) oraz dziennik
     * kapitana (#111). Kontratak może celować w wyspę innego akwenu (#109):
     * zapowiedź podaje akwen celu i drogę floty, a świat — bandery per akwen.
     *
     * @return array{profile: array<string,mixed>, fleet: list<array<string,mixed>>, shipTypes: list<array<string,mixed>>, buildingTypes: list<array<string,mixed>>, islands: list<array<string,mixed>>, world: array<string,mixed>, counterattack: array<string,mixed>, research: array<string,mixed>, log: list<array<string,mixed>>}
     */
    public function handle(string $profileId): array
    {
        $profile = $this->profiles->get(new ProfileId($profileId));
        if (null === $profile) {
            throw new \DomainException('Profile not found');
        }

        $rank = $profile->rank();
        $next = $rank->next();

        $sea = $this->worldFactory->seaFor($profile);
        $world = $this->worldFactory->worldFor($profile);
        $theme = $world->theme();
        $profile->ensureWorldState($world);
        $position = $profile->position();

        // wyspy bieżącego akwenu; bandery liczą się ze wszystkich akwenów
        $islands = [];
        foreach ($sea->islands->all() as $island) {
            $stats = $profile->battleStats($island->id);
            $isOwned = $profile->ownsIsland($island->id);
            $islandPos = $world->islandPosition($island->id);
            $discovered = null !== $islandPos && $profile->isDiscovered($islandPos['x'], $islandPos['y']);
            $islands[] = [
                'discovered' => $discovered,
                'position' => $discovered ? $islandPos : null,
                'present' => $discovered && $islandPos === $position,
                'id' => $island->id,
                'name' => $island->name,
                'description' => $island->description,
                'mode' => $island->mode,
                'requiredRank' => $island->requiredRank->value,
                'xpWin' => $island->xpWin,
                'xpLoss' => $island->xpLoss,
                'lootWin' => $island->lootWin->toArray(),
                'lootLoss' => $island->lootLoss->toArray(),
                // 0 = brak stoczni (na każdej wyspie zbudujesz tylko tratwę); z rozbudową pod banderą (#82)
                'shipyardLevel' => $profile->shipyardLevel($island),
                // bandera kapitana: pierwsza wygrana bitwa o wyspę ją zdobywa
                'owned' => $isOwned,
                // złoże: urobek na tik (z tartakiem/kopalnią), limit czekający w magazynie, czy pracuje (pod banderą)
                'deposit' => $island->hasDeposit() ? [
                    'yield' => $profile->depositYield($island, $theme)->toArray(),
                    'cap' => $profile->depositCap($island, $theme)->toArray(),
                    'active' => $isOwned,
                ] : null,
                // osada: stojące budynki (czynne pod banderą) i powód blokady budowy każdego typu (null = można)
                'buildings' => array_map(static fn (BuildingType $t) => $t->value, $profile->buildingsOn($island->id)),
                'buildBlockers' => array_combine(
                    array_map(static fn (BuildingType $t) => $t->value, BuildingType::cases()),
                    array_map(static fn (BuildingType $t) => $profile->buildingBlocker($t, $island, $world)?->value, BuildingType::cases()),
                ),
                'board' => ['w' => $island->boardWidth, 'h' => $island->boardHeight],
                // ile pól planszy może zająć skład bitewny (#73)
                'squadLimit' => $island->squadCellLimit(),
                'unlocked' => $island->isAccessibleFor($rank),
                'stockpile' => $profile->stockpile($island->id)->toArray(),
                'wins' => $stats['wins'],
                'losses' => $stats['losses'],
            ];
        }

        // ładownia i remont z technologiami kapitana (Pojemne ładownie, Dok remontowy)
        $fleet = array_map(static fn ($ship) => [
            'id' => $ship->id,
            'type' => $ship->type->value,
            'length' => $ship->type->length(),
            // wariant stały od budowy; null = typ bez wariantów (#99)
            'variant' => $ship->variant?->value,
            'damaged' => $ship->isDamaged(),
            'repairCost' => $profile->repairCost($ship->type)->toArray(),
        ], $profile->fleet());

        $shipTypes = array_map(static fn (ShipType $type) => [
            'type' => $type->value,
            'length' => $type->length(),
            'cargoCapacity' => $profile->shipCargoCapacity($type),
            // nośnik broni = eskorta konwoju (#47)
            'armed' => $type->isArmed(),
            'buildCost' => $type->buildCost()->toArray(),
            'repairCost' => $profile->repairCost($type)->toArray(),
            'requiredRank' => $type->requiredRank()->value,
            'requiredShipyardLevel' => $type->requiredShipyardLevel(),
            // warianty do wyboru przy budowie; zablokowany czeka na technologię
            'variants' => array_map(static fn (ShipVariant $variant) => [
                'variant' => $variant->value,
                'requiredTechnology' => $variant->requiredTechnology()?->value,
                'unlocked' => null === $variant->requiredTechnology() || $profile->hasResearched($variant->requiredTechnology()),
            ], ShipVariant::forType($type)),
        ], ShipType::cases());

        $buildingTypes = array_map(static fn (BuildingType $type) => [
            'type' => $type->value,
            'buildCost' => $type->buildCost()->toArray(),
        ], BuildingType::cases());

        return [
            'profile' => [
                'id' => (string) $profile->id(),
                'name' => $profile->name(),
                'xp' => $profile->xp(),
                'rank' => $rank->value,
                'nextRank' => null !== $next
                    ? ['rank' => $next->value, 'xpNeeded' => max(0, $next->threshold() - $profile->xp())]
                    : null,
                'cargo' => $profile->cargo()->toArray(),
                'cargoCapacity' => $profile->cargoCapacity(),
                'enemyShipsSunk' => $profile->enemyShipsSunk(),
                // wyspy pod banderą na wszystkich akwenach
                'ownedIslands' => count(array_filter(
                    $this->islands->all(),
                    static fn ($island) => $profile->ownsIsland($island->id),
                )),
            ],
            'fleet' => $fleet,
            'shipTypes' => $shipTypes,
            'buildingTypes' => $buildingTypes,
            'islands' => $islands,
            'world' => [
                // akwen floty i jego motyw (Epic I): sztorm, widoczność, mnożnik złóż
                'sea' => [
                    'id' => $sea->id,
                    'name' => $sea->name,
                    'theme' => $theme->toArray(),
                ],
                'width' => WorldMap::WIDTH,
                'height' => WorldMap::HEIGHT,
                'position' => $position,
                'discovered' => $profile->discoveredSectors(),
                // sektory, na których kapitan był — tam sztorm już nie grozi
                'visited' => $profile->visitedSectors(),
                'atIsland' => $world->islandAt($position['x'], $position['y']),
                'atGate' => $world->gateAt($position['x'], $position['y']),
                // bramy akwenu (#107): sektor po odkryciu, cel, blokada i strażnik
                'gates' => $this->gatesView($profile, $sea, $world),
                // szansa z motywu akwenu; Mapy sztormowe (Epic G) ją połowią
                'stormChance' => $profile->stormChance($theme),
                // Żegluga dwusektorowa (Epic G) wydłuża rejs za 1 tik z 1 do 2 sektorów
                'sailRange' => $profile->sailRange(),
                // zegar świata: rejs i bitwa kosztują tik
                'tick' => $profile->tick(),
                // bandery per akwen (#109): pełna bandera działa tylko przy obronie wyspy swojego akwenu
                'flags' => $this->flagsView($profile, $world),
            ],
            'counterattack' => $this->counterattackView($profile, $world),
            'research' => $this->researchView($profile, $world),
            'log' => $this->logView($profile),
        ];
    }

    /**
     * Dziennik kapitana od najnowszego wpisu: tik, rodzaj zdarzenia i jego
     * dane, a do id wyspy, bramy i akwenów nazwy z katalogów (wyspa z innego
     * akwenu też ma nazwę). Treść zdania składa UI.
     *
     * @return list<array<string,mixed>>
     */
    private function logView(CaptainProfile $profile): array
    {
        return array_map(function (LogEntry $entry) {
            $data = $entry->data;
            $names = array_filter([
                'islandName' => isset($data['island']) ? $this->islandName((string) $data['island']) : null,
                'gateName' => isset($data['gate']) ? ($this->seas->gate((string) $data['gate'])->name ?? (string) $data['gate']) : null,
                'fromName' => isset($data['from']) ? ($this->seas->sea((string) $data['from'])->name ?? (string) $data['from']) : null,
                'toName' => isset($data['to']) ? ($this->seas->sea((string) $data['to'])->name ?? (string) $data['to']) : null,
            ], static fn ($v) => null !== $v);

            return ['tick' => $entry->tick, 'event' => $entry->event->value] + $data + $names;
        }, array_reverse($profile->log()));
    }

    /**
     * Bramy wychodzące z akwenu floty. Sektor bramy widać dopiero po jego
     * odkryciu, jak wyspy; blokada liczy się bez względu na obecność floty.
     *
     * @return list<array<string,mixed>>
     */
    private function gatesView(CaptainProfile $profile, Sea $sea, WorldMap $world): array
    {
        $position = $profile->position();
        $gates = [];
        foreach ($this->seas->passagesFrom($sea->id) as $passage) {
            $gate = $passage->gate;
            $target = $this->seas->sea($passage->to);
            $pos = $world->gatePosition($gate->id);
            $discovered = null !== $pos && $profile->isDiscovered($pos['x'], $pos['y']);
            $blocker = $profile->gateBlocker($passage);
            $guardian = $gate->guardian();
            $stats = $profile->battleStats($gate->id);
            $gates[] = [
                'id' => $gate->id,
                'name' => $gate->name,
                'edge' => $passage->edge->value,
                'to' => ['id' => $passage->to, 'name' => $target->name ?? $passage->to, 'theme' => $target?->theme->toArray()],
                'discovered' => $discovered,
                'position' => $discovered ? $pos : null,
                'present' => $discovered && $pos === $position,
                // null = otwarta; rank | guardian (#108 dołoży technology)
                'blocker' => $blocker?->value,
                'open' => null === $blocker,
                'guardian' => null !== $guardian ? [
                    'name' => $guardian->name,
                    'description' => $guardian->description,
                    'requiredRank' => $guardian->requiredRank->value,
                    'unlocked' => $profile->rank()->atLeast($guardian->requiredRank),
                    'defeated' => $profile->isGateOpen($gate->id),
                    'mode' => $guardian->mode,
                    'fleet' => array_map(static fn (ShipType $t) => $t->value, $guardian->fleet),
                    'fleetCells' => $guardian->fleetCells(),
                    'board' => ['w' => $guardian->boardWidth, 'h' => $guardian->boardHeight],
                    'squadLimit' => $guardian->squadCellLimit(),
                    'xpWin' => $guardian->xpWin,
                    'xpLoss' => $guardian->xpLoss,
                    'lootWin' => $guardian->lootWin->toArray(),
                    'lootLoss' => $guardian->lootLoss->toArray(),
                    'wins' => $stats['wins'],
                    'losses' => $stats['losses'],
                ] : null,
            ];
        }

        return $gates;
    }

    /**
     * Drzewko badań: gałęzie, poziomy (koszt, czas, ranga), technologie ze
     * stanem i badanie w toku. Powód blokady liczy się dla wyspy, na której
     * stoi kapitan — tam można zacząć badanie.
     *
     * @return array{current: array<string,mixed>|null, branches: list<string>, levels: list<array<string,mixed>>, technologies: list<array<string,mixed>>}
     */
    private function researchView(CaptainProfile $profile, WorldMap $world): array
    {
        $position = $profile->position();
        $hereId = $world->islandAt($position['x'], $position['y']);
        $here = null !== $hereId ? $this->islands->byId($hereId) : null;
        $current = $profile->research();

        $technologies = array_map(function (Technology $t) use ($profile, $here, $world, $current) {
            $inProgress = $current['technology'] ?? null;
            $blocker = $profile->researchBlocker($t, $here, $world);

            return [
                'id' => $t->value,
                'branch' => $t->branch()->value,
                'level' => $t->level()->value,
                // researched | in-progress | available | locked (z powodem w blocker)
                'state' => match (true) {
                    $profile->hasResearched($t) => 'researched',
                    $inProgress === $t => 'in-progress',
                    null === $blocker => 'available',
                    default => 'locked',
                },
                'ticksLeft' => $inProgress === $t ? $profile->researchTicksLeft() : null,
                'blocker' => $profile->hasResearched($t) || $inProgress === $t ? null : $blocker?->value,
            ];
        }, Technology::cases());

        return [
            'current' => null !== $current ? [
                'technology' => $current['technology']->value,
                'island' => $current['island'],
                'islandName' => $this->islandName($current['island']),
                'progress' => $current['progress'],
                'ticks' => $current['technology']->level()->researchTicks(),
                'ticksLeft' => $profile->researchTicksLeft(),
                // wyspa uczelni bez bandery: badanie stoi do odbicia
                'paused' => $profile->isResearchPaused(),
            ] : null,
            'branches' => array_map(static fn (TechBranch $b) => $b->value, TechBranch::cases()),
            'levels' => array_map(static fn (TechLevel $l) => [
                'level' => $l->value,
                'cost' => $l->researchCost()->toArray(),
                'ticks' => $l->researchTicks(),
                'requiredRank' => $l->requiredRank()?->value,
            ], TechLevel::cases()),
            'technologies' => $technologies,
        ];
    }

    /**
     * Zapowiedź kontrataku (wyspa i jej akwen, za ile tików uderzenie, droga
     * floty, bonus pełnej bandery akwenu celu, fort) oraz ostatnie
     * rozstrzygnięcie — także utrata wyspy albo grabież pod fortem pod
     * nieobecność kapitana.
     *
     * @return array{pending: array<string,mixed>|null, last: array<string,mixed>|null}
     */
    private function counterattackView(CaptainProfile $profile, WorldMap $world): array
    {
        $pending = $profile->counterattack();
        $last = $profile->lastCounterattack();
        $targetMap = null !== $pending ? $world->seaOf($pending['island']) : null;
        $targetSea = null !== $targetMap ? $this->seas->sea($targetMap->seaId()) : null;
        $route = null !== $pending ? $this->route->toIsland($profile, $pending['island']) : null;
        $ticksLeft = null !== $pending ? max(0, $pending['strikeAt'] - $profile->tick()) : 0;

        return [
            'pending' => null !== $pending ? [
                'island' => $pending['island'],
                'islandName' => $this->islandName($pending['island']),
                'announcedAt' => $pending['announcedAt'],
                'strikeAt' => $pending['strikeAt'],
                'ticksLeft' => $ticksLeft,
                // akwen celu (#109): kontratak może uderzyć na innym akwenie niż flota
                'sea' => null !== $targetSea ? ['id' => $targetSea->id, 'name' => $targetSea->name] : null,
                'fleetAway' => null !== $targetSea && $targetSea->id !== $profile->currentSea(),
                // droga floty na wyspę w tikach (rejsy + 1 tik za bramę); obronę trzeba
                // przyjąć przed uderzeniem, więc zdąży droga krótsza niż ticksLeft
                'route' => null !== $route ? $route + ['inTime' => $route['ticks'] < $ticksLeft] : null,
                // rozpoczęta obrona wstrzymuje termin do rozliczenia bitwy
                'defenseStarted' => null !== $pending['defense'],
                // pełna bandera akwenu celu (#79, #109): obrońca strzela pierwszy, napastnik bez największego statku
                'fullFlag' => null !== $targetMap && $profile->hasFullFlag($targetMap),
                // czynny fort (#81): bez obrony wyspa zostaje pod banderą, magazyn traci połowę
                'fort' => $profile->hasActiveBuilding($pending['island'], BuildingType::Fort),
            ] : null,
            'last' => null !== $last ? [
                'island' => $last['island'],
                'islandName' => $this->islandName($last['island']),
                'tick' => $last['tick'],
                'held' => $last['held'],
                'absent' => $last['absent'],
                // nieobecność pod fortem: grabież połowy magazynu, bandera została
                'plundered' => $last['plundered'],
                'stockpileLost' => $last['stockpileLost'],
            ] : null,
        ];
    }

    /**
     * Bandery na akwenach, które kapitan zna (stoi tam albo już był): ile wysp
     * pod banderą i czy to pełna bandera akwenu.
     *
     * @return list<array{sea: string, name: string, owned: int, total: int, fullFlag: bool}>
     */
    private function flagsView(CaptainProfile $profile, WorldMap $world): array
    {
        $flags = [];
        foreach ([$world, ...$world->otherSeas()] as $map) {
            $sea = $this->seas->sea($map->seaId());
            if (null === $sea || ($map !== $world && !isset($profile->seaStates()[$sea->id]))) {
                continue;
            }
            $islands = array_keys($map->islands());
            $flags[$sea->id] = [
                'sea' => $sea->id,
                'name' => $sea->name,
                'owned' => count(array_filter($islands, static fn ($id) => $profile->ownsIsland((string) $id))),
                'total' => count($islands),
                'fullFlag' => $profile->hasFullFlag($map),
            ];
        }

        // kolejność progresji akwenów, nie kolejność map
        return array_values(array_filter(array_map(
            static fn (Sea $sea) => $flags[$sea->id] ?? null,
            $this->seas->seas(),
        )));
    }

    private function islandName(string $islandId): string
    {
        return $this->islands->byId($islandId)->name ?? $islandId;
    }
}
