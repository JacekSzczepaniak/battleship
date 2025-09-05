<?php

namespace App\Domain\Game;

/** Kontrakt planszy przeciwnika (strzał + stan końca). */
interface TargetBoard
{
    public function size(): int;
    public function shoot(Coordinate $c): ShotResult;       // MISS/HIT/SUNK
    public function isDefeated(): bool;                     // czy wszystkie statki zatopione
    public function wasTried(Coordinate $c): bool;          // dla AI
}
