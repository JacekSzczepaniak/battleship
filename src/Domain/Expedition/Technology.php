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

    /** Szansa sztormu przy pierwszym wpłynięciu: połowa szansy akwenu (Morze Rozbitków 10% zamiast 20%, Ocean Sztormów 20% zamiast 40%, Morze Mgieł 5% zamiast 10%). */
    case MapySztormowe = 'mapy-sztormowe';

    /** Promień sonaru gracza w bitwach wyprawy: 4 zamiast 3. */
    case SonarDalekiegoZasiegu = 'sonar-dalekiego-zasiegu';

    /** Rejs na sektor odległy o 2 kosztuje 1 tik, jak rejs na sąsiedni. */
    case ZeglugaDwusektorowa = 'zegluga-dwusektorowa';

    /** Ładownia każdego statku +50%, z zaokrągleniem w dół. */
    case PojemneLadownie = 'pojemne-ladownie';

    /** Remont za połowę ceny, z zaokrągleniem w górę. */
    case DokRemontowy = 'dok-remontowy';

    /** Złoże daje +1 jednostkę swojego surowca na tik, zanim mnożnik akwenu go pomnoży, a tartak i kopalnia podwoją urobek. */
    case LepszeNarzedzia = 'lepsze-narzedzia';

    /** Stocznia buduje niszczyciel w wariancie zwiadowczym: 2 sonary zamiast torpedy (ShipVariant). */
    case NiszczycielZwiadowczy = 'niszczyciel-zwiadowczy';

    /** Otwiera bramę oceaniczną między Oceanem Sztormów a Morzem Mgieł, w obie strony (#108). */
    case NawigacjaOceaniczna = 'nawigacja-oceaniczna';

    public function branch(): TechBranch
    {
        return match ($this) {
            self::Spichlerze, self::LepszeNarzedzia => TechBranch::Osady,
            self::PojemneLadownie, self::DokRemontowy => TechBranch::Kadluby,
            self::MapySztormowe, self::ZeglugaDwusektorowa, self::NawigacjaOceaniczna => TechBranch::Nawigacja,
            self::SonarDalekiegoZasiegu, self::NiszczycielZwiadowczy => TechBranch::Uzbrojenie,
        };
    }

    public function level(): TechLevel
    {
        return match ($this) {
            self::Spichlerze, self::MapySztormowe, self::SonarDalekiegoZasiegu, self::PojemneLadownie => TechLevel::First,
            self::ZeglugaDwusektorowa, self::LepszeNarzedzia, self::DokRemontowy, self::NiszczycielZwiadowczy => TechLevel::Second,
            self::NawigacjaOceaniczna => TechLevel::Third,
        };
    }
}
