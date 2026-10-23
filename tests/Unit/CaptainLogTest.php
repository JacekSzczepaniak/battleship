<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\LogEntry;
use App\Domain\Expedition\LogEvent;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\ShipType;
use App\Domain\Shared\ProfileId;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;

/** @param list<LogEntry> $log */
function loggedCaptain(int $tick, array $log): CaptainProfile
{
    return CaptainProfile::fromSnapshot(ProfileId::new(), 'Jacek', 0, [], null, Resources::none(), 7, tick: $tick, log: $log);
}

/** @return list<array{0:int, 1:LogEvent}> */
function logLine(CaptainProfile $profile): array
{
    return array_map(static fn (LogEntry $e) => [$e->tick, $e->event], $profile->log());
}

it('dziennik trzyma 50 ostatnich wpisów — najstarsze odpadają', function () {
    $storms = array_map(static fn (int $t) => new LogEntry($t, LogEvent::Storm, ['effect' => 'ship-damaged', 'ship' => 'tratwa']), range(1, 49));
    $profile = loggedCaptain(60, $storms);

    foreach (range(1, 3) as $ignored) {
        $profile->buildShip(ShipType::Tratwa, 'zatoka-rozbitka');
    }

    expect($profile->log())->toHaveCount(CaptainProfile::LOG_LIMIT)
        ->and($profile->log()[0]->tick)->toBe(3)
        ->and(array_slice(logLine($profile), -3))->toBe([[60, LogEvent::ShipBuilt], [60, LogEvent::ShipBuilt], [60, LogEvent::ShipBuilt]])
        ->and($profile->log()[49]->data)->toBe(['ship' => 'tratwa', 'island' => 'zatoka-rozbitka']);
});

it('wpis z wcześniejszego tiku (zdarzenie liczone leniwie) trafia przed późniejsze, za równe', function () {
    $profile = loggedCaptain(5, [
        new LogEntry(5, LogEvent::GateOpened, ['gate' => 'strzezona-ciesnina']),
        new LogEntry(8, LogEvent::IslandCaptured, ['island' => 'mielizny']),
        new LogEntry(2, LogEvent::IslandCaptured, ['island' => 'zatoka-rozbitka']),
    ]);

    $profile->buildShip(ShipType::Tratwa, 'zatoka-rozbitka');

    expect(logLine($profile))->toBe([
        [2, LogEvent::IslandCaptured],
        [5, LogEvent::GateOpened],
        [5, LogEvent::ShipBuilt],
        [8, LogEvent::IslandCaptured],
    ]);
});

it('dziennik przechodzi przez snapshot, a profil sprzed dziennika wczytuje się z pustym', function () {
    $m = new ProfileSnapshotMapper();
    $profile = loggedCaptain(12, [
        new LogEntry(4, LogEvent::Storm, ['effect' => 'cargo-lost', 'cargoLost' => ['wood' => 5, 'steel' => 0, 'gold' => 0], 'unescorted' => false]),
        new LogEntry(9, LogEvent::IslandPlundered, ['island' => 'mielizny', 'stockpileLost' => ['wood' => 3, 'steel' => 0, 'gold' => 0]]),
    ]);

    $state = $m->toArray($profile);
    $restored = $m->toDomain((string) $profile->id(), json_decode(json_encode($state, JSON_THROW_ON_ERROR), true));
    unset($state['log']);
    $legacy = $m->toDomain((string) $profile->id(), $state);

    expect($restored->log())->toEqual($profile->log())
        ->and($legacy->log())->toBe([]);
});
