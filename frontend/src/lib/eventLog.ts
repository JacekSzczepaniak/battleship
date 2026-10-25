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
        case 'island-besieged':
            return `Twierdza oblężona: ${island} — flota strażnicza rozbita, banderę da zdobycie fortu.`;
        case 'siege-failed':
            return `Fort się obronił: ${island} — oblężenie trwa, szturm możesz ponowić.`;
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
        case 'ship-boarded':
            return `Abordaż udany: ${prizeLabel(e)} zdobyty (${e.gateName ?? island}) — czeka na decyzję.`;
        case 'prize-decided': {
            if (e.decision === 'join') return `Zdobyty ${prizeLabel(e).toLowerCase()} dołączył do floty — czeka na remont.`;
            if (e.decision === 'study') return `Konstrukcja zbadana: ${prizeLabel(e).toLowerCase()} — stocznia buduje ten wariant, statek poszedł na części.`;
            const lost = e.lost ? `, za burtę: ${formatResources(e.lost)}` : '';
            return `Zdobyty ${prizeLabel(e).toLowerCase()} rozebrany — do ładowni: ${formatResources(e.refund ?? { wood: 0, steel: 0, gold: 0 }, 'nic')}${lost}.`;
        }
        case 'ship-scrapped': {
            const unloaded = e.unloaded ? `; z ładowni zostało w porcie: ${formatResources(e.unloaded)}` : '';
            return `Złomowano: ${prizeLabel(e).toLowerCase()}${e.damaged ? ' (uszkodzony)' : ''} — do magazynu (${island}): ${formatResources(e.refund ?? { wood: 0, steel: 0, gold: 0 }, 'nic')}${unloaded}.`;
        }
        case 'market-trade': {
            const paid = formatResources(e.paid ?? { wood: 0, steel: 0, gold: 0 });
            const received = formatResources(e.received ?? { wood: 0, steel: 0, gold: 0 });
            return e.side === 'sell'
                ? `Rynek (${island}): sprzedano ${paid} za ${received}.`
                : `Rynek (${island}): kupiono ${received} za ${paid}.`;
        }
        case 'ship-sold':
            return `Sprzedano: ${prizeLabel(e).toLowerCase()}${e.damaged ? ' (uszkodzony)' : ''} — do ładowni (${island}): ${formatResources({ wood: 0, steel: 0, gold: e.gold ?? 0 }, 'nic')}.`;
        case 'chart-acquired':
            return e.source === 'expedition'
                ? `Mapa akwenu z ekspedycji: ${e.seaName ?? e.sea} — wody nieznane stoją otworem.`
                : `Kupiono mapę akwenu (${island}): ${e.seaName ?? e.sea} za ${formatResources({ wood: 0, steel: 0, gold: e.gold ?? 0 }, 'nic')}.`;
        case 'encounter-met':
            if (e.encounter === 'bounty-hunters') return 'Łowcy nagród na kursie — embargo ściga korsarza. Bitwa jest jedynym wyjściem.';
            return e.encounter === 'pirate'
                ? 'Piraci na kursie — walka, ucieczka albo okup. Rejs czeka na decyzję.'
                : 'Handlarz na kursie — handel, napad albo minięcie. Rejs czeka na decyzję.';
        case 'reputation-tier':
            return reputationTierText(e);
        case 'encounter-resolved':
            return encounterResolvedText(e);
        case 'field-repair':
            return `Kuter remontowy naprawił: ${prizeLabel(e).toLowerCase()} — z ładowni: ${formatResources(e.paid ?? { wood: 0, steel: 0, gold: 0 }, 'nic')}.`;
        case 'encounter-battle':
            if (e.encounter === 'bounty-hunters') {
                return e.result === 'won' ? 'Łowcy nagród rozbici — ich złoto i stal w ładowni. Embargo trwa.' : 'Łowcy nagród wygrali bitwę — embargo trwa.';
            }
            if (e.encounter === 'pirate') {
                return e.result === 'won' ? 'Piraci pokonani — łup w ładowni.' : 'Piraci wygrali bitwę — odpłynęli z tym, co zatopili.';
            }
            return e.result === 'won' ? 'Napad udany — towary handlarza w ładowni.' : 'Napad odparty — eskorta obroniła handlarza.';
    }
}

// próg reputacji (#142): nowy poziom i jego skutek
function reputationTierText(e: LogEntryDTO): string {
    const value = e.reputation ?? 0;
    const signed = value > 0 ? `+${value}` : value < 0 ? `−${-value}` : '0';
    switch (e.to) {
        case 'merchant': return `Reputacja ${signed}: kupiec — ceny na rynku niższe o 10%.`;
        case 'corsair': return `Reputacja ${signed}: korsarz — ceny na rynku wyższe o 25%.`;
        case 'embargo': return `Reputacja ${signed}: embargo — porty odmawiają handlu, zamiast handlarzy przychodzą łowcy nagród.`;
        default: return `Reputacja ${signed}: neutralna — ceny na rynku bazowe.`;
    }
}

// spotkanie rozstrzygnięte bez bitwy (#141)
function encounterResolvedText(e: LogEntryDTO): string {
    const none = { wood: 0, steel: 0, gold: 0 };
    switch (e.decision) {
        case 'flee': return `Ucieczka przed piratami — za burtą: ${formatResources(e.cargoLost ?? none, 'nic')}, stracony tik.`;
        case 'ransom': return `Okup dla piratów: ${e.gold ?? 0} złota — odpłynęli.`;
        case 'trade': {
            const parts: string[] = [];
            if (e.sold) parts.push(`sprzedane ${formatResources(e.sold)} za ${e.goldReceived ?? 0} złota`);
            if (e.bought) parts.push(`kupione ${formatResources(e.bought)} za ${e.goldPaid ?? 0} złota`);
            return `Handel z handlarzem: ${parts.join(', ')}.`;
        }
        default: return 'Handlarz minięty — każdy płynie w swoją stronę.';
    }
}

function prizeLabel(e: LogEntryDTO): string {
    return e.ship ? fleetShipLabel({ type: e.ship, variant: e.variant ?? null }) : 'Statek';
}

/** Ton wpisu: kolor kropki w banerze i dzienniku. */
export function logTone(e: LogEntryDTO): 'danger' | 'warn' | 'info' {
    switch (e.event) {
        case 'island-lost': case 'counterattack-announced': return 'danger';
        case 'storm': case 'island-plundered': case 'siege-failed': return 'warn';
        case 'encounter-met': return e.encounter === 'bounty-hunters' ? 'danger' : e.encounter === 'pirate' ? 'warn' : 'info';
        case 'reputation-tier': return e.to === 'embargo' ? 'danger' : e.to === 'corsair' ? 'warn' : 'info';
        case 'encounter-battle': return e.result === 'lost' ? 'warn' : 'info';
        default: return 'info';
    }
}
