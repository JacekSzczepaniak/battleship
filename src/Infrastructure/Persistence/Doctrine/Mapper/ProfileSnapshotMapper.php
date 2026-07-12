<?php

namespace App\Infrastructure\Persistence\Doctrine\Mapper;

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\ShipType;
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
            'materials' => $profile->materials(),
            'fleet' => array_map(static fn (OwnedShip $ship) => [
                'id' => $ship->id,
                'type' => $ship->type->value,
                'damaged' => $ship->isDamaged(),
            ], $profile->fleet()),
            'worldSeed' => $profile->worldSeed(),
            'position' => $profile->hasWorldState() ? $profile->position() : null,
            'discovered' => $profile->discoveredSectors(),
            'moveCount' => $profile->moveCount(),
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
            if (isset($battle['ships']) && is_array($battle['ships'])) {
                $entry['ships'] = array_values(array_map('strval', $battle['ships']));
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
                $fleet[] = new OwnedShip((string) $ship['id'], $type, (bool) ($ship['damaged'] ?? false));
            }
        }

        $position = null;
        if (isset($state['position']) && is_array($state['position'])
            && isset($state['position']['x'], $state['position']['y'])) {
            $position = ['x' => (int) $state['position']['x'], 'y' => (int) $state['position']['y']];
        }

        $discovered = null;
        if (isset($state['discovered']) && is_array($state['discovered'])) {
            $discovered = array_values(array_map('strval', $state['discovered']));
        }

        return CaptainProfile::fromSnapshot(
            new ProfileId($id),
            (string) ($state['name'] ?? 'Rozbitek'),
            (int) ($state['xp'] ?? 0),
            $battles,
            $fleet,
            isset($state['materials']) ? max(0, (int) $state['materials']) : null,
            isset($state['worldSeed']) ? (int) $state['worldSeed'] : null,
            $position,
            $discovered,
            (int) ($state['moveCount'] ?? 0),
        );
    }
}
