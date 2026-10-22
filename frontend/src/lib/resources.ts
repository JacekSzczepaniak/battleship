// Trzy surowce wyprawy (GAME_DESIGN.md, filar IV) po stronie UI: etykiety,
// kolory z tokenów projektu i formatowanie kosztów/łupów.

import type { ResourcesDTO } from '../api/expeditionApi';

export type ResourceKey = keyof ResourcesDTO;

export const RESOURCES: { key: ResourceKey; label: string; genitive: string; color: string }[] = [
    { key: 'wood', label: 'Drewno', genitive: 'drewna', color: 'var(--res-wood)' },
    { key: 'steel', label: 'Stal', genitive: 'stali', color: 'var(--res-steel)' },
    { key: 'gold', label: 'Złoto', genitive: 'złota', color: 'var(--res-gold)' },
];

/** „15 drewna · 5 stali”; pomija zera, pusty koszt = „za darmo”. */
export function formatResources(r: ResourcesDTO, empty = 'za darmo'): string {
    const parts = RESOURCES.filter(x => r[x.key] > 0).map(x => `${r[x.key]} ${x.genitive}`);
    return parts.length ? parts.join(' · ') : empty;
}

export function addResources(a: ResourcesDTO, b: ResourcesDTO): ResourcesDTO {
    return { wood: a.wood + b.wood, steel: a.steel + b.steel, gold: a.gold + b.gold };
}

export function totalResources(r: ResourcesDTO): number {
    return r.wood + r.steel + r.gold;
}

export function isEmpty(r: ResourcesDTO): boolean {
    return totalResources(r) === 0;
}

export function covers(stock: ResourcesDTO, cost: ResourcesDTO): boolean {
    return RESOURCES.every(x => stock[x.key] >= cost[x.key]);
}

/** Czego brakuje do kosztu — do podpowiedzi przy zablokowanym przycisku. */
export function missing(stock: ResourcesDTO, cost: ResourcesDTO): string {
    const parts = RESOURCES
        .filter(x => stock[x.key] < cost[x.key])
        .map(x => `${cost[x.key] - stock[x.key]} ${x.genitive}`);
    return parts.length ? `brakuje: ${parts.join(' · ')}` : '';
}
