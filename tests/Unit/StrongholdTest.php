<?php

use App\Domain\Expedition\BattleKind;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\LogEvent;
use App\Domain\Expedition\MistSeaIslandCatalog;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\StaticIslandCatalog;
use App\Domain\Expedition\StormOceanIslandCatalog;
use App\Domain\Shared\GameId;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;

/*
 * Twierdze z fortem (#120): potyczka z flotą oblega wyspę, banderę stawia
 * dopiero wygrane oblężenie, a przegrane zostawia wyspę oblężoną.
 */

function besiege(CaptainProfile $profile, string $islandId = 'twierdza-admiralicji'): void
{
    $gameId = GameId::new();
    $profile->startBattle($islandId, $gameId, stronghold: true);
    $profile->settleBattle($gameId, 'won', 30);
}

/** @return list<string> */
function logEvents(CaptainProfile $profile): array
{
    return array_map(static fn ($entry) => $entry->event->value, $profile->log());
}

it('stawia forty dokładnie na trzech twierdzach', function () {
    $strongholds = [];
    foreach ([new StaticIslandCatalog(), new StormOceanIslandCatalog(), new MistSeaIslandCatalog()] as $catalog) {
        foreach ($catalog->all() as $island) {
            if ($island->stronghold) {
                $strongholds[] = $island->id;
            }
        }
    }

    expect($strongholds)->toBe(['twierdza-admiralicji', 'bastion-fal', 'cytadela-mgiel']);
});

it('wygrana potyczka na twierdzy oblega wyspę zamiast stawiać banderę', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $profile->startBattle('twierdza-admiralicji', $gameId, stronghold: true);
    $outcome = $profile->settleBattle($gameId, 'won', 30, new Resources(wood: 15));

    expect($outcome['captured'])->toBeFalse()
        ->and($outcome['besieged'])->toBeTrue()
        ->and($profile->ownsIsland('twierdza-admiralicji'))->toBeFalse()
        ->and($profile->isBesieged('twierdza-admiralicji'))->toBeTrue()
        ->and($profile->stockpile('twierdza-admiralicji')->wood)->toBe(15)
        ->and(logEvents($profile))->toBe([LogEvent::IslandBesieged->value]);

    // oblężonej wyspy nie atakuje się drugi raz
    expect(fn () => $profile->startBattle('twierdza-admiralicji', GameId::new(), stronghold: true))
        ->toThrow(DomainException::class, 'Island already besieged');
});

it('przegrana potyczka nie oblega twierdzy', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $profile->startBattle('twierdza-admiralicji', $gameId, stronghold: true);
    $outcome = $profile->settleBattle($gameId, 'lost', 30);

    expect($outcome['besieged'])->toBeFalse()
        ->and($profile->isBesieged('twierdza-admiralicji'))->toBeFalse()
        ->and(fn () => $profile->startSiege('twierdza-admiralicji', GameId::new()))
        ->toThrow(DomainException::class, 'Island not besieged');
});

it('wyspa bez fortu nadal pada po jednej wygranej', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $profile->startBattle('mielizny', $gameId);
    $outcome = $profile->settleBattle($gameId, 'won', 40);

    expect($outcome['captured'])->toBeTrue()
        ->and($outcome['besieged'])->toBeFalse()
        ->and($profile->ownsIsland('mielizny'))->toBeTrue();
});

it('przegrane oblężenie zostawia wyspę oblężoną, a wygrane stawia banderę', function () {
    $profile = CaptainProfile::create('Jacek');
    besiege($profile);
    $kuter = $profile->fleet()[0];

    $siege = GameId::new();
    $profile->startSiege('twierdza-admiralicji', $siege, [$kuter->id]);
    expect($profile->battleKindFor($siege))->toBe(BattleKind::Siege)
        ->and($profile->siegeAttempts('twierdza-admiralicji'))->toBe(1);
    $lost = $profile->settleBattle($siege, 'lost', 30, sunkLengths: [2]);

    expect($lost['captured'])->toBeFalse()
        ->and($lost['lost'])->toBe(['kuter'])
        ->and($profile->fleet())->not->toContain($kuter)
        ->and($profile->isBesieged('twierdza-admiralicji'))->toBeTrue()
        ->and($profile->ownsIsland('twierdza-admiralicji'))->toBeFalse()
        ->and(logEvents($profile))->toBe([LogEvent::IslandBesieged->value, LogEvent::SiegeFailed->value]);

    $again = GameId::new();
    $profile->startSiege('twierdza-admiralicji', $again);
    expect($profile->siegeAttempts('twierdza-admiralicji'))->toBe(2);
    $won = $profile->settleBattle($again, 'won', 120, new Resources(gold: 18));

    expect($won['captured'])->toBeTrue()
        ->and($won['besieged'])->toBeFalse()
        ->and($profile->ownsIsland('twierdza-admiralicji'))->toBeTrue()
        ->and($profile->isBesieged('twierdza-admiralicji'))->toBeFalse()
        ->and($profile->battleStats('twierdza-admiralicji'))->toBe(['wins' => 2, 'losses' => 1])
        ->and(logEvents($profile))->toContain(LogEvent::IslandCaptured->value)
        ->and(fn () => $profile->startSiege('twierdza-admiralicji', GameId::new()))
        ->toThrow(DomainException::class, 'Island already captured');
});

it('oblężona twierdza nie jest celem kontrataku, bo nie ma bandery', function () {
    $profile = CaptainProfile::create('Jacek');
    besiege($profile);

    expect($profile->ownedIslands())->toBe([])
        ->and($profile->besiegedIslands())->toHaveKey('twierdza-admiralicji');
});

it('oblężenie i znacznik potyczki przechodzą przez snapshot, a stary profil się wczytuje', function () {
    $mapper = new ProfileSnapshotMapper();
    $profile = CaptainProfile::create('Jacek');
    besiege($profile);
    $pending = GameId::new();
    $profile->startBattle('bastion-fal', $pending, stronghold: true);

    $restored = $mapper->toDomain((string) $profile->id(), json_decode(json_encode($mapper->toArray($profile)), true));
    expect($restored->besiegedIslands())->toBe($profile->besiegedIslands())
        ->and($restored->settleBattle($pending, 'won', 50)['besieged'])->toBeTrue()
        ->and($restored->isBesieged('bastion-fal'))->toBeTrue();

    $old = $mapper->toArray(CaptainProfile::create('Stary'));
    unset($old['besieged']);
    expect($mapper->toDomain((string) GameId::new(), $old)->besiegedIslands())->toBe([]);
});
