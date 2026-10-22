// Kontrataki po stronie UI (#78): opisy zapowiedzi i rozstrzygnięć. Reguły
// (spokój, szansa, termin) liczy backend — tu tylko je odczytujemy.

import type { IslandDTO, LastCounterattackDTO, PendingCounterattackDTO } from '../api/expeditionApi';
import { formatResources } from './resources';

export function ticksLabel(n: number): string {
    if (n === 1) return '1 tik';
    const lastTwo = n % 100;
    const last = n % 10;
    return last >= 2 && last <= 4 && (lastTwo < 12 || lastTwo > 14) ? `${n} tiki` : `${n} tików`;
}

// nazw wysp nie odmieniamy (Mielizny, Archipelag Mgieł) — stoją po dwukropku

/** „Kontratak: Mielizny — uderzenie za 7 tików” albo informacja o trwającej obronie. */
export function pendingLabel(c: PendingCounterattackDTO): string {
    return c.defenseStarted
        ? `Obrona: ${c.islandName} — termin wstrzymany do końca bitwy`
        : `Kontratak: ${c.islandName} — uderzenie za ${ticksLabel(c.ticksLeft)} (tik ${c.strikeAt})`;
}

/** Ostatnie rozstrzygnięcie: obrona, przegrana obrona albo utrata pod nieobecność. */
export function lastLabel(c: LastCounterattackDTO): string {
    if (c.held) return `Tik ${c.tick} · wyspa obroniona: ${c.islandName} — bandera została`;
    const lost = formatResources(c.stockpileLost, 'nic — magazyn był pusty');
    const why = c.absent ? 'nikt nie stanął do obrony' : 'obrona przegrana';
    return `Tik ${c.tick} · wyspa utracona: ${c.islandName} (${why}) — z magazynu przepadło: ${lost}`;
}

/** Czy na tej wyspie można teraz przyjąć bitwę obronną (flota na miejscu sprawdzana osobno). */
export function canDefend(island: IslandDTO, pending: PendingCounterattackDTO | null): boolean {
    return !!pending && pending.island === island.id && !pending.defenseStarted && pending.ticksLeft > 0;
}
