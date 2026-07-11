<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\IslandCatalog;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Shared\ProfileId;

final class GetExpedition
{
    public function __construct(
        private ProfileRepository $profiles,
        private IslandCatalog $islands,
    ) {
    }

    /**
     * Stan wyprawy: profil (XP, ranga, postęp do następnej) + wyspy z kłódkami.
     *
     * @return array{profile: array<string,mixed>, islands: list<array<string,mixed>>}
     */
    public function handle(string $profileId): array
    {
        $profile = $this->profiles->get(new ProfileId($profileId));
        if (null === $profile) {
            throw new \DomainException('Profile not found');
        }

        $rank = $profile->rank();
        $next = $rank->next();

        $islands = [];
        foreach ($this->islands->all() as $island) {
            $stats = $profile->battleStats($island->id);
            $islands[] = [
                'id' => $island->id,
                'name' => $island->name,
                'description' => $island->description,
                'mode' => $island->mode,
                'requiredRank' => $island->requiredRank->value,
                'xpWin' => $island->xpWin,
                'xpLoss' => $island->xpLoss,
                'unlocked' => $island->isAccessibleFor($rank),
                'wins' => $stats['wins'],
                'losses' => $stats['losses'],
            ];
        }

        return [
            'profile' => [
                'id' => (string) $profile->id(),
                'name' => $profile->name(),
                'xp' => $profile->xp(),
                'rank' => $rank->value,
                'nextRank' => null !== $next
                    ? ['rank' => $next->value, 'xpNeeded' => max(0, $next->threshold() - $profile->xp())]
                    : null,
            ],
            'islands' => $islands,
        ];
    }
}
