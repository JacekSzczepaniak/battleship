<?php

namespace App\Domain\Game\Boarding;

/** Wynik jedynej w bitwie próby abordażu; $shipIndex = indeks statku we flocie wroga. */
final class BoardingAttempt
{
    /** @param list<array{code:string, value:int}> $modifiers */
    public function __construct(
        public readonly int $round,
        public readonly int $roll,
        public readonly int $threshold,
        public readonly array $modifiers,
        public readonly int $shipIndex,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->roll >= $this->threshold;
    }

    /** @return array{round:int, roll:int, threshold:int, modifiers:list<array{code:string, value:int}>, ship:int, success:bool} */
    public function toArray(): array
    {
        return [
            'round' => $this->round,
            'roll' => $this->roll,
            'threshold' => $this->threshold,
            'modifiers' => $this->modifiers,
            'ship' => $this->shipIndex,
            'success' => $this->succeeded(),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['round'] ?? 1),
            (int) ($data['roll'] ?? 1),
            (int) ($data['threshold'] ?? BoardingOdds::BASE_THRESHOLD),
            array_values(array_map(
                static fn (array $m) => ['code' => (string) $m['code'], 'value' => (int) $m['value']],
                array_filter((array) ($data['modifiers'] ?? []), 'is_array'),
            )),
            (int) ($data['ship'] ?? 0),
        );
    }
}
