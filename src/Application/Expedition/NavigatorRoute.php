<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\SeaGraph;
use App\Domain\Expedition\SeaState;
use App\Domain\Expedition\WorldMap;

/**
 * Trasa Nawigatora (#153): seria zwykłych rejsów i przejść przez bramy,
 * każdy krok za 1 tik. Rejs ląduje tylko na odkrytym sektorze w zasięgu
 * sailRange(), a przy kroku o 2 sektory odkryty musi być też sektor
 * pośredni (ten sam, który odkrywa CaptainProfile::sail()). Brama prowadzi
 * z jej sektora na sektor tej samej bramy po drugiej stronie, gdy kapitan
 * może ją dziś przepłynąć, a sektor za nią jest odkryty.
 *
 * Ryzyko sztormu = pierwsze wpłynięcie na sektor nieodwiedzony. Domyślnie
 * trasa jest najkrótsza w tikach, a przy remisie ma mniej takich sektorów,
 * potem mniej dokowań po drodze (wyspa, port, dzika wyspa zatrzymują trasę
 * we froncie); $avoidUnvisited zabrania lądowania na nieodwiedzonych wcale.
 * Dijkstra po węzłach (akwen, sektor) z kosztem [tiki, sztormy, dokowania].
 */
final class NavigatorRoute
{
    public function __construct(private WorldFactory $worldFactory, private SeaGraph $seas)
    {
    }

    /**
     * Trasa na sektor (x, y) akwenu $seaId; null = cel nieodkryty albo nie
     * da się do niego dopłynąć. Pusta lista kroków = flota już stoi na celu.
     *
     * @return array{steps: list<array{type: string, sea: string, x: int, y: int, gate: string|null, risk: bool, dock: bool}>, ticks: int, stormSectors: int}|null
     */
    public function plan(CaptainProfile $profile, string $seaId, int $x, int $y, bool $avoidUnvisited): ?array
    {
        $maps = $this->maps($profile);
        $states = $profile->seaStates();
        if (!isset($maps[$seaId]) || !self::discovered($states, $seaId, $x, $y)) {
            return null;
        }
        $range = $profile->sailRange();
        $start = self::key($profile->currentSea(), $profile->position());
        $goal = self::key($seaId, ['x' => $x, 'y' => $y]);

        /** @var array<string, array{ticks:int, risk:int, docks:int, prev:string|null, step:array{type: string, sea: string, x: int, y: int, gate: string|null, risk: bool, dock: bool}|null}> $best */
        $best = [$start => ['ticks' => 0, 'risk' => 0, 'docks' => 0, 'prev' => null, 'step' => null]];
        $queue = new \SplPriorityQueue();
        $seq = 0;
        $queue->insert($start, [0, 0, 0, $seq]);
        $done = [];
        while (!$queue->isEmpty()) {
            /** @var string $key */
            $key = $queue->extract();
            if (isset($done[$key])) {
                continue;
            }
            $done[$key] = true;
            if ($key === $goal) {
                break;
            }
            [$sea, $at] = self::node($key);
            foreach ($this->moves($profile, $maps, $states, $sea, $at, $range) as $step) {
                if ($avoidUnvisited && $step['risk']) {
                    continue;
                }
                $next = self::key($step['sea'], $step);
                $ticks = $best[$key]['ticks'] + 1;
                $risk = $best[$key]['risk'] + ($step['risk'] ? 1 : 0);
                // dokowanie na samym celu nie przerywa trasy
                $docks = $best[$key]['docks'] + ($step['dock'] && $next !== $goal ? 1 : 0);
                if (isset($done[$next]) || (isset($best[$next]) && [$best[$next]['ticks'], $best[$next]['risk'], $best[$next]['docks']] <= [$ticks, $risk, $docks])) {
                    continue;
                }
                $best[$next] = ['ticks' => $ticks, 'risk' => $risk, 'docks' => $docks, 'prev' => $key, 'step' => $step];
                // SplPriorityQueue wyjmuje największy priorytet: minusy dają najmniejszy koszt, a przy remisie wcześniejszy wpis
                $queue->insert($next, [-$ticks, -$risk, -$docks, -++$seq]);
            }
        }
        if (!isset($best[$goal])) {
            return null;
        }

        $steps = [];
        for ($key = $goal; null !== $best[$key]['step']; $key = (string) $best[$key]['prev']) {
            $steps[] = $best[$key]['step'];
        }
        $steps = array_reverse($steps);

        return [
            'steps' => $steps,
            'ticks' => count($steps),
            'stormSectors' => count(array_filter($steps, static fn (array $step) => $step['risk'])),
        ];
    }

