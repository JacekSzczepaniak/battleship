// Skład bitewny w limicie pól wyspy (#73) — lustro doboru z CaptainProfile::battleSquad(),
// żeby UI pokazał, co popłynie, zanim gracz kliknie „Bitwa”.

import type { FleetShipDTO, ShipTypeName } from '../api/expeditionApi';

const PRIORITY: ShipTypeName[] = ['lotniskowiec', 'niszczyciel', 'kuter', 'tratwa'];

export function squadCells(ships: FleetShipDTO[]): number {
    return ships.reduce((sum, s) => sum + s.length, 0);
}

/** Nośniki broni od największego, potem tratwy, do limitu; tylko sprawne statki. */
export function defaultSquad(fleet: FleetShipDTO[], limit: number): FleetShipDTO[] {
    const rank = (s: FleetShipDTO) => {
        const i = PRIORITY.indexOf(s.type);
        return i === -1 ? PRIORITY.length : i;
    };
    const candidates = fleet.filter(s => !s.damaged).sort((a, b) => rank(a) - rank(b));
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
