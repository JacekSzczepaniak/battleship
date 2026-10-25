<?php

namespace App\Domain\Expedition;

/**
 * Ścieżka odblokowania technologii (filar V). Uczelnia to drzewko
 * z poziomami (Epic G); zdobycz to zbadanie konstrukcji statku zdobytego
 * abordażem (#121), bez kosztu i tików — takiej technologii nie bada się na uczelni.
 * Quest to legenda z ekspedycji w głąb dzikiej wyspy (#144). Artefakt z ekspedycji
 * odblokowuje też technologię z uczelni, ale jej ścieżką zostaje Academy — kapitan
 * pamięta tylko, że ma ją z artefaktu (CaptainProfile::artifacts()).
 */
enum TechUnlock: string
{
    case Academy = 'academy';
    case Boarding = 'boarding';
    case Quest = 'quest';
}
