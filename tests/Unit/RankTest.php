<?php

declare(strict_types=1);

use App\Domain\Expedition\Rank;

it('wyznacza rangę z XP wg progów', function (int $xp, Rank $expected) {
    expect(Rank::fromXp($xp))->toBe($expected);
})->with([
    [0, Rank::Rozbitek],
    [79, Rank::Rozbitek],
    [80, Rank::Marynarz],
    [249, Rank::Marynarz],
    [250, Rank::Kapitan],
    [499, Rank::Kapitan],
    [500, Rank::Admiral],
    [10000, Rank::Admiral],
]);

it('porównuje rangi progami', function () {
    expect(Rank::Kapitan->atLeast(Rank::Marynarz))->toBeTrue()
        ->and(Rank::Kapitan->atLeast(Rank::Kapitan))->toBeTrue()
        ->and(Rank::Rozbitek->atLeast(Rank::Marynarz))->toBeFalse();
});

it('zna następną rangę, a admirał jest ostatni', function () {
    expect(Rank::Rozbitek->next())->toBe(Rank::Marynarz)
        ->and(Rank::Kapitan->next())->toBe(Rank::Admiral)
        ->and(Rank::Admiral->next())->toBeNull();
});
