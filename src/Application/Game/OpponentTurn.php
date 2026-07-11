<?php

namespace App\Application\Game;

use App\Domain\Game\AI\HuntTargetAI;
use App\Domain\Game\Area;
use App\Domain\Game\BoardReadModel;
use App\Domain\Game\Coordinate;
use App\Domain\Game\Direction;
use App\Domain\Game\Game;
use App\Domain\Game\ShotResult;

/**
 * Tura przeciwnika (AI) po ofensywnym ruchu gracza.
 *
 * W trybie fun AI korzysta z broni specjalnych (te same limity i reguły co gracz):
 * - sonar: zwiad w trybie hunt — wykryte statki trafiają do kolejki dobijania
 *   (jak u gracza nie zużywa akcji ofensywnej),
 * - torpeda: z pozycji własnego niezatopionego statku, w linię z największą
 *   liczbą nieostrzelanych pól,
 * - nalot: w najgęstszy nieostrzelany obszar (rozmiar maksymalny z rulesetu).
 * Bronie tylko w trybie hunt (przy dobijaniu zwykły strzał jest skuteczniejszy),
 * z losową częstością — AI nie może być przewidywalne.
 */
final class OpponentTurn
{
    private const SONAR_CHANCE = 40;
    private const TORPEDO_CHANCE = 35;
    private const AIR_RAID_CHANCE = 25;

    /** Torpeda tylko, gdy linia ma co najmniej tyle nieostrzelanych pól. */
    private const TORPEDO_MIN_UNTRIED = 5;

    /** Nalot tylko, gdy obszar nalotu ma co najmniej tyle nieostrzelanych pól. */
    private const AIR_RAID_MIN_UNTRIED = 6;

    public function __construct(private readonly WeaponUseDecider $decider = new RandomWeaponUseDecider())
    {
    }

    /**
     * @return array{finished:bool, win:bool, loss:bool, turn:string, opponentMoves:list<array{x:int,y:int,result:string}>, opponentTorpedoLaunch: array{x:int,y:int}|null}
     */
    public function respond(Game $game): array
    {
        $finished = $game->isFinished();
        $opponentMoves = [];
        $torpedoLaunch = null;

        if (!$finished) {
            $ai = HuntTargetAI::fromSnapshot($game->aiState());
            ['moves' => $opponentMoves, 'torpedoLaunch' => $torpedoLaunch] = $this->act($game, $ai);
            $game->setAiState($ai->toSnapshot());

            $finished = $game->isFinished();
        }

        // Po ruchu przeciwnika tura wraca do gracza (o ile gra nie skończona)
        $turn = $finished ? 'none' : 'player';
        $game->setTurn($turn);

        return [
            'finished' => $finished,
            'win' => 'won' === $game->status()->value,
            'loss' => 'lost' === $game->status()->value,
            'turn' => $turn,
            'opponentMoves' => $opponentMoves,
            // koszt torpedy: zdradza wyrzutnię — gracz widzi, skąd strzelono
            'opponentTorpedoLaunch' => null !== $torpedoLaunch
                ? ['x' => $torpedoLaunch->x, 'y' => $torpedoLaunch->y]
                : null,
        ];
    }

    /** @return array{moves: list<array{x:int,y:int,result:string}>, torpedoLaunch: Coordinate|null} */
    private function act(Game $game, HuntTargetAI $ai): array
    {
        $view = $game->opponentShotsView();
        $huntMode = [] === ($game->aiState()['targets'] ?? []);
        $weapons = 'fun' === $game->ruleset()->name() ? $game->opponentWeaponsState() : [];

        // 1) Zwiad: sonar w trybie hunt (nie zużywa akcji ofensywnej)
        if ($huntMode && $this->hasUses($weapons, 'sonar') && $this->decide(self::SONAR_CHANCE)) {
            $center = $this->bestSonarCenter($view, $game->ruleset()->weapons()->sonar->radius);
            if (null !== $center) {
                $detected = [];
                foreach ($game->opponentSonarPing($center) as $cell) {
                    $c = new Coordinate($cell['x'], $cell['y']);
                    if ($cell['occupied'] && !$view->wasTried($c)) {
                        $detected[] = $c;
                    }
                }
                $ai->enqueueTargets($detected);
                $huntMode = [] === $detected;
            }
        }

        // 2) Akcja ofensywna: torpeda / nalot (tylko hunt) albo zwykły strzał
        if ($huntMode && $this->hasUses($weapons, 'torpedo') && $this->decide(self::TORPEDO_CHANCE)) {
            $run = $this->bestTorpedoRun($game, $view, $this->hasUses($weapons, 'torpedoDiagonal'));
            if (null !== $run) {
                [$start, $direction] = $run;
                $results = $game->fireOpponentTorpedo($start, $direction);
                $this->notifyAll($ai, $results);

                // koszt torpedy: wyrzutnia AI zostaje ujawniona graczowi
                return ['moves' => $results, 'torpedoLaunch' => $start];
            }
        }

        if ($huntMode && $this->hasUses($weapons, 'airRaid') && $this->decide(self::AIR_RAID_CHANCE)) {
            [$halfX, $halfY] = $this->airRaidHalfExtents($game);
            $center = $this->bestAirRaidCenter($view, $halfX, $halfY);
            if (null !== $center) {
                $results = $game->sendOpponentAirRaid($center, new Area($halfX, $halfY));
                $this->notifyAll($ai, $results);

                return ['moves' => $results, 'torpedoLaunch' => null];
            }
        }

        $target = $ai->nextShot($view);
        $result = $game->fireOpponentShot($target);
        $ai->notify($target, $result);

        return [
            'moves' => [['x' => $target->x, 'y' => $target->y, 'result' => $result->value]],
            'torpedoLaunch' => null,
        ];
    }

