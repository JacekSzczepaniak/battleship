// Dziennik kapitana po stronie UI (#111): treść wpisu z rodzaju zdarzenia
// i jego danych. Zdarzenia zapisuje backend (CaptainProfile::record).

import {
    BUILDING_LABELS, SHIP_LABELS, TECH_LABELS, fleetShipLabel, type LogEntryDTO,
} from '../api/expeditionApi';
import { formatResources } from './resources';
import { ticksLabel } from './counterattack';

// nazw wysp nie odmieniamy (Mielizny, Archipelag Mgieł) — stoją po dwukropku
export function logText(e: LogEntryDTO, tick?: number): string {
    const island = e.islandName ?? e.island ?? '';
    switch (e.event) {
        case 'storm':
            if (e.effect === 'ship-damaged') return `Sztorm uszkodził statek: ${e.ship ? SHIP_LABELS[e.ship] : '?'} — ładunek cały.`;
            return `Sztorm: fale zabrały ${formatResources(e.cargoLost ?? { wood: 0, steel: 0, gold: 0 }, 'nic — ładownia była pusta')}${e.unescorted ? ' (konwój bez eskorty — podwójne straty)' : ''}.`;
        case 'island-captured':
            return `Bandera na wyspie: ${island}.`;
        case 'island-lost': {
            const lost = formatResources(e.stockpileLost ?? { wood: 0, steel: 0, gold: 0 }, 'nic — magazyn był pusty');
            return `Wyspa utracona: ${island} (${e.absent ? 'nikt nie stanął do obrony' : 'obrona przegrana'}) — z magazynu przepadło: ${lost}.`;
        }
        case 'island-defended':
            return `Wyspa obroniona: ${island} — bandera została.`;
        case 'island-plundered':
            return `Grabież: ${island} — fort utrzymał wyspę, z magazynu przepadło: ${formatResources(e.stockpileLost ?? { wood: 0, steel: 0, gold: 0 }, 'nic')}.`;
        case 'counterattack-announced': {
            const left = tick !== undefined && e.strikeAt !== undefined && e.strikeAt > tick ? ` — za ${ticksLabel(e.strikeAt - tick)}` : '';
            return `Zapowiedź kontrataku: ${island} — uderzenie w tiku ${e.strikeAt}${left}.`;
        }
        case 'research-completed':
            return `Badanie ukończone: ${e.technology ? TECH_LABELS[e.technology] : ''}.`;
        case 'ship-built':
            return `Zwodowano: ${e.ship ? fleetShipLabel({ type: e.ship, variant: e.variant ?? null }) : 'statek'} (${island}).`;
        case 'building-built':
            return `Nowy budynek: ${e.building ? BUILDING_LABELS[e.building] : '?'} (osada: ${island}).`;
        case 'gate-opened':
            return `Strażnik pokonany — brama otwarta na stałe: ${e.gateName ?? e.gate}.`;
        case 'gate-passed':
            return `${e.gateName ?? e.gate} za rufą — witaj na akwenie ${e.toName ?? e.to}.`;
    }
}

/** Ton wpisu: kolor kropki w banerze i dzienniku. */
export function logTone(e: LogEntryDTO): 'danger' | 'warn' | 'info' {
    switch (e.event) {
        case 'island-lost': case 'counterattack-announced': return 'danger';
        case 'storm': case 'island-plundered': return 'warn';
        default: return 'info';
    }
}
