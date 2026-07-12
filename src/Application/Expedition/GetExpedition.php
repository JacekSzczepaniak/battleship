<?php

namespace App\Application\Expedition;

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
     * Stan wyprawy: profil (XP, ranga, materiały), flota, katalog typów
     * statków (do UI stoczni) i wyspy z kłódkami.
     *
     * @return array{profile: array<string,mixed>, fleet: list<array<string,mixed>>, shipTypes: list<array<string,mixed>>, islands: list<array<string,mixed>>, world: array<string,mixed>}
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
        foreach ($this->islands->all() as $island) {
            $stats = $profile->battleStats($island->id);
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
                'materialsWin' => $island->materialsWin,
                'materialsLoss' => $island->materialsLoss,
                'shipyardLevel' => $island->shipyardLevel,
                'board' => ['w' => $island->boardWidth, 'h' => $island->boardHeight],
                'unlocked' => $island->isAccessibleFor($rank),
                'wins' => $stats['wins'],
                'losses' => $stats['losses'],
            ];
        }

        $fleet = array_map(static fn ($ship) => [
            'id' => $ship->id,
            'type' => $ship->type->value,
            'length' => $ship->type->length(),
            'damaged' => $ship->isDamaged(),
            'repairCost' => $ship->type->repairCost(),
        ], $profile->fleet());

        $shipTypes = array_map(static fn (ShipType $type) => [
            'type' => $type->value,
            'length' => $type->length(),
            'buildCost' => $type->buildCost(),
            'repairCost' => $type->repairCost(),
            'requiredRank' => $type->requiredRank()->value,
            'requiredShipyardLevel' => $type->requiredShipyardLevel(),
        ], ShipType::cases());

        return [
            'profile' => [
                'id' => (string) $profile->id(),
                'name' => $profile->name(),
                'xp' => $profile->xp(),
                'rank' => $rank->value,
                'nextRank' => null !== $next
                    ? ['rank' => $next->value, 'xpNeeded' => max(0, $next->threshold() - $profile->xp())]
                    : null,
                'materials' => $profile->materials(),
            ],
            'fleet' => $fleet,
            'shipTypes' => $shipTypes,
            'islands' => $islands,
            'world' => [
                'width' => WorldMap::WIDTH,
                'height' => WorldMap::HEIGHT,
                'position' => $position,
                'discovered' => $profile->discoveredSectors(),
                'atIsland' => $world->islandAt($position['x'], $position['y']),
                'stormChance' => 20,
            ],
        ];
    }
}
