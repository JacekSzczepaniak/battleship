<?php

namespace App\Infrastructure\Persistence\Doctrine\Mapper;

use App\Domain\Expedition\BuildingType;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\LogEntry;
use App\Domain\Expedition\LogEvent;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\SeaState;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\ShipVariant;
use App\Domain\Expedition\Technology;
use App\Domain\Shared\ProfileId;

final class ProfileSnapshotMapper
{
    /** @return array<string,mixed> */
    public function toArray(CaptainProfile $profile): array
    {
        return [
            'name' => $profile->name(),
            'xp' => $profile->xp(),
            'battles' => $profile->battles(),
            'cargo' => $profile->cargo()->toArray(),
            'stockpiles' => array_map(static fn (Resources $r) => $r->toArray(), $profile->stockpiles()),
            // wariant tylko przy statku typu z wariantami; brak klucza = wariant domyślny (#99)
            'fleet' => array_map(static fn (OwnedShip $ship) => array_filter([
                'id' => $ship->id,
                'type' => $ship->type->value,
                'damaged' => $ship->isDamaged(),
                'variant' => $ship->variant?->value,
            ], static fn ($v) => null !== $v), $profile->fleet()),
            'worldSeed' => $profile->worldSeed(),
            // akweny (Epic I): akwen floty i stan każdego akwenu — pozycja, odkryte i odwiedzone sektory
            'sea' => $profile->currentSea(),
            'seas' => array_map(static fn (SeaState $state) => [
                'position' => $state->position(),
                'discovered' => $state->discoveredSectors(),
                'visited' => $state->visitedSectors(),
            ], $profile->seaStates()),
            // bramy otwarte na stałe: id bramy → tik otwarcia (#107)
            'gates' => $profile->openedGates(),
            'moveCount' => $profile->moveCount(),
            'tick' => $profile->tick(),
            // surowce czekające na magazyn wyspy — rozłoży je ensureWorldState()
            'unplaced' => $profile->unplacedResources()?->toArray(),
            // złoża wysp: tik ostatniego naliczenia urobku
            'depositTicks' => $profile->depositTicks(),
            // wyspy pod banderą: id wyspy → tik zdobycia
            'ownedIslands' => $profile->ownedIslands(),
            // kontratak w toku, spokój, rzuty i ostatnie rozstrzygnięcie (#78)
            'counterattacks' => $profile->counterattackState(),
            // budynki osady: id wyspy → typy budynków
            'buildings' => array_map(
                static fn (array $types) => array_map(static fn (BuildingType $t) => $t->value, $types),
                $profile->buildings(),
            ),
            // badania (Epic G): zbadane technologie i badanie w toku
            'research' => $profile->researchState(),
            // dziennik kapitana (#111): ostatnie zdarzenia, najstarsze pierwsze
            'log' => array_map(static fn (LogEntry $entry) => [
                'tick' => $entry->tick,
                'event' => $entry->event->value,
                'data' => $entry->data,
            ], $profile->log()),
        ];
    }

