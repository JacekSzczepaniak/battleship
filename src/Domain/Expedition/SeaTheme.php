<?php

namespace App\Domain\Expedition;

/**
 * Motyw akwenu: trzy parametry globalne dla całego morza (Epic I, D6).
 * Domyślne wartości to motyw Morza Rozbitków — gra na nim działa tak jak
 * przed akwenami.
 */
final class SeaTheme
{
    /**
     * @param int $stormChance       szansa sztormu przy pierwszym wpłynięciu na sektor (w %)
     * @param int $visibility        promień odkrywania wokół floty; 0 = tylko sektor, na który flota wpływa
     * @param int $depositMultiplier mnożnik urobku złóż wysp akwenu
     */
    public function __construct(
        public readonly int $stormChance = 20,
        public readonly int $visibility = 1,
        public readonly int $depositMultiplier = 1,
    ) {
        if ($stormChance < 0 || $stormChance > 100 || $visibility < 0 || $depositMultiplier < 1) {
            throw new \InvalidArgumentException('Invalid sea theme');
        }
    }

    /** @return array{stormChance: int, visibility: int, depositMultiplier: int} */
    public function toArray(): array
    {
        return [
            'stormChance' => $this->stormChance,
            'visibility' => $this->visibility,
            'depositMultiplier' => $this->depositMultiplier,
        ];
    }
}
