<?php

namespace App\Domain\Game\Weapon;

/**
 * Parametry sonaru: liczba użyć na grę i stronę oraz maksymalny promień
 * krzyża skanowania (0 = tylko komórka centralna).
 */
final class SonarSpec
{
    /** Promień sonaru w trybie fun — wroga zawsze, gracza bez technologii. */
    public const RADIUS = 3;

    /** Promień sonaru gracza po zbadaniu Sonaru dalekiego zasięgu (Epic G). */
    public const LONG_RANGE_RADIUS = 4;

    public function __construct(
        public readonly int $uses,
        public readonly int $radius = 0,
    ) {
        if ($uses < 0 || $radius < 0) {
            throw new \InvalidArgumentException('Negative sonar spec value');
        }
    }
}
