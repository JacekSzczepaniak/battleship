<?php

namespace App\Domain\Expedition;

/** Strona transakcji na rynku portu handlowego (#140). */
enum MarketSide: string
{
    /** Kapitan płaci złotem i dostaje towar. */
    case Buy = 'buy';

    /** Kapitan oddaje towar i dostaje złoto. */
    case Sell = 'sell';
}
