// Pętla ekonomiczna po stronie UI (#49): złoża wysp, magazyny i to, gdzie
// leżą surowce potrzebne w stoczni. Lustro reguł z #48 — limit urobku liczy
// backend, tu tylko go odczytujemy.

import type { IslandDTO, ResourcesDTO } from '../api/expeditionApi';
import { RESOURCES, formatResources, isEmpty } from './resources';

/** none = wyspa bez złoża; idle = wyspa bez bandery; full = urobek sięgnął limitu w magazynie. */
export type DepositState = 'none' | 'idle' | 'working' | 'full';

export function depositState(island: IslandDTO): DepositState {
    const d = island.deposit;
    if (!d) return 'none';
    if (!d.active) return 'idle';
    const full = RESOURCES.every(r => d.yield[r.key] === 0 || island.stockpile[r.key] >= d.cap[r.key]);
    return full ? 'full' : 'working';
}

/** „+3 drewna/tik”, „stoi — magazyn pełny (30 drewna)”, „3 drewna/tik pod banderą”. */
export function depositLabel(island: IslandDTO): string {
    const d = island.deposit;
    if (!d) return '';
    switch (depositState(island)) {
        case 'idle': return `${formatResources(d.yield)}/tik pod banderą`;
        case 'full': return `stoi — magazyn pełny (${formatResources(d.cap)})`;
        default: return `+${formatResources(d.yield)}/tik (do ${formatResources(d.cap)})`;
    }
}

/** Wyspy warte pokazania w przeglądzie: odkryte, z magazynem, złożem albo stocznią. */
export function economyIslands(islands: IslandDTO[]): IslandDTO[] {
    return islands.filter(i => i.discovered && (!isEmpty(i.stockpile) || i.deposit !== null || i.shipyardLevel > 0));
}

/**
 * Gdzie leżą brakujące surowce (poza wyspą `exceptId`): „Mielizny: 30 drewna”.
 * Tylko odkryte wyspy — mgła nie zdradza magazynów.
 */
export function whereToFind(islands: IslandDTO[], need: ResourcesDTO, exceptId: string): string {
    return islands
        .filter(i => i.discovered && i.id !== exceptId)
        .map(i => {
            const found = { wood: 0, steel: 0, gold: 0 };
            RESOURCES.forEach(r => { if (need[r.key] > 0) found[r.key] = i.stockpile[r.key]; });
            return isEmpty(found) ? '' : `${i.name}: ${formatResources(found)}`;
        })
        .filter(Boolean)
        .join('; ');
}
