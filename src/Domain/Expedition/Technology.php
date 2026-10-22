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

    /** Ładownia każdego statku +50%, z zaokrągleniem w dół. */
    case PojemneLadownie = 'pojemne-ladownie';

    /** Remont za połowę ceny, z zaokrągleniem w górę. */
    case DokRemontowy = 'dok-remontowy';

    /** Złoże daje +1 jednostkę swojego surowca na tik, zanim tartak i kopalnia podwoją urobek. */
    case LepszeNarzedzia = 'lepsze-narzedzia';

    public function branch(): TechBranch
    {
        return match ($this) {
            self::Spichlerze, self::LepszeNarzedzia => TechBranch::Osady,
            self::PojemneLadownie, self::DokRemontowy => TechBranch::Kadluby,
            self::MapySztormowe => TechBranch::Nawigacja,
        };
    }

    public function level(): TechLevel
    {
        return match ($this) {
            self::Spichlerze, self::MapySztormowe, self::PojemneLadownie => TechLevel::First,
            self::LepszeNarzedzia, self::DokRemontowy => TechLevel::Second,
        };
    }
}
