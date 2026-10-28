<?php

declare(strict_types=1);

use App\Application\Game\CreateGame;
use App\Domain\Game\BoardSize;
use App\Domain\Game\Coordinate;
use App\Domain\Game\FunRuleset;
use App\Domain\Game\Game;
use App\Domain\Game\Orientation;
use App\Domain\Game\Ship;
use App\Domain\Game\Siege\Fort;
use App\Domain\Game\Siege\FortGenerator;
use App\Domain\Game\Siege\FortRules;
use App\Domain\Game\Siege\SiegeTech;
use App\Infrastructure\Persistence\Doctrine\Mapper\GameSnapshotMapper;
use App\Infrastructure\Persistence\InMemory\InMemoryGameRepository;
use Tests\Support\FleetFactory;

// Technologie Oblężnictwa (#177): Lunety artyleryjskie i Lonty prochowe.

/**
 * Fort z magazynem przy krawędzi (0,1): działo (2,3) stoi w 5×5, ale poza 3×3;
 * mur (1,2)–(1,3) w obu. Pozostałe działa daleko od magazynu.
 */
function edgeFort(int $seed = 3): Fort
{
    return new Fort(
        $seed,
        [new Coordinate(2, 3), new Coordinate(9, 9), new Coordinate(9, 0), new Coordinate(5, 9)],
        [new Ship(new Coordinate(1, 2), Orientation::V, 2)],
        new Coordinate(0, 1),
    );
}

function techSiege(Fort $fort, ?SiegeTech $tech = null): Game
{
    $game = Game::create(new FunRuleset(new BoardSize(10, 10)));
    $game->placeFort($fort, $tech);
    $game->placeFleet(FleetFactory::classic10x10());

    return $game;
}

it('Lunety: namierzone działo wynika z seeda fortu i jest jednym z jego dział', function () {
    $gen = new FortGenerator();
    $picked = [];
    foreach (range(1, 40) as $i) {
        $fort = $gen->generate($i * 7919);
        expect($fort->guns)->toContainEqual($fort->spottedGun())
            ->and($gen->generate($i * 7919)->spottedGun())->toEqual($fort->spottedGun());
        $picked[array_search($fort->spottedGun(), $fort->guns, false)] = true;
    }

    // nie zawsze to samo działo z listy
    expect(count($picked))->toBeGreaterThan(1);
});

it('Lunety: oblężenie zaczyna się z jednym namierzonym, ale nietrafionym działem', function () {
    $fort = edgeFort(seed: 5);
    $game = techSiege($fort, new SiegeTech(spyglasses: true));

    expect($game->spottedFortGun())->toEqual($fort->spottedGun())
        ->and($game->spottedFortGun())->toEqual(new Coordinate(9, 9))
        ->and($game->fortGunsLeft())->toBe(4)
        ->and($game->shots())->toBe([]);
});

it('bez Lunet żadne działo nie jest namierzone', function () {
    expect(techSiege(edgeFort())->spottedFortGun())->toBeNull()
        ->and(techSiege(edgeFort(), SiegeTech::none())->spottedFortGun())->toBeNull();
});

it('Lonty: wybuch magazynu przy krawędzi obejmuje 5×5 przycięte do planszy', function () {
    $cells = edgeFort()->blastCells(10, 10, SiegeTech::POWDER_FUSE_BLAST_RADIUS);
    $keys = array_map(static fn (Coordinate $c) => "$c->x:$c->y", $cells);

    // x 0..2, y 0..3: kolumny -2 i -1 oraz wiersz -1 wypadają poza planszę
    expect($cells)->toHaveCount(12)
        ->and($keys)->toContain('0:0', '2:3', '2:0', '0:3')
        ->and($keys)->not->toContain('3:3', '2:4')
        ->and(edgeFort()->blastCells(10, 10))->toHaveCount(6);
});

it('Lonty: trafiony magazyn niszczy działo, którego wybuch 3×3 nie sięga', function () {
    $plain = techSiege(edgeFort());
    $plain->fireShot(new Coordinate(0, 1));

    $fused = techSiege(edgeFort(), new SiegeTech(blastRadius: SiegeTech::POWDER_FUSE_BLAST_RADIUS));
    $fused->fireShot(new Coordinate(0, 1));

    expect($plain->fortExploded())->toBeTrue()
        ->and($plain->fortGunsLeft())->toBe(4)
        ->and($plain->fortBlastCells())->toHaveCount(6)
        ->and($fused->fortExploded())->toBeTrue()
        ->and($fused->fortGunsLeft())->toBe(3)
        ->and($fused->fortBlastRadius())->toBe(2)
        ->and($fused->fortBlastCells())->toHaveCount(12);
});

it('snapshot zapisuje promień wybuchu i namierzone działo, a stary snapshot wczytuje wartości domyślne', function () {
    $mapper = new GameSnapshotMapper();
    $game = techSiege(edgeFort(seed: 5), new SiegeTech(spyglasses: true, blastRadius: 2));

    $state = $mapper->toArray($game);
    $restored = $mapper->toDomain((string) $game->id(), $state);

    expect($state['siegeTech'])->toBe(['blastRadius' => 2, 'spotted' => [9, 9]])
        ->and($restored->fortBlastRadius())->toBe(2)
        ->and($restored->spottedFortGun())->toEqual(new Coordinate(9, 9));

    $plainState = $mapper->toArray(techSiege(edgeFort()));
    unset($state['siegeTech']);
    $old = $mapper->toDomain((string) $game->id(), $state);

    expect($plainState['siegeTech'])->toBeNull()
        ->and($old->fortBlastRadius())->toBe(FortRules::EXPLOSION_RADIUS)
        ->and($old->spottedFortGun())->toBeNull();
});

it('CreateGame przekazuje technologie oblężnicze do fortu; bez nich fort jak dotąd', function () {
    $create = new CreateGame(new InMemoryGameRepository());
    $tech = new SiegeTech(spyglasses: true, blastRadius: 2);

    $siege = $create->handle(mode: 'fun', fortSeed: 11, siegeTech: $tech);
    $plain = $create->handle(mode: 'fun', fortSeed: 11);

    expect($siege->spottedFortGun())->toEqual((new FortGenerator())->generate(11)->spottedGun())
        ->and($siege->fortBlastRadius())->toBe(2)
        ->and($plain->spottedFortGun())->toBeNull()
        ->and($plain->fortBlastRadius())->toBe(FortRules::EXPLOSION_RADIUS);
});
