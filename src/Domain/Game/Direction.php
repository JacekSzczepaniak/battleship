<?php


namespace App\Domain\Game;

enum Direction: string
{
    case N = 'N';
    case E = 'E';
    case S = 'S';
    case W = 'W';
}
