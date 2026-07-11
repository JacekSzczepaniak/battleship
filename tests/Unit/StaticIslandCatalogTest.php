<?php

declare(strict_types=1);

use App\Domain\Expedition\Rank;
use App\Domain\Expedition\StaticIslandCatalog;

it('trasa jest liniowa: wymagane rangi nie maleją', function () {
    $islands = (new StaticIslandCatalog())->all();

    expect($islands)->not->toBeEmpty();

    $previous = 0;
    foreach ($islands as $island) {
        expect($island->requiredRank->threshold())->toBeGreaterThanOrEqual($previous);
        $previous = $island->requiredRank->threshold();
    }
});

it('pierwsza wyspa jest dostępna dla rozbitka, ostatnia nie', function () {
    $islands = (new StaticIslandCatalog())->all();

    expect($islands[0]->isAccessibleFor(Rank::Rozbitek))->toBeTrue()
        ->and(end($islands)->isAccessibleFor(Rank::Rozbitek))->toBeFalse()
        ->and(end($islands)->isAccessibleFor(Rank::Kapitan))->toBeTrue();
});

it('znajduje wyspę po id i zwraca null dla nieznanej', function () {
    $catalog = new StaticIslandCatalog();

    expect($catalog->byId('zatoka-rozbitka')?->name)->toBe('Zatoka Rozbitka')
        ->and($catalog->byId('atlantyda'))->toBeNull();
});

it('suma XP za wygrane pozwala osiągnąć rangę admirała z niewielką powtórką', function () {
    $winXp = array_sum(array_map(fn ($i) => $i->xpWin, (new StaticIslandCatalog())->all()));

    // jedno przejście trasy daje większość drogi do admirała (500 XP)
    expect($winXp)->toBeGreaterThanOrEqual(400);
});
