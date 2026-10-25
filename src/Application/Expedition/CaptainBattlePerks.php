<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Perk;
use App\Domain\Game\BattlePerks;

/**
 * Perki ścieżki bojowej działające w samej bitwie (#161): próg abordażu −1,
 * +1 użycie sonaru i +1⚡ w pierwszej turze. Woła je start każdej bitwy
 * wyprawy; gra dostaje tylko liczby, a wróg ich nie ma (D6). Remont i łup
 * liczy profil przy rozliczeniu i w stoczni.
 */
final class CaptainBattlePerks
{
    public static function of(CaptainProfile $profile): BattlePerks
    {
        return new BattlePerks(
            extraSonarUses: $profile->hasPerk(Perk::ExtraSonar) ? 1 : 0,
            firstTurnEnergy: $profile->hasPerk(Perk::FirstTurnEnergy) ? 1 : 0,
            boardingEdge: $profile->hasPerk(Perk::BoardingEdge) ? 1 : 0,
        );
    }
}
