<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\SeaGraph;

/**
 * Najkrótsza droga floty do wyspy dowolnego akwenu, w tikach (#109): rejs
 * nie omija przeszkód i liczy odległość jak król w szachach, więc odcinek
 * do bramy albo wyspy to ⌈odległość / zasięg rejsu⌉ tików, a przejście
 * przez bramę dokłada 1 tik. Akwenów jest kilka, więc droga przez bramy
 * to Dijkstra po węzłach „flota na sektorze bramy”. Liczą się tylko bramy,
 * które kapitan może dziś przepłynąć.
 */
final class SeaRoute
{
    public function __construct(private WorldFactory $worldFactory, private SeaGraph $seas)
    {
    }

    /**
     * @return array{ticks: int, gates: int}|null tiki drogi i liczba bram po drodze;
     *                                            null = wyspa spoza świata albo brak przejścia
     */
    public function toIsland(CaptainProfile $profile, string $islandId): ?array
    {
        $maps = [];
        foreach ($this->seas->seas() as $sea) {
            $maps[$sea->id] = $this->worldFactory->mapOf($sea, $profile);
        }
        $targetSea = $maps[$profile->currentSea()]->seaOf($islandId)?->seaId();
        if (null === $targetSea) {
            return null;
        }
        $target = $maps[$targetSea]->islandPosition($islandId);
        $range = $profile->sailRange();

        // węzeł: akwen + sektor floty; koszt: [tiki, bramy]
        $start = $profile->currentSea().'@'.self::key($profile->position());
        $nodes = [$start => ['sea' => $profile->currentSea(), 'at' => $profile->position(), 'ticks' => 0, 'gates' => 0]];
        $done = [];
        $best = null;
        while (true) {
            $open = array_diff_key($nodes, $done);
            if ([] === $open) {
                return $best;
            }
            uasort($open, static fn (array $a, array $b) => [$a['ticks'], $a['gates']] <=> [$b['ticks'], $b['gates']]);
            $key = array_key_first($open);
            $node = $open[$key];
            $done[$key] = true;
            if (null !== $best && $node['ticks'] >= $best['ticks']) {
                return $best;
            }

            if ($node['sea'] === $targetSea) {
                $ticks = $node['ticks'] + self::legs($node['at'], $target, $range);
                if (null === $best || $ticks < $best['ticks']) {
                    $best = ['ticks' => $ticks, 'gates' => $node['gates']];
                }
            }
            foreach ($this->seas->passagesFrom($node['sea']) as $passage) {
                if (null !== $profile->gateBlocker($passage)) {
                    continue;
                }
                $from = $maps[$node['sea']]->gatePosition($passage->gate->id);
                $to = $maps[$passage->to]->gatePosition($passage->gate->id);
                if (null === $from || null === $to) {
                    continue;
                }
                $next = "{$passage->to}@".self::key($to);
                $ticks = $node['ticks'] + self::legs($node['at'], $from, $range) + 1;
                if (!isset($done[$next]) && (!isset($nodes[$next]) || $ticks < $nodes[$next]['ticks'])) {
                    $nodes[$next] = ['sea' => $passage->to, 'at' => $to, 'ticks' => $ticks, 'gates' => $node['gates'] + 1];
                }
            }
        }
    }

    /**
     * Rejsy między sektorami jednego akwenu.
     *
     * @param array{x:int,y:int} $from
     * @param array{x:int,y:int} $to
     */
    private static function legs(array $from, array $to, int $range): int
    {
        $distance = max(abs($to['x'] - $from['x']), abs($to['y'] - $from['y']));

        return intdiv($distance + $range - 1, $range);
    }

    /** @param array{x:int,y:int} $at */
    private static function key(array $at): string
    {
        return "{$at['x']}:{$at['y']}";
    }
}
