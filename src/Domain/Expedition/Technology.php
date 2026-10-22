<?php

namespace App\Domain\Expedition;

/**
 * Technologia drzewka (Epic G). Katalog zawiera tylko technologie, które
 * działają; efekt każdej liczy profil kapitana tam, gdzie zmienia regułę.
 * Działa wyłącznie po stronie gracza — lustrzany skład wroga jej nie dostaje.
 */
enum Technology: string
{
    /** Magazyn złoża mieści 20 tików produkcji zamiast 10. */
    case Spichlerze = 'spichlerze';

    /** Szansa sztormu przy pierwszym wpłynięciu: 10% zamiast 20%. */
    case MapySztormowe = 'mapy-sztormowe';

    /** Promień sonaru gracza w bitwach wyprawy: 4 zamiast 3. */
    case SonarDalekiegoZasiegu = 'sonar-dalekiego-zasiegu';

    /** Rejs na sektor odległy o 2 kosztuje 1 tik, jak rejs na sąsiedni. */
    case ZeglugaDwusektorowa = 'zegluga-dwusektorowa';

    public function branch(): TechBranch
    {
        return match ($this) {
            self::Spichlerze => TechBranch::Osady,
            self::MapySztormowe, self::ZeglugaDwusektorowa => TechBranch::Nawigacja,
            self::SonarDalekiegoZasiegu => TechBranch::Uzbrojenie,
        };
    }

    public function level(): TechLevel
    {
        return match ($this) {
            self::Spichlerze, self::MapySztormowe, self::SonarDalekiegoZasiegu => TechLevel::First,
            self::ZeglugaDwusektorowa => TechLevel::Second,
        };
    }
}
