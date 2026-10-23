<?php

namespace App\Domain\Game\Boarding;

/**
 * Próg sukcesu abordażu na k6: bazowo 5+, każdy działający modyfikator go
 * obniża. Próg nie spada poniżej 2 — jedynka zawsze oznacza porażkę.
 */
final class BoardingOdds
{
    public const BASE_THRESHOLD = 5;
    public const MIN_THRESHOLD = 2;

    /** @var list<BoardingModifier> */
    private array $modifiers;

    /** @param list<BoardingModifier>|null $modifiers null = modyfikatory bazowe */
    public function __construct(?array $modifiers = null)
    {
        $this->modifiers = $modifiers ?? [new HalfHullHit()];
    }

    /** @return list<array{code:string, value:int}> działające modyfikatory (value < 0 = próg niższy) */
    public function applied(BoardingTarget $target): array
    {
        $out = [];
        foreach ($this->modifiers as $modifier) {
            $reduction = $modifier->reduction($target);
            if (0 !== $reduction) {
                $out[] = ['code' => $modifier->code(), 'value' => -$reduction];
            }
        }

        return $out;
    }

    public function threshold(BoardingTarget $target): int
    {
        $threshold = self::BASE_THRESHOLD + array_sum(array_column($this->applied($target), 'value'));

        return max(self::MIN_THRESHOLD, min(6, $threshold));
    }
}
