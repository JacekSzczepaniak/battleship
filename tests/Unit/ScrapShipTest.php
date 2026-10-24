<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\LogEvent;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\Prize;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\ScrapBlock;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\ShipVariant;
use App\Domain\Expedition\Technology;
use App\Domain\Shared\GameId;

// Złomowanie statku za część kosztu (#124).

/**
 * @param list<OwnedShip>                                                                                           $fleet
 * @param list<Technology>                                                                                          $researched
 * @param array<string, array{island: string, settled: bool, result: ?string, kind?: string, ships?: list<string>}> $battles
 */
function scrapper(array $fleet, array $researched = [], array $battles = [], ?Resources $cargo = null): CaptainProfile
{
    return CaptainProfile::fromSnapshot(
        CaptainProfile::create('Jacek')->id(), 'Jacek', 0, $battles, $fleet, $cargo ?? Resources::none(),
        tick: 7,
        stockpiles: ['zatoka-rozbitka' => new Resources(wood: 5)],
        researched: $researched,
    );
}

function damagedShip(ShipType $type, ?ShipVariant $variant = null): OwnedShip
{
    $ship = OwnedShip::build($type, $variant);
    $ship->markDamaged();

    return $ship;
}

it('wartość złomu typu to połowa kosztu budowy z zaokrągleniem w dół, tak samo jak rozbiórka zdobyczy', function () {
    expect(ShipType::Kuter->scrapValue())->toEqual(new Resources(wood: 10))
        ->and(ShipType::Niszczyciel->scrapValue())->toEqual(new Resources(wood: 15, steel: 10))
        ->and(ShipType::Lotniskowiec->scrapValue())->toEqual(new Resources(wood: 30, steel: 30))
        ->and(ShipType::Transportowiec->scrapValue())->toEqual(new Resources(wood: 20, steel: 5))
        ->and(ShipType::Tratwa->scrapValue())->toEqual(Resources::none())
        ->and(Prize::capture(ShipType::Niszczyciel, null, 0)->scrapValue())->toEqual(ShipType::Niszczyciel->scrapValue());
});

it('sprawny statek oddaje połowę kosztu do magazynu wyspy i znika z floty', function () {
    $kuter = OwnedShip::build(ShipType::Kuter);
    $profile = scrapper([$kuter, OwnedShip::build(ShipType::Niszczyciel)]);

    $refund = $profile->scrapShip($kuter->id, 'zatoka-rozbitka');

    expect($refund)->toEqual(new Resources(wood: 10))
        ->and($profile->stockpile('zatoka-rozbitka'))->toEqual(new Resources(wood: 15))
        ->and($profile->cargo())->toEqual(Resources::none())
        ->and(array_map(static fn (OwnedShip $s) => $s->type, $profile->fleet()))->toBe([ShipType::Niszczyciel])
        ->and($profile->tick())->toBe(7);
});

it('wariant zwraca tyle co typ, bo kosztuje tyle co typ', function () {
    $zwiadowca = OwnedShip::build(ShipType::Niszczyciel, ShipVariant::Zwiadowczy);
    $profile = scrapper([$zwiadowca, OwnedShip::build(ShipType::Kuter)], [Technology::NiszczycielZwiadowczy]);

    expect($profile->scrapShip($zwiadowca->id, 'zatoka-rozbitka'))->toEqual(new Resources(wood: 15, steel: 10));
});

it('uszkodzony statek oddaje mniej o koszt remontu, najwyżej do zera', function () {
    // lotniskowiec: 30/30 − remont 20/25 = 10/5; niszczyciel: 15/10 − 10/10 = 5/0
    $profile = scrapper([damagedShip(ShipType::Lotniskowiec), damagedShip(ShipType::Niszczyciel), OwnedShip::build(ShipType::Kuter)]);
    [$lotniskowiec, $niszczyciel] = $profile->fleet();

    expect($profile->scrapValue($lotniskowiec))->toEqual(new Resources(wood: 10, steel: 5))
        ->and($profile->scrapValue($niszczyciel))->toEqual(new Resources(wood: 5));

    // kuter: 10 − 8 = 2; transportowiec: 20/5 − 15/5 = 5/0
    expect(scrapper([])->scrapValue(damagedShip(ShipType::Kuter)))->toEqual(new Resources(wood: 2))
        ->and(scrapper([])->scrapValue(damagedShip(ShipType::Transportowiec)))->toEqual(new Resources(wood: 5));
});

it('Dok remontowy obniża potrącenie za remont uszkodzonego statku', function () {
    // remont z Dokiem: 10/13, więc lotniskowiec oddaje 30/30 − 10/13 = 20/17
    $profile = scrapper([], [Technology::DokRemontowy]);

    expect($profile->scrapValue(damagedShip(ShipType::Lotniskowiec)))->toEqual(new Resources(wood: 20, steel: 17));
});

