// Skład bitewny w limicie pól wyspy (#73) — lustro doboru z CaptainProfile::battleSquad(),
// żeby UI pokazał, co popłynie, zanim gracz kliknie „Bitwa”.

import type { FleetShipDTO, ShipTypeName } from '../api/expeditionApi';

const PRIORITY: ShipTypeName[] = ['lotniskowiec', 'niszczyciel', 'kuter', 'tratwa'];

export function squadCells(ships: FleetShipDTO[]): number {
    return ships.reduce((sum, s) => sum + s.length, 0);
}

/** Kolejność doboru: nośniki broni od największego, statki bez broni (tratwa, kuter remontowy) na końcu. */
function priority(ship: FleetShipDTO): number {
    return ship.armed ? PRIORITY.indexOf(ship.type) : PRIORITY.length;
}

/**
 * Nośniki broni od największego, potem tratwy, do limitu; tylko sprawne statki.
 * Transportowiec płynie do bitwy tylko wybrany ręcznie.
 */
export function defaultSquad(fleet: FleetShipDTO[], limit: number): FleetShipDTO[] {
    const candidates = fleet
        .filter(s => !s.damaged && PRIORITY.includes(s.type))
        .sort((a, b) => priority(a) - priority(b));
    const squad: FleetShipDTO[] = [];
    let cells = 0;
    for (const ship of candidates) {
        if (cells + ship.length <= limit) {
            squad.push(ship);
            cells += ship.length;
        }
    }
    return squad;
}

/**
 * Konwój (statki z transportowcem, także uszkodzonym — wozi ładunek) bez
 * eskorty — sprawnego statku z bronią. Lustro CaptainProfile::isUnescortedConvoy():
 * nie atakuje wyspy, w sztormie traci 2×.
 */
export function isUnescortedConvoy(ships: FleetShipDTO[]): boolean {
    return ships.some(s => s.type === 'transportowiec') && !ships.some(s => !s.damaged && s.armed);
}

/** Sprawne statki floty — tylko one mogą stanąć do bitwy. */
export function activeShips(fleet: FleetShipDTO[]): FleetShipDTO[] {
    return fleet.filter(s => !s.damaged);
}

/** Skład, który popłynie: wybrany ręcznie (pick) albo dobrany domyślnie w limicie pól. */
export function squadOf(fleet: FleetShipDTO[], limit: number, pick: Set<string> | null): FleetShipDTO[] {
    if (pick) return activeShips(fleet).filter(s => pick.has(s.id));
    return defaultSquad(fleet, limit);
}

/** Dobór domyślny zostawia w porcie statki spoza priorytetu (transportowiec), choć jest miejsce. */
export function leavesShipsInPort(fleet: FleetShipDTO[], limit: number): boolean {
    return defaultSquad(fleet, limit).length < activeShips(fleet).length;
}

/** Wybór składu ma sens, gdy flota przekracza limit albo dobór domyślny zostawia statki w porcie (#73, #166 D3). */
export function squadChoiceNeeded(fleet: FleetShipDTO[], limit: number): boolean {
    return squadCells(activeShips(fleet)) > limit || leavesShipsInPort(fleet, limit);
}

/** Konwój bez eskorty nie atakuje — także gdy sprawne są same transportowce (dobór domyślny jest pusty). */
export function convoyBlocked(fleet: FleetShipDTO[], limit: number, pick: Set<string> | null): boolean {
    const squad = squadOf(fleet, limit, pick);
    return isUnescortedConvoy(squad.length ? squad : activeShips(fleet));
}
