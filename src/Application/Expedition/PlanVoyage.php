<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Technology;
use App\Domain\Expedition\WorldMap;
use App\Domain\Shared\ProfileId;

/**
 * Plan trasy Nawigatora (#153) bez ruszania floty: kroki, koszt w tikach
 * (suma zwykłych rejsów i przejść przez bramy), sektory z ryzykiem sztormu
 * i zdążenie przed zapowiedzianym kontratakiem. Trasę płynie się zwykłymi
 * rejsami (/sail) i przejściami przez bramy krok po kroku.
 */
final class PlanVoyage
{
    public function __construct(
        private ProfileRepository $profiles,
        private WorldFactory $worldFactory,
        private NavigatorRoute $route,
    ) {
    }

    /** @return array<string,mixed> */
    public function handle(string $profileId, string $seaId, int $x, int $y, bool $avoidUnvisited): array
    {
        $profile = $this->profiles->get(new ProfileId($profileId));
        if (null === $profile) {
            throw new \DomainException('Profile not found');
        }
        if (!$profile->hasResearched(Technology::Nawigator)) {
            throw new \DomainException('Technology locked: navigator required');
        }
        $world = $this->worldFactory->worldFor($profile);
        $profile->ensureWorldState($world);
        $maps = $this->route->maps($profile);
        $target = $maps[$seaId] ?? throw new \DomainException('Sea not found');
        if (!$target->isInside($x, $y)) {
            throw new \DomainException('Sector out of bounds');
        }

        $plan = $this->route->plan($profile, $seaId, $x, $y, $avoidUnvisited);
        $reason = null;
        if (null === $plan) {
            $discovered = isset($profile->seaStates()[$seaId]) && $profile->seaStates()[$seaId]->isDiscovered($x, $y);
            $reason = match (true) {
                !$discovered => 'undiscovered',
                // bez przełącznika trasa jest, ale każda prowadzi przez sektor ze sztormem
                $avoidUnvisited && null !== $this->route->plan($profile, $seaId, $x, $y, false) => 'no-safe-route',
                default => 'no-route',
            };
        }

        return [
            'target' => ['sea' => $seaId, 'x' => $x, 'y' => $y],
            'avoidUnvisited' => $avoidUnvisited,
            'tick' => $profile->tick(),
            // undiscovered | no-route | no-safe-route; null = trasa jest
            'reason' => $reason,
            'route' => null !== $plan ? [
                'steps' => $plan['steps'],
                'ticks' => $plan['ticks'],
                'stormSectors' => $plan['stormSectors'],
                'arrivalTick' => $profile->tick() + $plan['ticks'],
                // szansa sztormu na pierwszym wpłynięciu, per akwen trasy (z Mapami sztormowymi)
                'stormChance' => array_map(
                    static fn (WorldMap $map) => $profile->stormChance($map->theme()),
                    array_intersect_key($maps, array_flip(array_unique([$profile->currentSea(), ...array_column($plan['steps'], 'sea')]))),
                ),
            ] : null,
            'counterattack' => null !== $plan ? $this->counterattack($profile->counterattack(), $profile->tick(), $plan['ticks'], $maps, $seaId, $x, $y) : null,
        ];
    }

    /**
     * Zdążenie przed zapowiedzianym kontratakiem: obronę przyjmuje się przed
     * uderzeniem, więc zdąży trasa krótsza niż czas do uderzenia (#109).
     *
     * @param array{island:string, strikeAt:int, defense:?string}|null $pending
     * @param array<string, WorldMap>                                  $maps
     *
     * @return array{island: string, strikeAt: int, ticksLeft: int, inTime: bool, targetsIsland: bool}|null
     */
    private function counterattack(?array $pending, int $tick, int $ticks, array $maps, string $seaId, int $x, int $y): ?array
    {
        if (null === $pending || null !== $pending['defense']) {
            return null;
        }
        $ticksLeft = max(0, $pending['strikeAt'] - $tick);

        return [
            'island' => $pending['island'],
            'strikeAt' => $pending['strikeAt'],
            'ticksLeft' => $ticksLeft,
            'inTime' => $ticks < $ticksLeft,
            // czy trasa prowadzi na zaatakowaną wyspę
            'targetsIsland' => $maps[$seaId]->islandPosition($pending['island']) === ['x' => $x, 'y' => $y],
        ];
    }
}
