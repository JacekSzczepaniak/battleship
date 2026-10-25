<?php

namespace App\Domain\Expedition;

/**
 * Skarb z etapu 3 ekspedycji w głąb lądu (#144, Epic J D6–D7): mapa akwenu,
 * artefakt (technologia z uczelni od razu) albo legenda tylko questowa.
 */
enum InlandTreasure: string
{
    /** Mapa Wód Czarnej Perły (#143), przyznana z SeaChartSource::Expedition. */
    case Chart = 'chart';

    /** Artefakt: niezbadana technologia z poziomów 1–2 uczelni, odblokowana od razu (TechUnlock::Quest). */
    case Artifact = 'artifact';

    /** Legenda „Czarna perła”: technologia tylko questowa, +1 do widoczności na wszystkich akwenach. */
    case Legend = 'legend';
}
