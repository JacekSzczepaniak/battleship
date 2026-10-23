<?php

namespace App\Domain\Game\Siege;

/**
 * Liczby oblężenia fortu (#119, D3 w #55: „zobaczymy, jak to działa”) — jedno
 * miejsce do strojenia. Układ fortu generuje FortGenerator, strzały fortu
 * oddaje zwykłe AI (OpponentTurn) bez broni specjalnych i bez energii.
 */
final class FortRules
{
    /** Plansza fortu (i całej bitwy oblężniczej): kwadrat SIZE × SIZE. */
    public const SIZE = 10;

    /** Działa nadbrzeżne po jednym polu; zniszczenie wszystkich = wygrana gracza. */
    public const GUNS = 4;

    /** Odcinki muru: liczba i długość (losowana z seeda w przedziale MIN..MAX). */
    public const WALLS = 3;
    public const WALL_MIN_LENGTH = 2;
    public const WALL_MAX_LENGTH = 3;

    /** Magazyny prochu po jednym polu. */
    public const MAGAZINES = 1;

    /** Promień eksplozji magazynu: 1 = kwadrat 3×3 wokół magazynu. */
    public const EXPLOSION_RADIUS = 1;
}
