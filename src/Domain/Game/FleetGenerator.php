<?php

namespace App\Domain\Game;

/**
 * Port generowania floty (np. dla przeciwnika po rozstawieniu floty gracza).
 * Implementacja produkcyjna losuje układ, testowa zwraca deterministyczny.
 */
interface FleetGenerator
{
    /**
     * Zwraca flotę zgodną z ruleset->allowedShips(), mieszczącą się na planszy
     * rulesetu bez kolizji i styku (walidowalną przez Board::placeMany()).
     *
     * @return Ship[]
     */
    public function generate(Ruleset $ruleset): array;
}
