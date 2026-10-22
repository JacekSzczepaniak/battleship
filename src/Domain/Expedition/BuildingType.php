<?php

namespace App\Domain\Expedition;

/**
 * Budynek osady na posiadanej wyspie (GAME_DESIGN.md, filar III, Epic F).
 * Każdy typ stoi na wyspie najwyżej raz i nie ma poziomów; działa tylko pod
 * banderą kapitana.
 */
enum BuildingType: string
{
    /** Przy złożu drewna podwaja jego urobek. */
    case Tartak = 'tartak';

    /** Przy złożu stali albo złota podwaja jego urobek. */
    case Kopalnia = 'kopalnia';

    /** Podnosi istniejącą stocznię wyspy o jeden poziom (#82); nowych stoczni się nie stawia. */
    case RozbudowaStoczni = 'rozbudowa-stoczni';

    /**
     * Garnizon (#81): kontratak bez rozpoczętej obrony kończy się grabieżą
     * połowy magazynu zamiast utraty wyspy.
     */
    case Fort = 'fort';

    public function buildCost(): Resources
    {
        return match ($this) {
            self::Tartak => new Resources(wood: 30, steel: 10),
            self::Kopalnia => new Resources(wood: 30, steel: 20),
            self::RozbudowaStoczni => new Resources(wood: 50, steel: 30, gold: 15),
            self::Fort => new Resources(wood: 40, steel: 40, gold: 10),
        };
    }

    /** Czy budynek może stanąć na tej wyspie (warunek miejsca, bez bandery i kosztu). */
    public function fitsSite(Island $island): bool
    {
        return match ($this) {
            // budynek produkcyjny stoi tylko przy złożu swojego surowca
            self::Tartak, self::Kopalnia => !$this->depositBoost($island->depositYield)->isZero(),
            // rozbudowa tylko istniejącej stoczni, która nie ma jeszcze maksymalnego poziomu
            self::RozbudowaStoczni => $island->shipyardLevel > 0 && $island->shipyardLevel < Island::MAX_SHIPYARD_LEVEL,
            // kontrataki nie dotyczą bezpiecznej przystani, więc fort nie miałby tam czego bronić
            self::Fort => !$island->safeHarbor,
        };
    }

    /** Dodatkowy urobek, który budynek daje do urobku złoża: tartak dokłada drewno, kopalnia stal i złoto. */
    public function depositBoost(Resources $yield): Resources
    {
        return match ($this) {
            self::Tartak => new Resources(wood: $yield->wood),
            self::Kopalnia => new Resources(steel: $yield->steel, gold: $yield->gold),
            self::RozbudowaStoczni, self::Fort => Resources::none(),
        };
    }

    /** O ile poziomów budynek podnosi stocznię wyspy. */
    public function shipyardBoost(): int
    {
        return self::RozbudowaStoczni === $this ? 1 : 0;
    }
}
