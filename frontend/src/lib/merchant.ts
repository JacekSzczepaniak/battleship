// Ceny handlarza spotkanego na morzu (#141), jak Domain/Expedition/MerchantPrices:
// kurs portu akwenu (#140) z marżą handlarza. Zlecenie w sztukach towaru — kupno płaci
// złotem za każdą zaczętą porcję, sprzedaż dostaje złoto tylko za pełne, każdy towar osobno.

import type { EncounterDTO, TradeOrder } from '../api/expeditionApi';

type Prices = NonNullable<EncounterDTO['trade']>;

export function buyCost(p: Prices, order: TradeOrder): number {
    return Math.ceil(order.wood / p.rate.wood.buy) + Math.ceil(order.steel / p.rate.steel.buy);
}

export function sellValue(p: Prices, order: TradeOrder): number {
    return Math.floor(order.wood / p.rate.wood.sell) + Math.floor(order.steel / p.rate.steel.sell);
}
