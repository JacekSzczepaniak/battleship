<?php

namespace App\Domain\Expedition;

/** Krawędź mapy akwenu, na której leży sektor bramy (Epic I). */
enum SeaEdge: string
{
    case North = 'north';
    case East = 'east';
    case South = 'south';
    case West = 'west';
}