    /** @param array<string,mixed> $state */
    public function toDomain(string $id, array $state): CaptainProfile
    {
        $battles = [];
        foreach ((array) ($state['battles'] ?? []) as $gameId => $battle) {
            if (!is_array($battle) || !isset($battle['island'])) {
                continue;
            }
            $entry = [
                'island' => (string) $battle['island'],
                'settled' => (bool) ($battle['settled'] ?? false),
                'result' => isset($battle['result']) ? (string) $battle['result'] : null,
            ];
            if (isset($battle['kind'])) {
                $entry['kind'] = (string) $battle['kind'];
            }
            if (isset($battle['ships']) && is_array($battle['ships'])) {
                $entry['ships'] = array_values(array_map('strval', $battle['ships']));
            }
            if (isset($battle['enemySunk'])) {
                $entry['enemySunk'] = max(0, (int) $battle['enemySunk']);
            }
            $battles[(string) $gameId] = $entry;
        }

        // Flota: brak klucza = profil sprzed ekonomii (CaptainProfile da flotę startową)
        $fleet = null;
        if (isset($state['fleet']) && is_array($state['fleet'])) {
            $fleet = [];
            foreach ($state['fleet'] as $ship) {
                $type = is_array($ship) ? ShipType::tryFrom((string) ($ship['type'] ?? '')) : null;
                if (null === $type || !isset($ship['id'])) {
                    continue;
                }
                // niszczyciel sprzed wariantów jest torpedowy; wariant obcego typu się pomija
                $variant = ShipVariant::tryFrom((string) ($ship['variant'] ?? ''));
                $fleet[] = new OwnedShip((string) $ship['id'], $type, (bool) ($ship['damaged'] ?? false), $variant?->type() === $type ? $variant : null);
            }
        }

        // akweny: brak klucza `seas` = profil sprzed akwenów, którego płaskie pola są stanem Morza Rozbitków
        $sea = isset($state['sea']) ? (string) $state['sea'] : null;
        $seaStates = $this->seaStatesFrom($state);
        $here = null !== $sea && isset($state['seas'][$sea]) && is_array($state['seas'][$sea]) ? $state['seas'][$sea] : $state;
        unset($seaStates[$sea ?? '']);

        $position = $this->positionFrom($here);

        $discovered = null;
        if (isset($here['discovered']) && is_array($here['discovered'])) {
            $discovered = array_values(array_map('strval', $here['discovered']));
        }

        $cargo = isset($state['cargo']) && is_array($state['cargo']) ? Resources::fromArray($state['cargo']) : null;

        return CaptainProfile::fromSnapshot(
            new ProfileId($id),
            (string) ($state['name'] ?? 'Rozbitek'),
            (int) ($state['xp'] ?? 0),
            $battles,
            $fleet,
            $cargo,
            isset($state['worldSeed']) ? (int) $state['worldSeed'] : null,
            $position,
            $discovered,
            (int) ($state['moveCount'] ?? 0),
            // brak klucza = profil sprzed tiku; CaptainProfile wyliczy go z historii
            isset($state['tick']) ? (int) $state['tick'] : null,
            // brak klucza = profil sprzed odwiedzin; CaptainProfile przyjmie obecną pozycję
            isset($here['visited']) && is_array($here['visited']) ? array_values(array_map('strval', $here['visited'])) : null,
            $this->stockpilesFrom($state),
            $this->unplacedFrom($state, $cargo),
            array_map('intval', (array) ($state['depositTicks'] ?? [])),
            // brak klucza = brak bander (bez migracji — stare profile są testowe, #77)
            array_map('intval', (array) ($state['ownedIslands'] ?? [])),
            // brak klucza = profil sprzed kontrataków; CaptainProfile zacznie rzuty od bieżącego tiku
            $this->counterattacksFrom($state),
            $this->buildingsFrom($state),
            ...$this->researchFrom($state),
            sea: $sea,
            seaStates: $seaStates,
            // brak klucza = profil sprzed bram, żadna nie jest otwarta
            openedGates: array_map('intval', (array) ($state['gates'] ?? [])),
            // brak klucza = profil sprzed dziennika, dziennik zaczyna się pusty
            log: $this->logFrom($state),
        );
    }

    /**
     * Dziennik kapitana; wpis z nieznanym rodzajem zdarzenia jest pomijany.
     *
     * @param array<string,mixed> $state
     *
     * @return list<LogEntry>
     */
    private function logFrom(array $state): array
    {
        $out = [];
        foreach ((array) ($state['log'] ?? []) as $entry) {
            $event = is_array($entry) ? LogEvent::tryFrom((string) ($entry['event'] ?? '')) : null;
            if (null === $event || !isset($entry['tick'])) {
                continue;
            }
            $out[] = new LogEntry((int) $entry['tick'], $event, (array) ($entry['data'] ?? []));
        }

        return $out;
    }

    /**
     * Stan akwenów zapisany pod kluczem `seas` (klucz: id akwenu); stan
     * akwenu floty wyjmuje toDomain() osobno.
     *
     * @param array<string,mixed> $state
     *
     * @return array<string, SeaState>
     */
    private function seaStatesFrom(array $state): array
    {
        $out = [];
        foreach ((array) ($state['seas'] ?? []) as $seaId => $sea) {
            if (!is_array($sea)) {
                continue;
            }
            $out[(string) $seaId] = new SeaState(
                $this->positionFrom($sea),
                array_values(array_map('strval', (array) ($sea['discovered'] ?? []))),
                array_values(array_map('strval', (array) ($sea['visited'] ?? []))),
            );
        }

        return $out;
    }

    /**
     * @param array<mixed> $state
     *
     * @return array{x:int,y:int}|null
     */
    private function positionFrom(array $state): ?array
    {
        $position = $state['position'] ?? null;

        return is_array($position) && isset($position['x'], $position['y'])
            ? ['x' => (int) $position['x'], 'y' => (int) $position['y']]
            : null;
    }

