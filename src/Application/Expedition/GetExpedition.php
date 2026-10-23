<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\BuildingType;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\IslandCatalog;
use App\Domain\Expedition\ProfileRepository;
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
    ) {
    }

    /**
     * Stan wyprawy: profil (XP, ranga, ładownia, liczba bander), flota, katalogi
     * typów statków (do UI stoczni) i budynków (do panelu osady), wyspy
     * z kłódkami, banderami, stoczniami, złożami i budynkami, kontratak
     * w toku i ostatnie rozstrzygnięcie (#78) oraz drzewko badań (Epic G).
     *
     * @return array{profile: array<string,mixed>, fleet: list<array<string,mixed>>, shipTypes: list<array<string,mixed>>, buildingTypes: list<array<string,mixed>>, islands: list<array<string,mixed>>, world: array<string,mixed>, counterattack: array<string,mixed>, research: array<string,mixed>}
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
                // szansa z motywu akwenu; Mapy sztormowe (Epic G) ją połowią
                'stormChance' => $profile->stormChance($theme),
                // Żegluga dwusektorowa (Epic G) wydłuża rejs za 1 tik z 1 do 2 sektorów
                'sailRange' => $profile->sailRange(),
                // zegar świata: rejs i bitwa kosztują tik
                'tick' => $profile->tick(),
            ],
            'counterattack' => $this->counterattackView($profile, $world),
            'research' => $this->researchView($profile, $world),
        ];
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
     * Zapowiedź kontrataku (wyspa, za ile tików uderzenie, bonus pełnej
     * bandery, fort) oraz ostatnie rozstrzygnięcie — także utrata wyspy albo
     * grabież pod fortem pod nieobecność kapitana.
     *
     * @return array{pending: array<string,mixed>|null, last: array<string,mixed>|null}
     */
    private function counterattackView(CaptainProfile $profile, WorldMap $world): array
    {
        $pending = $profile->counterattack();
        $last = $profile->lastCounterattack();

        return [
            'pending' => null !== $pending ? [
                'island' => $pending['island'],
                'islandName' => $this->islandName($pending['island']),
                'announcedAt' => $pending['announcedAt'],
                'strikeAt' => $pending['strikeAt'],
                'ticksLeft' => max(0, $pending['strikeAt'] - $profile->tick()),
                // rozpoczęta obrona wstrzymuje termin do rozliczenia bitwy
                'defenseStarted' => null !== $pending['defense'],
                // pełna bandera (#79): obrońca strzela pierwszy, napastnik bez największego statku
                'fullFlag' => $profile->hasFullFlag($world),
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

    private function islandName(string $islandId): string
    {
        return $this->islands->byId($islandId)->name ?? $islandId;
    }
}
