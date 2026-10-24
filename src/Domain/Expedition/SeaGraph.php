<?php

namespace App\Domain\Expedition;

/** Świat wyprawy jako graf akwenów (Epic I): węzły to akweny, krawędzie to przejścia przez bramy. */
interface SeaGraph
{
    /** @return list<Sea> akweny w kolejności progresji; pierwszy to start */
    public function seas(): array;

    public function sea(string $id): ?Sea;

    /** Akwen, w którym zaczyna się każda wyprawa. */
    public function start(): Sea;

    /** @return list<SeaPassage> przejścia wychodzące z akwenu */
    public function passagesFrom(string $seaId): array;

    /** Przejście z akwenu przez daną bramę; null = brama nie leży na tym akwenie. */
    public function passage(string $fromSeaId, string $gateId): ?SeaPassage;

    /** Port handlowy o danym id (z dowolnego akwenu, #140); null = nieznany. */
    public function tradePort(string $portId): ?TradePort;

    /** Brama o danym id (z dowolnego akwenu); null = nieznana. */
    public function gate(string $gateId): ?SeaGate;
}
