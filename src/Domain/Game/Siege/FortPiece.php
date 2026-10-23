<?php

namespace App\Domain\Game\Siege;

/** Rodzaj konstrukcji fortu na polu planszy. */
enum FortPiece: string
{
    case Gun = 'gun';
    case Wall = 'wall';
    case Magazine = 'magazine';
}
