<?php
declare(strict_types=1);

use App\Application\Game\PlaceFleet;
use App\Application\Game\CreateGame;
use App\Infrastructure\Persistence\InMemory\InMemoryGameRepository;

it("PlaceFleet zapisuje poprawnie rozstawioną flotę", function () {
    $repo = new InMemoryGameRepository();
    $create = new CreateGame($repo);
    $game = $create->handle(12,10);

    $handler = new PlaceFleet($repo);
    $ships = [
        ["x"=>0,"y"=>0,"o"=>"H","l"=>4],
        ["x"=>0,"y"=>2,"o"=>"H","l"=>3],
        ["x"=>5,"y"=>2,"o"=>"V","l"=>3],
        ["x"=>8,"y"=>0,"o"=>"V","l"=>2],
        ["x"=>10,"y"=>5,"o"=>"H","l"=>2],
        ["x"=>2,"y"=>7,"o"=>"H","l"=>2],
        ["x"=>0,"y"=>5,"o"=>"H","l"=>1],
        ["x"=>5,"y"=>7,"o"=>"H","l"=>1],
        ["x"=>7,"y"=>7,"o"=>"H","l"=>1],
        ["x"=>11,"y"=>9,"o"=>"H","l"=>1]
    ];

    $handler->handle((string)$game->id(), $ships);

    $reloaded = $repo->get($game->id());
    expect($reloaded)->not->toBeNull();
    expect($reloaded->fleet())->not->toBeNull();
});
