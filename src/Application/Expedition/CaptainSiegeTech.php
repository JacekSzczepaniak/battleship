<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Technology;
use App\Domain\Game\Siege\FortRules;
use App\Domain\Game\Siege\SiegeTech;

/**
 * Technologie gałęzi Oblężnictwo (#177) przełożone na parametry oblężenia:
 * Lunety artyleryjskie namierzają działo, Lonty prochowe powiększają wybuch
 * magazynu. Woła je tylko start oblężenia; inne bitwy ich nie dostają.
 */
final class CaptainSiegeTech
{
    public static function of(CaptainProfile $profile): SiegeTech
    {
        return new SiegeTech(
            spyglasses: $profile->hasResearched(Technology::LunetyArtyleryjskie),
            blastRadius: $profile->hasResearched(Technology::LontyProchowe) ? SiegeTech::POWDER_FUSE_BLAST_RADIUS : FortRules::EXPLOSION_RADIUS,
        );
    }
}
