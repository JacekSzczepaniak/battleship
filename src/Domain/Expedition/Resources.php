<?php

namespace App\Domain\Expedition;

/**
 * Trzy surowce wyprawy (docs/GAME_DESIGN.md, filar IV): drewno (kadłuby,
 * budynki), stal (uzbrojenie, forty), złoto (handel, uczelnie). Niemutowalna
 * paczka ilości — koszty, nagrody i stan magazynu kapitana.
 */
final readonly class Resources
{
    public function __construct(
        public int $wood = 0,
        public int $steel = 0,
        public int $gold = 0,
    ) {
        if ($wood < 0 || $steel < 0 || $gold < 0) {
            throw new \InvalidArgumentException('Negative resource amount');
        }
    }

    public static function none(): self
    {
        return new self();
    }

    /** @param array<string,mixed> $data klucze wood/steel/gold; brakujące = 0 */
    public static function fromArray(array $data): self
    {
        return new self(
            max(0, (int) ($data['wood'] ?? 0)),
            max(0, (int) ($data['steel'] ?? 0)),
            max(0, (int) ($data['gold'] ?? 0)),
        );
    }

    public function add(self $other): self
    {
        return new self($this->wood + $other->wood, $this->steel + $other->steel, $this->gold + $other->gold);
    }

    public function covers(self $cost): bool
    {
        return $this->wood >= $cost->wood && $this->steel >= $cost->steel && $this->gold >= $cost->gold;
    }

    public function subtract(self $cost): self
    {
        if (!$this->covers($cost)) {
            throw new \DomainException('Not enough resources');
        }

        return new self($this->wood - $cost->wood, $this->steel - $cost->steel, $this->gold - $cost->gold);
    }

    /** Suma wszystkich surowców — do porównań skali nagród, nie do płatności. */
    public function total(): int
    {
        return $this->wood + $this->steel + $this->gold;
    }

    /**
     * Dzieli paczkę na część mieszczącą się w `$room` jednostkach i nadmiar.
     * Najcenniejsze zostają: najpierw złoto, potem stal, na końcu drewno.
     *
     * @return array{0: self, 1: self} [mieści się, nadmiar]
     */
    public function fitInto(int $room): array
    {
        $room = max(0, $room);
        $gold = min($this->gold, $room);
        $room -= $gold;
        $steel = min($this->steel, $room);
        $room -= $steel;
        $wood = min($this->wood, $room);
        $kept = new self($wood, $steel, $gold);

        return [$kept, $this->subtract($kept)];
    }

    public function isZero(): bool
    {
        return 0 === $this->total();
    }

    /** @return array{wood:int, steel:int, gold:int} */
    public function toArray(): array
    {
        return ['wood' => $this->wood, 'steel' => $this->steel, 'gold' => $this->gold];
    }
}
