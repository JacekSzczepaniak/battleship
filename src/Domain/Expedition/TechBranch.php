<?php

namespace App\Domain\Expedition;

/** Gałąź drzewka technologii (GAME_DESIGN.md, filar V, Epic G). */
enum TechBranch: string
{
    case Kadluby = 'kadluby';
    case Uzbrojenie = 'uzbrojenie';
    case Nawigacja = 'nawigacja';
    case Osady = 'osady';
}
