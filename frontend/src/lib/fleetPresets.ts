// Zestawy floty (#125): skład z rozmieszczeniem zapisany w profilu. Zestaw trzyma
// id statków floty — statek utracony, złomowany albo w remoncie jest pomijany
// z komunikatem, a statki bez pasującej pozycji idą do ręcznego rozstawienia.

import { SHIP_LABELS, fleetShipLabel, type FleetPresetDTO, type FleetShipDTO } from '../api/expeditionApi';
import type { Loadout } from '../api/gameApi';
import { carriedWeapon, VARIANTS } from './ships';

export const FLEET_PRESET_LIMIT = 5;
export const FLEET_PRESET_NAME_MAX = 30;

export type MissingReason = 'lost' | 'damaged' | 'over-limit' | 'not-in-battle';

export interface MissingShip {
    label: string;
    reason: MissingReason;
}

const REASON_LABEL: Record<MissingReason, string> = {
    lost: 'już nie we flocie',
    damaged: 'czeka na remont',
    'over-limit': 'ponad limit składu',
    'not-in-battle': 'nie płynie w tej bitwie',
};

/** „Pominięto: Kuter (czeka na remont), Tratwa (nie ma go już we flocie).” — pusty, gdy nic nie brakuje. */
export function missingMessage(missing: MissingShip[]): string {
    if (!missing.length) return '';
    return `Pominięto: ${missing.map(m => `${m.label} (${REASON_LABEL[m.reason]})`).join(', ')}.`;
}

/**
 * Skład z zestawu w limicie pól: statki w kolejności zapisu, tylko sprawne
 * i tylko te, które się jeszcze zmieszczą.
 */
export function presetSquad(preset: FleetPresetDTO, fleet: FleetShipDTO[], limit: number): { ships: FleetShipDTO[]; missing: MissingShip[] } {
    const ships: FleetShipDTO[] = [];
    const missing: MissingShip[] = [];
    let cells = 0;
    for (const entry of preset.ships) {
        const ship = fleet.find(s => s.id === entry.shipId);
        if (!ship) {
            missing.push({ label: SHIP_LABELS[entry.type], reason: 'lost' });
        } else if (ship.damaged) {
            missing.push({ label: fleetShipLabel(ship), reason: 'damaged' });
        } else if (cells + ship.length > limit) {
            missing.push({ label: fleetShipLabel(ship), reason: 'over-limit' });
        } else {
            ships.push(ship);
            cells += ship.length;
        }
    }
    return { ships, missing };
}

// --- rozstawianie: statki planszy znają tylko długość i wyposażenie ---

/** Statek ekranu rozstawiania: długość i wyposażenie wariantu (brak = domyślne). */
export interface SetupSlot {
    id: number;
    l: number;
    w?: Loadout;
}

// statki tej samej długości i broni są w bitwie zamienne
function slotKey(l: number, w?: Loadout): string {
    return `${l}|${carriedWeapon(l, w) ?? ''}`;
}

function shipKey(ship: FleetShipDTO): string {
    return slotKey(ship.length, ship.variant ? VARIANTS[ship.variant].w : undefined);
}

/**
 * Przypisanie statków składu bitwy do statków planszy — po długości i broni,
 * w kolejności floty. Statek planszy bez pary nie dostaje id (nie trafi do zapisu).
 */
export function slotShipIds(slots: SetupSlot[], fleet: FleetShipDTO[], squadIds: string[]): Map<number, string> {
    const free = fleet.filter(s => squadIds.includes(s.id));
    const out = new Map<number, string>();
    for (const slot of slots) {
        const i = free.findIndex(s => shipKey(s) === slotKey(slot.l, slot.w));
        if (i >= 0) out.set(slot.id, free.splice(i, 1)[0].id);
    }
    return out;
}

/**
 * Pozycje z zestawu dla statków planszy: statek zestawu musi płynąć w tej
 * bitwie; dostaje wolny statek planszy tej samej długości i broni. Czy pozycja
 * się mieści, sprawdza ekran rozstawiania (canPlace) — nie mieszczący się
 * statek zostaje do ręcznego ustawienia.
 */
export function presetPlacements(
    preset: FleetPresetDTO, slots: SetupSlot[], fleet: FleetShipDTO[], squadIds: string[],
): { placements: { slotId: number; x: number; y: number; o: 'h' | 'v' }[]; missing: MissingShip[] } {
    const used = new Set<number>();
    const placements: { slotId: number; x: number; y: number; o: 'h' | 'v' }[] = [];
    const missing: MissingShip[] = [];
    for (const entry of preset.ships) {
        const ship = fleet.find(s => s.id === entry.shipId);
        if (!ship) {
            missing.push({ label: SHIP_LABELS[entry.type], reason: 'lost' });
            continue;
        }
        const slot = squadIds.includes(ship.id)
            ? slots.find(s => !used.has(s.id) && slotKey(s.l, s.w) === shipKey(ship))
            : undefined;
        if (!slot) {
            missing.push({ label: fleetShipLabel(ship), reason: ship.damaged ? 'damaged' : 'not-in-battle' });
            continue;
        }
        used.add(slot.id);
        placements.push({ slotId: slot.id, x: entry.x, y: entry.y, o: entry.o === 'H' ? 'h' : 'v' });
    }
    return { placements, missing };
}

const PRESET_ERRORS: Record<string, string> = {
    FLEET_PRESET_LIMIT: `Masz już ${FLEET_PRESET_LIMIT} zestawów — usuń któryś albo zapisz pod istniejącą nazwą.`,
    FLEET_PRESET_NAME_TAKEN: 'Zestaw o tej nazwie już istnieje.',
    FLEET_PRESET_NOT_FOUND: 'Tego zestawu już nie ma.',
    SHIP_NOT_FOUND: 'Któregoś statku nie ma już we flocie.',
};

export function presetErrorMessage(e: any, fallback: string): string {
    return PRESET_ERRORS[e?.details?.error?.code] ?? e?.message ?? fallback;
}
