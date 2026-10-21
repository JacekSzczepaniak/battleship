<?php

declare(strict_types=1);

use App\Domain\Expedition\Rank;

it('wyznacza rangę z XP wg progów', function (int $xp, Rank $expected) {
    expect(Rank::fromXp($xp))->toBe($expected);
})->with([
    [0, Rank::Rozbitek],
    [29, Rank::Rozbitek],
    [30, Rank::Majtek],
    [79, Rank::Majtek],
    [80, Rank::Marynarz],
    [149, Rank::Marynarz],
    [150, Rank::Bosman],
    [250, Rank::Sternik],
    [400, Rank::Porucznik],
    [599, Rank::Porucznik],
    [600, Rank::Kapitan],
    [900, Rank::Komandor],
    [1299, Rank::Komandor],
    [1300, Rank::Admiral],
    [10000, Rank::Admiral],
]);

it('porównuje rangi progami', function () {
    expect(Rank::Kapitan->atLeast(Rank::Marynarz))->toBeTrue()
        ->and(Rank::Kapitan->atLeast(Rank::Kapitan))->toBeTrue()
        ->and(Rank::Rozbitek->atLeast(Rank::Marynarz))->toBeFalse()
        ->and(Rank::Porucznik->atLeast(Rank::Kapitan))->toBeFalse();
});

it('zna następną rangę, a admirał jest ostatni', function () {
    expect(Rank::Rozbitek->next())->toBe(Rank::Majtek)
        ->and(Rank::Porucznik->next())->toBe(Rank::Kapitan)
        ->and(Rank::Komandor->next())->toBe(Rank::Admiral)
        ->and(Rank::Admiral->next())->toBeNull();
});

it('ma dziewięć stopni z rosnącym zyskiem XP do kolejnej rangi', function () {
    $ordered = Rank::ordered();
    expect($ordered)->toHaveCount(9)
        ->and($ordered)->toBe(Rank::cases());

    $gaps = [];
    for ($i = 1, $n = count($ordered); $i < $n; ++$i) {
        $gaps[] = $ordered[$i]->threshold() - $ordered[$i - 1]->threshold();
    }
    $sorted = $gaps;
    sort($sorted);

    // ściśle rosnący zysk XP między kolejnymi rangami
    expect($gaps)->toBe($sorted)
        ->and(count(array_unique($gaps)))->toBe(count($gaps));
});