    /** @param list<array{x:int,y:int,result:string}> $results */
    private function notifyAll(HuntTargetAI $ai, array $results): void
    {
        foreach ($results as $r) {
            $ai->notify(new Coordinate($r['x'], $r['y']), ShotResult::from($r['result']));
        }
    }

    /** @param array<string, array{used:int, limit:int}> $weapons */
    private function hasUses(array $weapons, string $name): bool
    {
        return isset($weapons[$name]) && $weapons[$name]['used'] < $weapons[$name]['limit'];
    }

    private function decide(int $percent): bool
    {
        return $this->decider->decide($percent);
    }

    /** Środek krzyża sonaru maksymalizujący nieostrzelane pola. */
    private function bestSonarCenter(BoardReadModel $view, int $radius): ?Coordinate
    {
        $best = null;
        $bestScore = 0;

        for ($y = 0; $y < $view->height(); ++$y) {
            for ($x = 0; $x < $view->width(); ++$x) {
                $score = $this->countUntried($view, $this->sonarCross($x, $y, $radius));
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = new Coordinate($x, $y);
                }
            }
        }

        return $best;
    }

    /**
     * Najlepsza linia torpedy: start z niezatopionego statku AI, maksimum
     * nieostrzelanych pól; null, gdy żadna linia nie przekracza progu.
     *
     * @return array{0: Coordinate, 1: Direction}|null
     */
    private function bestTorpedoRun(Game $game, BoardReadModel $view, bool $diagonalAllowed): ?array
    {
        $best = null;
        $bestScore = self::TORPEDO_MIN_UNTRIED - 1;

        foreach ($game->opponentLaunchableCells() as $cell) {
            foreach (Direction::cases() as $direction) {
                if ($direction->isDiagonal() && !$diagonalAllowed) {
                    continue;
                }
                [$dx, $dy] = $direction->vector();

                $score = 0;
                $cx = $cell['x'];
                $cy = $cell['y'];
                while ($cx >= 0 && $cy >= 0 && $cx < $view->width() && $cy < $view->height()) {
                    if (!$view->wasTried(new Coordinate($cx, $cy))) {
                        ++$score;
                    }
                    $cx += $dx;
                    $cy += $dy;
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = [new Coordinate($cell['x'], $cell['y']), $direction];
                }
            }
        }

        return $best;
    }

    /** Centrum nalotu maksymalizujące nieostrzelane pola; null poniżej progu. */
    private function bestAirRaidCenter(BoardReadModel $view, int $halfX, int $halfY): ?Coordinate
    {
        $best = null;
        $bestScore = self::AIR_RAID_MIN_UNTRIED - 1;

        for ($y = 0; $y < $view->height(); ++$y) {
            for ($x = 0; $x < $view->width(); ++$x) {
                $cells = [];
                for ($dx = -$halfX; $dx <= $halfX; ++$dx) {
                    for ($dy = -$halfY; $dy <= $halfY; ++$dy) {
                        $cells[] = [$x + $dx, $y + $dy];
                    }
                }
                $score = $this->countUntried($view, $cells);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = new Coordinate($x, $y);
                }
            }
        }

        return $best;
    }

    /**
     * Pół-zasięgi maksymalnego nalotu z rulesetu (pełny rozmiar → half-extents).
     *
     * @return array{0:int, 1:int}
     */
    private function airRaidHalfExtents(Game $game): array
    {
        $max = $game->ruleset()->weapons()->airRaid->maxArea;

        return [intdiv(max(0, $max->width - 1), 2), intdiv(max(0, $max->height - 1), 2)];
    }

    /** @return list<array{0:int,1:int}> komórki krzyża sonaru o zadanym promieniu */
    private function sonarCross(int $x, int $y, int $radius): array
    {
        $cells = [[$x, $y]];
        for ($i = 1; $i <= $radius; ++$i) {
            $cells[] = [$x, $y - $i];
            $cells[] = [$x + $i, $y];
            $cells[] = [$x, $y + $i];
            $cells[] = [$x - $i, $y];
        }

        return $cells;
    }

    /** @param list<array{0:int,1:int}> $cells */
    private function countUntried(BoardReadModel $view, array $cells): int
    {
        $count = 0;
        foreach ($cells as [$x, $y]) {
            if ($x < 0 || $y < 0 || $x >= $view->width() || $y >= $view->height()) {
                continue;
            }
            if (!$view->wasTried(new Coordinate($x, $y))) {
                ++$count;
            }
        }

        return $count;
    }
}
