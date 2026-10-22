<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\BuildingType;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\IslandCatalog;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\ShipType;
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
     * z kłódkami, banderami, stoczniami, złożami i budynkami oraz kontratak
     * w toku i ostatnie rozstrzygnięcie (#78).
     *
     * @return array{profile: array<string,mixed>, fleet: list<array<string,mixed>>, shipTypes: list<array<string,mixed>>, buildingTypes: list<array<string,mixed>>, islands: list<array<string,mixed>>, world: array<string,mixed>, counterattack: array<string,mixed>}
     */
    public function handle(string $profileId): array
    {
        $profile = $this->profiles->get(new ProfileId($profileId));
        if (null === $profile) {
            throw new \DomainException('Profile not found');
        }

        $rank = $profile->rank();
        $next = $rank->next();

        $world = $this->worldFactory->worldFor($profile);
        $profile->ensureWorldState($world);
        $position = $profile->position();

        $islands = [];
        $owned = 0;
        foreach ($this->islands->all() as $island) {
            $stats = $profile->battleStats($island->id);
            $isOwned = $profile->ownsIsland($island->id);
            $owned += (int) $isOwned;
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
                    'yield' => $profile->depositYield($island)->toArray(),
                    'cap' => $profile->depositCap($island)->toArray(),
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

        $fleet = array_map(static fn ($ship) => [
            'id' => $ship->id,
            'type' => $ship->type->value,
            'length' => $ship->type->length(),
            'damaged' => $ship->isDamaged(),
            'repairCost' => $ship->type->repairCost()->toArray(),
        ], $profile->fleet());

        $shipTypes = array_map(static fn (ShipType $type) => [
            'type' => $type->value,
            'length' => $type->length(),
            'cargoCapacity' => $type->cargoCapacity(),
            // nośnik broni = eskorta konwoju (#47)
            'armed' => $type->isArmed(),
            'buildCost' => $type->buildCost()->toArray(),
            'repairCost' => $type->repairCost()->toArray(),
            'requiredRank' => $type->requiredRank()->value,
            'requiredShipyardLevel' => $type->requiredShipyardLevel(),
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
                // wyspy pod banderą (z katalogu wysp)
                'ownedIslands' => $owned,
            ],
            'fleet' => $fleet,
            'shipTypes' => $shipTypes,
            'buildingTypes' => $buildingTypes,
            'islands' => $islands,
            'world' => [
                'width' => WorldMap::WIDTH,
                'height' => WorldMap::HEIGHT,
                'position' => $position,
                'discovered' => $profile->discoveredSectors(),
                // sektory, na których kapitan był — tam sztorm już nie grozi
                'visited' => $profile->visitedSectors(),
                'atIsland' => $world->islandAt($position['x'], $position['y']),
                'stormChance' => 20,
                // zegar świata: rejs i bitwa kosztują tik
                'tick' => $profile->tick(),
            ],
            'counterattack' => $this->counterattackView($profile, $world),
        ];
    }

    /**
     * Zapowiedź kontrataku (wyspa, za ile tików uderzenie, bonus pełnej
     * bandery) oraz ostatnie rozstrzygnięcie — także utrata wyspy pod
     * nieobecność kapitana.
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
            ] : null,
            'last' => null !== $last ? [
                'island' => $last['island'],
                'islandName' => $this->islandName($last['island']),
                'tick' => $last['tick'],
                'held' => $last['held'],
                'absent' => $last['absent'],
                'stockpileLost' => $last['stockpileLost'],
            ] : null,
        ];
    }

    private function islandName(string $islandId): string
    {
        return $this->islands->byId($islandId)->name ?? $islandId;
    }
}
