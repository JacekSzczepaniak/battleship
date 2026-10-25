<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Shared\ProfileId;

final class ExploreWildIsland
{
    public const START = 'start';
    public const DEEPER = 'deeper';
    public const RETURN = 'return';
    public const ACTIONS = [self::START, self::DEEPER, self::RETURN];

    public function __construct(
        private ProfileRepository $profiles,
        private WorldFactory $worldFactory,
    ) {
    }

    /**
     * Ekspedycja w głąb dzikiej wyspy akwenu floty (#144): start (etap 1),
     * „głębiej” albo „wracaj”. Obecność, ryzyko, nagrody, skarb i tiki liczy
     * domena (CaptainProfile::startInlandExpedition / goDeeperInland / returnFromInland).
     *
     * @return array<string, mixed>
     */
    public function handle(string $profileId, string $islandId, string $action): array
    {
        $profile = $this->profiles->get(new ProfileId($profileId));
        if (null === $profile) {
            throw new \DomainException('Profile not found');
        }
        $island = $this->worldFactory->seaFor($profile)->wildIsland;
        if (null === $island || $island->id !== $islandId) {
            throw new \DomainException('Wild island not found');
        }

        if (self::RETURN === $action) {
            $outcome = $profile->returnFromInland($island);
            $view = [
                'stage' => null,
                'result' => 'returned',
                'reward' => null,
                'damagedShip' => null,
                'treasure' => null,
                'received' => $outcome['received']->toArray(),
                'lost' => $outcome['lost']->isZero() ? null : $outcome['lost']->toArray(),
            ];
        } else {
            $world = $this->worldFactory->worldFor($profile);
            $outcome = match ($action) {
                self::START => $profile->startInlandExpedition($island, $world),
                self::DEEPER => $profile->goDeeperInland($island, $world),
                default => throw new \InvalidArgumentException('Unknown inland action'),
            };
            $view = [
                'stage' => $outcome['stage'],
                // passed | completed | failed
                'result' => $outcome['result'],
                'reward' => $outcome['reward']->toArray(),
                'damagedShip' => $outcome['damagedShip'],
                'treasure' => $outcome['treasure'],
                'received' => $outcome['received']?->toArray(),
                'lost' => null !== $outcome['lost'] && !$outcome['lost']->isZero() ? $outcome['lost']->toArray() : null,
            ];
        }
        $this->profiles->save($profile);

        $inland = $profile->inlandExpedition();

        return $view + [
            'expedition' => $inland?->toArray(),
            'collected' => ($inland->collected ?? Resources::none())->toArray(),
            'cargo' => $profile->cargo()->toArray(),
            'tick' => $profile->tick(),
        ];
    }
}
