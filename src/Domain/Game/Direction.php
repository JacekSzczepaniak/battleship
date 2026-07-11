<?php

namespace App\Domain\Game;

enum Direction: string
{
    case N = 'N';
    case NE = 'NE';
    case E = 'E';
    case SE = 'SE';
    case S = 'S';
    case SW = 'SW';
    case W = 'W';
    case NW = 'NW';

    /** @return array{0:int,1:int} wektor [dx, dy] */
    public function vector(): array
    {
        return match ($this) {
            self::N => [0, -1],
            self::NE => [1, -1],
            self::E => [1, 0],
            self::SE => [1, 1],
            self::S => [0, 1],
            self::SW => [-1, 1],
            self::W => [-1, 0],
            self::NW => [-1, -1],
        };
    }

    /**
     * Kierunki ukośne mają osobny limit: statki leżą zawsze w pionie/poziomie,
     * więc torpeda ukośna nie trafi jednego statku dwa razy — to broń zwiadowcza.
     */
    public function isDiagonal(): bool
    {
        return in_array($this, [self::NE, self::SE, self::SW, self::NW], true);
    }
}
