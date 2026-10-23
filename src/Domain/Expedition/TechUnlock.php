<?php

namespace App\Domain\Expedition;

/**
 * Ścieżka odblokowania technologii (filar V). Uczelnia to drzewko
 * z poziomami (Epic G); zdobycz to zbadanie konstrukcji statku zdobytego
 * abordażem (#121), bez kosztu i tików — takiej technologii nie bada się na uczelni.
 */
enum TechUnlock: string
{
    case Academy = 'academy';
    case Boarding = 'boarding';
}