    /** @return array<string, WorldMap> mapy wszystkich akwenów kapitana (klucz: id akwenu) */
    public function maps(CaptainProfile $profile): array
    {
        $maps = [];
        foreach ($this->seas->seas() as $sea) {
            $maps[$sea->id] = $this->worldFactory->mapOf($sea, $profile);
        }

        return $maps;
    }

    /**
     * Kroki z sektora: rejsy w zasięgu i przejście przez bramę stojącą na sektorze.
     *
     * @param array<string, WorldMap> $maps
     * @param array<string, SeaState> $states
     * @param array{x:int,y:int}      $at
     *
     * @return list<array{type: string, sea: string, x: int, y: int, gate: string|null, risk: bool, dock: bool}>
     */
    private function moves(CaptainProfile $profile, array $maps, array $states, string $sea, array $at, int $range): array
    {
        $map = $maps[$sea];
        $moves = [];
        for ($dy = -$range; $dy <= $range; ++$dy) {
            for ($dx = -$range; $dx <= $range; ++$dx) {
                [$tx, $ty] = [$at['x'] + $dx, $at['y'] + $dy];
                if ((0 === $dx && 0 === $dy) || !$map->isInside($tx, $ty) || !self::discovered($states, $sea, $tx, $ty)) {
                    continue;
                }
                // sektor pośredni jak w CaptainProfile::sail(): krok od startu w stronę celu
                if (2 === max(abs($dx), abs($dy)) && !self::discovered($states, $sea, $at['x'] + ($dx <=> 0), $at['y'] + ($dy <=> 0))) {
                    continue;
                }
                $moves[] = [
                    'type' => 'sail', 'sea' => $sea, 'x' => $tx, 'y' => $ty, 'gate' => null,
                    'risk' => !self::visited($states, $sea, $tx, $ty), 'dock' => self::docks($map, $tx, $ty),
                ];
            }
        }

        $gate = $map->gateAt($at['x'], $at['y']);
        $passage = null !== $gate ? $this->seas->passage($sea, $gate) : null;
        if (null !== $passage && null === $profile->gateBlocker($passage)) {
            $arrival = isset($maps[$passage->to]) ? $maps[$passage->to]->gatePosition($passage->gate->id) : null;
            if (null !== $arrival && self::discovered($states, $passage->to, $arrival['x'], $arrival['y'])) {
                $moves[] = [
                    'type' => 'gate', 'sea' => $passage->to, 'x' => $arrival['x'], 'y' => $arrival['y'], 'gate' => $passage->gate->id,
                    'risk' => !self::visited($states, $passage->to, $arrival['x'], $arrival['y']), 'dock' => false,
                ];
            }
        }

        return $moves;
    }

    /** Wyspa, port handlowy albo dzika wyspa: rejs na nią to dokowanie (sektor bramy nie). */
    private static function docks(WorldMap $map, int $x, int $y): bool
    {
        $at = ['x' => $x, 'y' => $y];

        return null !== $map->islandAt($x, $y) || $map->tradePortPosition() === $at || $map->wildIslandPosition() === $at;
    }

    /** @param array<string, SeaState> $states */
    private static function discovered(array $states, string $sea, int $x, int $y): bool
    {
        return isset($states[$sea]) && $states[$sea]->isDiscovered($x, $y);
    }

    /** @param array<string, SeaState> $states */
    private static function visited(array $states, string $sea, int $x, int $y): bool
    {
        return isset($states[$sea]) && $states[$sea]->isVisited($x, $y);
    }

    /** @param array{x:int,y:int} $at */
    private static function key(string $sea, array $at): string
    {
        return "$sea@{$at['x']}:{$at['y']}";
    }

    /** @return array{0: string, 1: array{x:int,y:int}} */
    private static function node(string $key): array
    {
        [$sea, $sector] = explode('@', $key);
        [$x, $y] = array_map('intval', explode(':', $sector));

        return [$sea, ['x' => $x, 'y' => $y]];
    }
}
