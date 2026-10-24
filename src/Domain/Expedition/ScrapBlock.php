<?php

namespace App\Domain\Expedition;

/**
 * Powód, dla którego statku nie da się zezłomować (#124). Wymóg stoczni
 * w porcie sprawdza warstwa aplikacji, bo zna wyspę, przy której stoi flota.
 */
enum ScrapBlock: string
{
    /** Statek wypłynął do bitwy, która nie została rozliczona. */
    case InBattle = 'in-battle';

    /** Ostatnia sprawna jednostka floty — bezpiecznik anty-softlock (zasada 6). */
    case LastSeaworthy = 'last-seaworthy';

    /** Komunikat błędu domenowego (mapowany na kod API). */
    public function message(): string
    {
        return match ($this) {
            self::InBattle => 'Ship in an unsettled battle',
            self::LastSeaworthy => 'Cannot scrap the last seaworthy ship',
        };
    }
}
