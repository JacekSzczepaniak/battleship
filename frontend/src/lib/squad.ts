// Skład bitewny w limicie pól wyspy (#73) — lustro doboru z CaptainProfile::battleSquad(),
// żeby UI pokazał, co popłynie, zanim gracz kliknie „Bitwa”.

import type { FleetShipDTO, ShipTypeName } from '../api/expeditionApi';

const PRIORITY: ShipTypeName[] = ['lotniskowiec', 'niszczyciel', 'kuter', 'tratwa'];
const ARMED: ShipTypeName[] = ['lotniskowiec', 'niszczyciel', 'kuter'];

export function squadCells(ships: FleetShipDTO[]): number {
    return ships.reduce((sum, s) => sum + s.length, 0);
}

/**
 * Nośniki broni od największego, potem tratwy, do limitu; tylko sprawne statki.
 * Transportowiec płynie do bitwy tylko wybrany ręcznie.
 */
export function defaultSquad(fleet: FleetShipDTO[], limit: number): FleetShipDTO[] {
    const candidates = fleet
        .filter(s => !s.damaged && PRIORITY.includes(s.type))
        .sort((a, b) => PRIORITY.indexOf(a.type) - PRIORITY.indexOf(b.type));
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
 * Konwój (statki z transportowcem) bez eskorty — statku z bronią. Lustro
 * CaptainProfile::isUnescortedConvoy(): nie atakuje wyspy, w sztormie traci 2×.
 */
export function isUnescortedConvoy(ships: FleetShipDTO[]): boolean {
    return ships.some(s => s.type === 'transportowiec') && !ships.some(s => ARMED.includes(s.type));
}
