<?php

namespace App\Domain\Expedition;

/**
 * Skąd kapitan ma mapę akwenu (#143, Epic J D5). Mapy nie ma w łupach
 * z bitew, żeby została najcenniejszym przedmiotem w grze.
 */
enum SeaChartSource: string
{
    /** Kupiona na rynku portu handlowego (Bazar we Mgle). */
    case Market = 'market';

    /** Nagroda z ekspedycji na dziką wyspę (#144). */
    case Expedition = 'expedition';
}
