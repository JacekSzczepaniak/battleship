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

    public function branch(): TechBranch
    {
        return match ($this) {
            self::Spichlerze => TechBranch::Osady,
            self::MapySztormowe => TechBranch::Nawigacja,
        };
    }

    public function level(): TechLevel
    {
        return match ($this) {
            self::Spichlerze, self::MapySztormowe => TechLevel::First,
        };
    }
}
