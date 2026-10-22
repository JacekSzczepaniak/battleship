<?php

namespace App\Domain\Expedition;

/**
 * Budynek osady na posiadanej wyspie (GAME_DESIGN.md, filar III, Epic F).
 * Każdy typ stoi na wyspie najwyżej raz i nie ma poziomów; działa tylko pod
 * banderą kapitana. Fort (#81) i rozbudowa stoczni (#82) dochodzą jako
 * kolejne przypadki z własnym warunkiem miejsca.
 */
enum BuildingType: string
{
    /** Przy złożu drewna podwaja jego urobek. */
    case Tartak = 'tartak';

    /** Przy złożu stali albo złota podwaja jego urobek. */
    case Kopalnia = 'kopalnia';

    public function buildCost(): Resources
    {
        return match ($this) {
            self::Tartak => new Resources(wood: 30, steel: 10),
            self::Kopalnia => new Resources(wood: 30, steel: 20),
        };
    }

    /** Czy budynek może stanąć na tej wyspie (warunek miejsca, bez bandery i kosztu). */
    public function fitsSite(Island $island): bool
    {
        return match ($this) {
            // budynek produkcyjny stoi tylko przy złożu swojego surowca
            self::Tartak, self::Kopalnia => !$this->depositBoost($island->depositYield)->isZero(),
        };
    }

    /** Dodatkowy urobek, który budynek daje do urobku złoża: tartak dokłada drewno, kopalnia stal i złoto. */
    public function depositBoost(Resources $yield): Resources
    {
        return match ($this) {
            self::Tartak => new Resources(wood: $yield->wood),
            self::Kopalnia => new Resources(steel: $yield->steel, gold: $yield->gold),
        };
    }
}
