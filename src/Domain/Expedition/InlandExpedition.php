<?php

namespace App\Domain\Expedition;

/**
 * Ekspedycja w głąb dzikiej wyspy w toku (#144): ostatni przebyty etap
 * i nagrody zebrane po drodze. Nagrody trafiają do ładowni dopiero przy
 * powrocie, porażka je zabiera.
 */
final class InlandExpedition
{
    public function __construct(
        public readonly string $island,
        public readonly int $stage,
        public readonly Resources $collected,
        public readonly int $startedAt,
    ) {
        if ('' === $island || $stage < 0 || $stage > WildIsland::STAGES) {
            throw new \InvalidArgumentException('Invalid inland expedition');
        }
    }

    /** Następny etap przebyty z nagrodą. */
    public function passed(int $stage, Resources $reward): self
    {
        return new self($this->island, $stage, $this->collected->add($reward), $this->startedAt);
    }

    /** @return array{island: string, stage: int, collected: array{wood:int, steel:int, gold:int}, startedAt: int} */
    public function toArray(): array
    {
        return ['island' => $this->island, 'stage' => $this->stage, 'collected' => $this->collected->toArray(), 'startedAt' => $this->startedAt];
    }
}
