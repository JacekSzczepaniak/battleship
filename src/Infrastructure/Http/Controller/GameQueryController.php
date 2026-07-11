<?php

namespace App\Infrastructure\Http\Controller;

use App\Domain\Game\GameRepository;
use App\Domain\Game\Orientation;
use App\Domain\Shared\GameId;
use App\Infrastructure\Http\Error\ApiException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/games')]
final class GameQueryController
{
    public function __construct(private GameRepository $repo)
    {
    }

    #[Route('/{id}', name: 'api_games_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            throw new ApiException('Invalid game id', 'INVALID_GAME_ID', 400);
        }

        $game = $this->repo->get(new GameId($id));
        if (!$game) {
            throw new \InvalidArgumentException('Game not found');
        }

        $size = $game->ruleset()->boardSize();
        $shots = $game->shotsWithResults();
        $oppShots = $game->opponentShotsWithResults();

        // hits/misses sets
        $hits = [];
        $misses = [];
        foreach ($shots as $s) {
            $coord = [$s['x'], $s['y']];
            if ('hit' === $s['result'] || 'sunk' === $s['result']) {
                $hits[] = $coord;
            } elseif ('miss' === $s['result']) {
                $misses[] = $coord;
            }
        }

        // sunk ships cells
        $sunk = [];
        $hitSet = [];
        foreach ($hits as $h) {
            $hitSet[$h[0].':'.$h[1]] = true;
        }

        $fleet = $game->fleet() ?? [];
        foreach ($fleet as $ship) {
            $cells = [];
            $allHit = true;
            for ($i = 0; $i < $ship->length; ++$i) {
                $x = $ship->start->x + (Orientation::H === $ship->orientation ? $i : 0);
                $y = $ship->start->y + (Orientation::V === $ship->orientation ? $i : 0);
                $cells[] = [$x, $y];
                if (!isset($hitSet["$x:$y"])) {
                    $allHit = false;
                }
            }
            if ($allHit) {
                $sunk[] = ['cells' => $cells];
            }
        }

        // Finished raportujemy wprost z domeny
        $finished = $game->isFinished();

        // player fleet export (same format as POST /fleet payload items)
        $playerFleet = array_map(static function ($s) {
            return [
                'x' => $s->start->x,
                'y' => $s->start->y,
                'o' => $s->orientation->value,
                'l' => $s->length,
            ];
        }, $fleet);

        $turn = $finished ? 'none' : $game->turn();

        return new JsonResponse([
            'id' => (string) $game->id(),
            'status' => $game->status()->value,
            'board' => ['w' => $size->width, 'h' => $size->height],
            'mode' => $game->mode(),
            'opponent' => $game->opponent(),
            'turn' => $turn,
            'playerFleet' => $playerFleet,
            'enemyFogGrid' => [
                'hits' => $hits,
                'misses' => $misses,
                'sunk' => $sunk,
            ],
            // overlay trafień/pudeł przeciwnika na planszy gracza
            'playerUnderFireGrid' => [
                'hits' => array_values(array_map(static fn (array $s) => [$s['x'], $s['y']], array_filter($oppShots, static fn (array $s) => in_array($s['result'], ['hit', 'sunk'], true)))),
                'misses' => array_values(array_map(static fn (array $s) => [$s['x'], $s['y']], array_filter($oppShots, static fn (array $s) => 'miss' === $s['result']))),
            ],
            'shotsCount' => count($shots),
            'finished' => $finished,
        ]);
    }
}
