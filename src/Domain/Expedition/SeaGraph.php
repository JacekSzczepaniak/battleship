<?php

namespace App\Domain\Expedition;

/** Świat wyprawy jako graf akwenów (Epic I): węzły to akweny, krawędzie to przejścia. */
interface SeaGraph
{
    /** @return list<Sea> akweny w kolejności progresji; pierwszy to start */
    public function seas(): array;

    public function sea(string $id): ?Sea;

    /** Akwen, w którym zaczyna się każda wyprawa. */
    public function start(): Sea;

    /** @return list<SeaPassage> przejścia wychodzące z akwenu */
    public function passagesFrom(string $seaId): array;
}
