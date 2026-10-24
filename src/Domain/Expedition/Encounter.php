<?php

namespace App\Domain\Expedition;

/**
 * Spotkanie, które czeka na decyzję kapitana (#141): kto, kiedy i gdzie.
 * Dopóki czeka, flota nie płynie dalej.
 */
final class Encounter
{
    public function __construct(
        public readonly EncounterType $type,
        public readonly int $tick,
        public readonly string $sea,
        public readonly int $x,
        public readonly int $y,
    ) {
    }

    /** @return array{type:string, tick:int, sea:string, x:int, y:int} */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'tick' => $this->tick, 'sea' => $this->sea, 'x' => $this->x, 'y' => $this->y];
    }
}
