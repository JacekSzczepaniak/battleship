<?php

namespace App\Infrastructure\Http\Controller;

use App\Domain\Game\FleetComposition;
use App\Domain\Game\Game;
use App\Domain\Game\GameRepository;
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

        // sunk ships cells — liczone względem floty PRZECIWNIKA (to w nią strzela gracz);
        // fallback na flotę gracza dla starych gier bez floty przeciwnika
        $sunk = [];
        $hitSet = [];
        foreach ($hits as $h) {
            $hitSet[$h[0].':'.$h[1]] = true;
        }

        $targetFleet = $game->opponentFleet() ?? $game->fleet() ?? [];
        foreach ($targetFleet as $ship) {
            $cells = [];
            $allHit = true;
            foreach ($ship->cells() as $c) {
                $cells[] = [$c->x, $c->y];
                if (!isset($hitSet["{$c->x}:{$c->y}"])) {
                    $allHit = false;
                }
            }
            if ($allHit) {
                $sunk[] = ['cells' => $cells];
            }
        }

        // Finished raportujemy wprost z domeny
        $finished = $game->isFinished();

        // player fleet export (same format as POST /fleet payload items;
        // `w` tylko przy wyposażeniu innym niż domyślne dla długości, #95)
        $playerFleet = array_map(static function ($s) {
            $item = [
                'x' => $s->start->x,
                'y' => $s->start->y,
                'o' => $s->orientation->value,
                'l' => $s->length,
            ];
            if (!$s->hasDefaultLoadout()) {
                $item['w'] = $s->loadout->toArray();
            }

            return $item;
        }, $game->fleet() ?? []);

        $turn = $finished ? 'none' : $game->turn();

        return new JsonResponse([
            'id' => (string) $game->id(),
            'status' => $game->status()->value,
            'board' => ['w' => $size->width, 'h' => $size->height],
            'ruleset' => $game->ruleset()->name(),
            // skład floty do wystawienia: mapa długość => liczba sztuk
            'allowedShips' => $game->ruleset()->allowedShips(),
            // skład floty przeciwnika; różni się od allowedShips przy bonusie pełnej bandery (#79)
            'opponentShips' => $game->ruleset()->opponentShips(),
            // warianty wyposażenia (#95/#99): długość => wyposażenia odbiegające od domyślnego
            'variants' => FleetComposition::variantsToArray($game->ruleset()->variants()),
            'opponentVariants' => FleetComposition::variantsToArray($game->ruleset()->opponentVariants()),
            'weapons' => $game->weaponsState(),
            'opponentWeapons' => $game->opponentWeaponsState(),
            // energia gracza w bieżącej turze (#59); null = bitwa bez energii (classic)
            'energy' => $game->energyState(),
            // ruch statku wykorzystany w tej turze (#60); bez energii ruchu nie ma
            'moveUsed' => $game->playerMovedThisTurn(),
            // abordaż (#118): dostępność, próg k6, modyfikatory, wynik próby; null = bitwa bez abordażu
            'boarding' => $game->boardingState(),
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
            // bitwa oblężnicza (#119); null = zwykła bitwa z flotą
            'fort' => $this->fortView($game, $hits),
        ]);
    }

    /**
     * Fort we mgle: licznik dział, rodzaj konstrukcji tylko na trafionych
     * polach (trafienie odsłania mur) i pola eksplozji, gdy magazyn wybuchł.
     *
     * @param list<array{0:int,1:int}> $hits
     *
     * @return array{seed:int, guns:array{left:int,total:int}, revealed:list<array{x:int,y:int,kind:string}>, explosion:list<array{0:int,1:int}>|null}|null
     */
    private function fortView(Game $game, array $hits): ?array
    {
        $fort = $game->fort();
        if (null === $fort) {
            return null;
        }

        $revealed = [];
        foreach ($hits as [$x, $y]) {
            $piece = $fort->pieceAt($x, $y);
            if (null !== $piece) {
                $revealed[] = ['x' => $x, 'y' => $y, 'kind' => $piece->value];
            }
        }
        $size = $game->ruleset()->boardSize();

        return [
            'seed' => $fort->seed,
            'guns' => ['left' => $game->fortGunsLeft(), 'total' => count($fort->guns)],
            'revealed' => $revealed,
            'explosion' => $game->fortExploded()
                ? array_map(static fn ($c) => [$c->x, $c->y], $fort->blastCells($size->width, $size->height))
                : null,
        ];
    }
}
