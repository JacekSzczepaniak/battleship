<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\BuildingType;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\ChartCondition;
use App\Domain\Expedition\EncounterCatalog;
use App\Domain\Expedition\EncounterType;
use App\Domain\Expedition\FleetPreset;
use App\Domain\Expedition\IslandCatalog;
use App\Domain\Expedition\LogEntry;
use App\Domain\Expedition\Prize;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Reputation;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\SeaGraph;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\ShipVariant;
use App\Domain\Expedition\TechBranch;
use App\Domain\Expedition\TechLevel;
use App\Domain\Expedition\Technology;
use App\Domain\Expedition\TechnologyCondition;
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
     * Spotkanie z rejsu (#141) przychodzi ze skutkami każdego wyboru.
     *
     * @return array{profile: array<string,mixed>, fleet: list<array<string,mixed>>, shipTypes: list<array<string,mixed>>, buildingTypes: list<array<string,mixed>>, islands: list<array<string,mixed>>, world: array<string,mixed>, counterattack: array<string,mixed>, encounter: array<string,mixed>|null, research: array<string,mixed>, log: list<array<string,mixed>>}
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
                // twierdza z fortem (#120): wygrana potyczka oblega wyspę, banderę daje oblężenie
                'stronghold' => $island->stronghold,
                'besieged' => $profile->isBesieged($island->id),
                // skład oblężenia mieści się w limicie planszy fortu; null = wyspa bez fortu
                'siegeSquadLimit' => $island->stronghold ? StartSiege::SQUAD_CELL_LIMIT : null,
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
            // nośnik broni = eskorta konwoju; wariant bez broni (kuter remontowy, #152) nim nie jest
            'armed' => $ship->isArmed(),
            'damaged' => $ship->isDamaged(),
            'repairCost' => $profile->repairCost($ship->type)->toArray(),
            // złomowanie (#124): zwrot do magazynu wyspy i blokada domeny; stocznię w porcie sprawdza widok
            'scrapValue' => $profile->scrapValue($ship)->toArray(),
            'scrapBlock' => $profile->scrapBlocker($ship)?->value,
            // sprzedaż w porcie handlowym akwenu (#140): złoto za statek; null = akwen bez portu
            'saleValue' => null !== $sea->tradePort ? $profile->shipSaleValue($ship, $sea->tradePort->rate) : null,
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
            // wariant statku budowanego bez wyboru; null = statek podstawowy obok wariantów (#121)
            'defaultVariant' => ShipVariant::defaultFor($type)?->value,
            // warianty do wyboru przy budowie; zablokowany czeka na technologię (z uczelni albo ze zdobyczy, #121)
            'variants' => array_map(static fn (ShipVariant $variant) => [
                'variant' => $variant->value,
                'requiredTechnology' => $variant->requiredTechnology()?->value,
                'unlock' => $variant->requiredTechnology()?->unlock()->value,
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
                // naprawy kutrów remontowych poza stocznią (#152); przełącznik w zakładce Flota
                'fieldRepairs' => $profile->fieldRepairsEnabled(),
                'enemyShipsSunk' => $profile->enemyShipsSunk(),
                // wyspy pod banderą na wszystkich akwenach
                'ownedIslands' => count(array_filter(
                    $this->islands->all(),
                    static fn ($island) => $profile->ownsIsland($island->id),
                )),
                // mapy akwenów w profilu (#143): przedmiot i warunek bramy „wody nieznane”
                'seaCharts' => array_map(fn (string $seaId, int $tick) => [
                    'sea' => $seaId,
                    'name' => $this->seas->sea($seaId)->name ?? $seaId,
                    'tick' => $tick,
                ], array_keys($profile->seaCharts()), array_values($profile->seaCharts())),
                // reputacja kupiec ↔ korsarz (#142): stan, skutki i progi podane z góry (zasada 4)
                'reputation' => self::reputationView($profile->reputation()),
            ],
            'fleet' => $fleet,
            // statki zdobyte abordażem, czekające na decyzję (#118); scrapValue = zwrot z rozbiórki,
            // studyTechnology = technologia z „Zbadaj konstrukcję” (#121), null = nie ma czego badać
            'prizes' => array_map(static fn (Prize $prize) => $prize->toArray() + [
                'length' => $prize->type->length(),
                'scrapValue' => $prize->scrapValue()->toArray(),
                'studyTechnology' => $profile->studyableTechnology($prize)?->value,
            ], $profile->prizes()),
            // zapisane zestawy floty z rozmieszczeniem (#125)
            'fleetPresets' => array_map(static fn (FleetPreset $preset) => $preset->toArray(), $profile->fleetPresets()),
            // skład nierozliczonych bitew (gameId → id statków) — rozstawianie łączy statki planszy z flotą (#125)
            'battleSquads' => (object) array_map(
                static fn (array $battle) => $battle['ships'] ?? [],
                array_filter($profile->battles(), static fn (array $battle) => !$battle['settled']),
            ),
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
                // port handlowy akwenu (#140): neutralna wyspa z rynkiem; null = akwen bez portu
                'tradePort' => $this->tradePortView($profile, $sea, $world),
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
            'encounter' => $this->encounterView($profile),
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
                'seaName' => isset($data['sea']) ? ($this->seas->sea((string) $data['sea'])->name ?? (string) $data['sea']) : null,
            ], static fn ($v) => null !== $v);

            return ['tick' => $entry->tick, 'event' => $entry->event->value] + $data + $names;
        }, array_reverse($profile->log()));
    }

    /**
     * Port handlowy akwenu floty (#140). Sektor widać dopiero po odkryciu,
     * jak wyspy; kurs (towar za 1 złoto) z mnożnikiem ceny kapitana.
     *
     * @return array<string,mixed>|null
     */
    private function tradePortView(CaptainProfile $profile, Sea $sea, WorldMap $world): ?array
    {
        $port = $sea->tradePort;
        $pos = $world->tradePortPosition();
        if (null === $port || null === $pos) {
            return null;
        }
        $discovered = $profile->isDiscovered($pos['x'], $pos['y']);

        return [
            'id' => $port->id,
            'name' => $port->name,
            'description' => $port->description,
            'discovered' => $discovered,
            'position' => $discovered ? $pos : null,
            'present' => $discovered && $pos === $profile->position(),
            'rate' => $port->rate->toArray($profile->marketPricePercent()),
            // kurs bazowy akwenu: kupno liczy się od całego zlecenia (#142), więc front potrzebuje go do podglądu
            'baseRate' => $port->rate->toArray(),
            // mnożnik ceny w procentach (100 = kurs bazowy) z reputacji (#142)
            'pricePercent' => $profile->marketPricePercent(),
            // embargo (#142): port odmawia handlu, sprzedaży statku i mapy
            'embargo' => $profile->isUnderEmbargo(),
            'stockpile' => $profile->stockpile($port->id)->toArray(),
            // mapa akwenu na sprzedaż (#143); null = port bez mapy
            'chart' => null !== $port->chart ? [
                'sea' => $port->chart->seaId,
                'seaName' => $this->seas->sea($port->chart->seaId)->name ?? $port->chart->seaId,
                'price' => $profile->chartPrice($port->chart),
                'owned' => $profile->hasSeaChart($port->chart->seaId),
            ] : null,
        ];
    }

    /**
     * Spotkanie z rejsu czekające na decyzję (#141), ze skutkami wyborów
     * podanymi z góry (zasada 4): flota i nagrody bitwy (napad albo walka),
     * ceny handlarza, a u pirata koszt ucieczki i okup.
     *
     * @return array<string,mixed>|null
     */
    private function encounterView(CaptainProfile $profile): ?array
    {
        $encounter = $profile->encounter();
        if (null === $encounter) {
            return null;
        }
        $enemy = EncounterCatalog::fleet($encounter->type, $encounter->sea);
        $pirate = EncounterType::Pirate === $encounter->type;
        $merchant = EncounterType::Merchant === $encounter->type;
        $rate = $this->seas->sea($encounter->sea)?->tradePort?->rate;
        $ransom = EncounterCatalog::ransom($encounter->sea);

        return [
            'type' => $encounter->type->value,
            'tick' => $encounter->tick,
            'sea' => ['id' => $encounter->sea, 'name' => $this->seas->sea($encounter->sea)->name ?? $encounter->sea],
            'position' => ['x' => $encounter->x, 'y' => $encounter->y],
            // bitwa: napad na handlarza albo walka z piratem; za przegraną tylko XP
            'battle' => [
                'name' => $enemy->name,
                'mode' => $enemy->mode,
                'fleet' => array_map(static fn (ShipType $t) => $t->value, $enemy->fleet),
                'board' => ['w' => $enemy->boardWidth, 'h' => $enemy->boardHeight],
                'squadLimit' => $enemy->squadCellLimit(),
                'xpWin' => $enemy->xpWin,
                'xpLoss' => $enemy->xpLoss,
                'lootWin' => $enemy->lootWin->toArray(),
                'lootLoss' => $enemy->lootLoss->toArray(),
            ],
            // handel na miejscu: towar za 1 złoto u handlarza (rate) i w porcie akwenu (portRate, #140);
            // zlecenie w sztukach, kupno płaci za każdą zaczętą porcję, sprzedaż dostaje za pełne
            'trade' => !$merchant || null === $rate ? null : [
                'rate' => $rate->toArray($profile->merchantPrices($rate)->pricePercent),
                'portRate' => $rate->toArray($profile->marketPricePercent()),
                'worsePercent' => EncounterCatalog::MERCHANT_WORSE_PERCENT,
                'ticks' => EncounterCatalog::TRADE_TICKS,
            ],
            // ucieczka: tiki i ładunek, który zabierze; okup: złoto z ładowni
            'flee' => $pirate ? ['ticks' => EncounterCatalog::FLEE_TICKS, 'cargoLost' => $profile->fleeCargoLoss()->toArray()] : null,
            'ransom' => $pirate ? ['gold' => $ransom, 'affordable' => $profile->cargo()->gold >= $ransom] : null,
            // skutki wyborów dla reputacji (#142): bitwa (napad od razu, pirat po wygranej), handel
            'reputation' => [
                'battle' => match ($encounter->type) {
                    EncounterType::Merchant => Reputation::RAID,
                    EncounterType::Pirate => Reputation::PIRATE_DEFEATED,
                    EncounterType::BountyHunters => 0,
                },
                'trade' => $merchant ? Reputation::TRADE : null,
            ],
        ];
    }

    /**
     * Reputacja z jej skutkami i progami (#142): wartość, poziom, mnożnik ceny,
     * embargo, zmiany za zdarzenia i progi osi.
     *
     * @return array<string,mixed>
     */
    private static function reputationView(Reputation $reputation): array
    {
        return [
            'value' => $reputation->value,
            'tier' => $reputation->tier()->value,
            'pricePercent' => $reputation->pricePercent(),
            'embargo' => $reputation->isEmbargo(),
            'min' => Reputation::MIN,
            'max' => Reputation::MAX,
            'changes' => ['trade' => Reputation::TRADE, 'pirateDefeated' => Reputation::PIRATE_DEFEATED, 'raid' => Reputation::RAID],
            'thresholds' => ['merchant' => Reputation::MERCHANT_FROM, 'corsair' => Reputation::CORSAIR_FROM, 'embargo' => Reputation::EMBARGO_FROM],
            'prices' => ['merchant' => Reputation::MERCHANT_PRICE_PERCENT, 'corsair' => Reputation::CORSAIR_PRICE_PERCENT],
        ];
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
                // null = otwarta; rank | guardian | technology | chart
                'blocker' => $blocker?->value,
                'open' => null === $blocker,
                // technologia, która otwiera bramę (otwarty ocean); null = inny warunek
                'technology' => $gate->condition instanceof TechnologyCondition ? $gate->condition->technology->value : null,
                // akwen, którego mapy wymaga brama (wody nieznane, #143); null = inny warunek
                'chart' => $gate->condition instanceof ChartCondition ? $gate->condition->seaId : null,
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
                'level' => $t->level()?->value,
                // academy | boarding: technologia ze zdobyczy jest poza poziomami drzewka (#121)
                'unlock' => $t->unlock()->value,
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
                'ticks' => $current['technology']->academyLevel()->researchTicks(),
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
        // port handlowy nie należy do katalogu wysp (#140)
        return $this->islands->byId($islandId)->name ?? $this->seas->tradePort($islandId)->name ?? $islandId;
    }
}
