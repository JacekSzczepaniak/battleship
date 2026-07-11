<?php

namespace App\Infrastructure\Persistence\Doctrine\Mapper;

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Shared\ProfileId;

final class ProfileSnapshotMapper
{
    /** @return array{name: string, xp: int, battles: array<string, array{island:string, settled:bool, result:?string}>} */
    public function toArray(CaptainProfile $profile): array
    {
        return [
            'name' => $profile->name(),
            'xp' => $profile->xp(),
            'battles' => $profile->battles(),
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
            $battles[(string) $gameId] = [
                'island' => (string) $battle['island'],
                'settled' => (bool) ($battle['settled'] ?? false),
                'result' => isset($battle['result']) ? (string) $battle['result'] : null,
            ];
        }

        return CaptainProfile::fromSnapshot(
            new ProfileId($id),
            (string) ($state['name'] ?? 'Rozbitek'),
            (int) ($state['xp'] ?? 0),
            $battles,
        );
    }
}
