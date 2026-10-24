<?php

namespace App\Domain\Expedition;

/** Powód, dla którego remont całej floty (#151) pominął uszkodzony statek. */
enum RepairSkip: string
{
    /** Stocznia ma za niski poziom na ten typ statku. */
    case ShipyardTooLow = 'shipyard-too-low';

    /** Magazyn wyspy i ładownia nie pokryły kosztu remontu po tańszych statkach. */
    case NotEnoughResources = 'not-enough-resources';
}