it('tratwa niczego nie zwraca, ale złomowanie usuwa ją z floty', function () {
    $tratwa = OwnedShip::build(ShipType::Tratwa);
    $profile = scrapper([$tratwa, OwnedShip::build(ShipType::Kuter)]);

    expect($profile->scrapShip($tratwa->id, 'zatoka-rozbitka'))->toEqual(Resources::none())
        ->and($profile->fleet())->toHaveCount(1)
        ->and($profile->stockpile('zatoka-rozbitka'))->toEqual(new Resources(wood: 5));
});

it('ostatniej sprawnej jednostki nie da się zezłomować, uszkodzone obok niej tak', function () {
    $kuter = OwnedShip::build(ShipType::Kuter);
    $wrak = damagedShip(ShipType::Niszczyciel);
    $profile = scrapper([$kuter, $wrak]);

    expect($profile->scrapBlocker($kuter))->toBe(ScrapBlock::LastSeaworthy)
        ->and($profile->scrapBlocker($wrak))->toBeNull()
        ->and(fn () => $profile->scrapShip($kuter->id, 'zatoka-rozbitka'))->toThrow(DomainException::class, 'Cannot scrap the last seaworthy ship');

    $profile->scrapShip($wrak->id, 'zatoka-rozbitka');
    expect($profile->fleet())->toBe([$kuter]);
});

it('ostatnia sprawna tratwa też jest bezpiecznikiem', function () {
    $tratwa = OwnedShip::build(ShipType::Tratwa);
    $profile = scrapper([$tratwa, damagedShip(ShipType::Kuter)]);

    expect($profile->scrapBlocker($tratwa))->toBe(ScrapBlock::LastSeaworthy);
});

it('statku z nierozliczonej bitwy nie da się zezłomować, po rozliczeniu już tak', function () {
    $kuter = OwnedShip::build(ShipType::Kuter);
    $niszczyciel = OwnedShip::build(ShipType::Niszczyciel);
    $profile = scrapper([$kuter, $niszczyciel, OwnedShip::build(ShipType::Tratwa)]);
    $profile->startBattle('mielizny', GameId::new(), [$kuter->id]);

    expect($profile->scrapBlocker($kuter))->toBe(ScrapBlock::InBattle)
        ->and($profile->scrapBlocker($niszczyciel))->toBeNull()
        ->and(fn () => $profile->scrapShip($kuter->id, 'zatoka-rozbitka'))->toThrow(DomainException::class, 'Ship in an unsettled battle');

    $settled = scrapper([$kuter, $niszczyciel], battles: [
        'g1' => ['island' => 'mielizny', 'settled' => true, 'result' => 'won', 'kind' => 'capture', 'ships' => [$kuter->id]],
    ]);
    expect($settled->scrapBlocker($kuter))->toBeNull();
});

it('nieznany statek daje błąd domenowy', function () {
    expect(fn () => scrapper([OwnedShip::build(ShipType::Kuter)])->scrapShip('nope', 'zatoka-rozbitka'))
        ->toThrow(DomainException::class, 'Ship not found');
});

it('ładunek ponad pojemność po złomowaniu transportowca zostaje w magazynie wyspy', function () {
    // transportowiec + kuter wożą razem więcej niż sam kuter
    $transportowiec = OwnedShip::build(ShipType::Transportowiec);
    $kuter = OwnedShip::build(ShipType::Kuter);
    $probe = scrapper([$kuter]);
    $capacity = $probe->cargoCapacity();
    $profile = scrapper([$transportowiec, $kuter], cargo: new Resources(wood: $capacity, steel: 10));

    $profile->scrapShip($transportowiec->id, 'zatoka-rozbitka');

    expect($profile->cargo()->total())->toBe($capacity)
        ->and($profile->stockpile('zatoka-rozbitka')->total() + $profile->cargo()->total())->toBe(5 + 20 + 5 + $capacity + 10);
});

it('złomowanie trafia do dziennika ze zwrotem i wyspą', function () {
    $wrak = damagedShip(ShipType::Niszczyciel, ShipVariant::Zwiadowczy);
    $profile = scrapper([$wrak, OwnedShip::build(ShipType::Kuter)]);

    $profile->scrapShip($wrak->id, 'zatoka-rozbitka');
    $entry = $profile->log()[array_key_last($profile->log())];

    expect($entry->event)->toBe(LogEvent::ShipScrapped)
        ->and($entry->tick)->toBe(7)
        ->and($entry->data)->toBe([
            'ship' => 'niszczyciel',
            'variant' => 'zwiadowczy',
            'island' => 'zatoka-rozbitka',
            'damaged' => true,
            'refund' => ['wood' => 5, 'steel' => 0, 'gold' => 0],
        ]);
});