    /**
     * Badania; brak klucza = profil sprzed uczelni, bez badań. Nieznana
     * technologia jest pomijana, a badanie w toku z nieznaną technologią przepada.
     *
     * @param array<string,mixed> $state
     *
     * @return array{researched: list<Technology>, research: array{technology: Technology, island: string, progress: int, countedTo: int}|null}
     */
    private function researchFrom(array $state): array
    {
        $raw = (array) ($state['research'] ?? []);

        $researched = [];
        foreach ((array) ($raw['researched'] ?? []) as $value) {
            $technology = Technology::tryFrom((string) $value);
            if (null !== $technology && !in_array($technology, $researched, true)) {
                $researched[] = $technology;
            }
        }

        $current = $raw['current'] ?? null;
        $technology = is_array($current) ? Technology::tryFrom((string) ($current['technology'] ?? '')) : null;
        $research = null !== $technology && isset($current['island']) && !in_array($technology, $researched, true) ? [
            'technology' => $technology,
            'island' => (string) $current['island'],
            'progress' => max(0, (int) ($current['progress'] ?? 0)),
            'countedTo' => (int) ($current['countedTo'] ?? 0),
        ] : null;

        return ['researched' => $researched, 'research' => $research];
    }

    /**
     * Budynki osady; brak klucza = profil sprzed osad, bez budynków. Nieznany
     * typ i powtórzony typ na tej samej wyspie są pomijane.
     *
     * @param array<string,mixed> $state
     *
     * @return array<string, list<BuildingType>>
     */
    private function buildingsFrom(array $state): array
    {
        $out = [];
        foreach ((array) ($state['buildings'] ?? []) as $islandId => $types) {
            $island = [];
            foreach ((array) $types as $value) {
                $type = BuildingType::tryFrom((string) $value);
                if (null !== $type && !in_array($type, $island, true)) {
                    $island[] = $type;
                }
            }
            if ([] !== $island) {
                $out[(string) $islandId] = $island;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $state
     *
     * @return array{pending: array{island: string, announcedAt: int, strikeAt: int, defense: ?string}|null, calmFrom: int, rolledTo: int, last: array{island: string, tick: int, held: bool, absent: bool, plundered: bool, stockpileLost: array{wood:int, steel:int, gold:int}}|null}|null
     */
    private function counterattacksFrom(array $state): ?array
    {
        $raw = $state['counterattacks'] ?? null;
        if (!is_array($raw)) {
            return null;
        }

        $pending = $raw['pending'] ?? null;
        $last = $raw['last'] ?? null;

        return [
            'pending' => is_array($pending) && isset($pending['island'], $pending['announcedAt'], $pending['strikeAt']) ? [
                'island' => (string) $pending['island'],
                'announcedAt' => (int) $pending['announcedAt'],
                'strikeAt' => (int) $pending['strikeAt'],
                'defense' => isset($pending['defense']) ? (string) $pending['defense'] : null,
            ] : null,
            'calmFrom' => (int) ($raw['calmFrom'] ?? 0),
            'rolledTo' => (int) ($raw['rolledTo'] ?? 0),
            'last' => is_array($last) && isset($last['island'], $last['tick']) ? [
                'island' => (string) $last['island'],
                'tick' => (int) $last['tick'],
                'held' => (bool) ($last['held'] ?? false),
                'absent' => (bool) ($last['absent'] ?? false),
                // rozstrzygnięcie sprzed fortu (#81) nie było grabieżą
                'plundered' => (bool) ($last['plundered'] ?? false),
                'stockpileLost' => Resources::fromArray((array) ($last['stockpileLost'] ?? []))->toArray(),
            ] : null,
        ];
    }

    /**
     * @param array<string,mixed> $state
     *
     * @return array<string, Resources>
     */
    private function stockpilesFrom(array $state): array
    {
        $out = [];
        foreach ((array) ($state['stockpiles'] ?? []) as $islandId => $amounts) {
            if (is_array($amounts)) {
                $out[(string) $islandId] = Resources::fromArray($amounts);
            }
        }

        return $out;
    }

    /**
     * Surowce czekające na magazyn wyspy; brak ładowni w snapshotcie = profil
     * sprzed #46, którego jeden magazyn też rozłoży ensureWorldState().
     *
     * @param array<string,mixed> $state
     */
    private function unplacedFrom(array $state, ?Resources $cargo): ?Resources
    {
        if (isset($state['unplaced']) && is_array($state['unplaced'])) {
            return Resources::fromArray($state['unplaced']);
        }

        return null === $cargo ? $this->legacyResourcesFrom($state) : null;
    }

    /**
     * Jeden magazyn profilu sprzed ładowni: `resources` (#45) albo jeszcze
     * starsze `materials`, które przechodzą w drewno (GAME_DESIGN.md, filar IV).
     *
     * @param array<string,mixed> $state
     */
    private function legacyResourcesFrom(array $state): ?Resources
    {
        if (isset($state['resources']) && is_array($state['resources'])) {
            return Resources::fromArray($state['resources']);
        }
        if (isset($state['materials'])) {
            return new Resources(wood: max(0, (int) $state['materials']));
        }

        return null;
    }
}
