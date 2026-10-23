// Kontrataki po stronie UI (#78): opisy zapowiedzi i rozstrzygnięć. Reguły
// (spokój, szansa, termin) liczy backend — tu tylko je odczytujemy.

import type { IslandDTO, LastCounterattackDTO, PendingCounterattackDTO } from '../api/expeditionApi';
import { formatResources } from './resources';
import { shipName } from './ships';

export function ticksLabel(n: number): string {
    if (n === 1) return '1 tik';
    const lastTwo = n % 100;
    const last = n % 10;
    return last >= 2 && last <= 4 && (lastTwo < 12 || lastTwo > 14) ? `${n} tiki` : `${n} tików`;
}

// nazw wysp nie odmieniamy (Mielizny, Archipelag Mgieł) — stoją po dwukropku

/**
 * „Kontratak: Mielizny — uderzenie za 7 tików” albo informacja o trwającej
 * obronie. Wyspa innego akwenu niż flota dostaje nazwę akwenu (#109).
 */
export function pendingLabel(c: PendingCounterattackDTO): string {
    const island = c.fleetAway && c.sea ? `${c.islandName} (${c.sea.name})` : c.islandName;
    return c.defenseStarted
        ? `Obrona: ${island} — termin wstrzymany do końca bitwy`
        : `Kontratak: ${island} — uderzenie za ${ticksLabel(c.ticksLeft)} (tik ${c.strikeAt})`;
}

/**
 * Ostrzeżenie, gdy cel leży na innym akwenie niż flota (#109): pozostały
 * czas wobec drogi przez bramę. Obronę przyjmuje się przed uderzeniem, więc
 * zdąży tylko droga krótsza niż czas do uderzenia. null = flota na akwenie celu.
 */
export function routeWarning(c: PendingCounterattackDTO): { text: string; inTime: boolean } | null {
    if (!c.fleetAway || c.defenseStarted) return null;
    const sea = c.sea?.name ?? 'inny akwen';
    if (!c.route) return { text: `Flota jest poza akwenem ${sea}, a przejście jest zamknięte — nie zdążysz przed uderzeniem.`, inTime: false };
    const gates = c.route.gates === 1 ? 'przez bramę' : `przez ${c.route.gates} bramy`;
    const verdict = c.route.inTime ? 'zdążysz' : 'nie zdążysz';
    return {
        text: `Flota jest poza akwenem ${sea}: droga ${gates} to ${ticksLabel(c.route.ticks)}, do uderzenia ${ticksLabel(c.ticksLeft)} — ${verdict}.`,
        inTime: c.route.inTime,
    };
}

/**
 * Jak przebiegnie obrona: kolejność strzałów zależy od pełnej bandery (#79),
 * a skutek nieobecności od fortu na wyspie (#81).
 */
export function defenseHint(c: PendingCounterattackDTO): string {
    const battle = c.fullFlag
        ? 'Stań flotą na wyspie i przyjmij bitwę.'
        : 'Stań flotą na wyspie i przyjmij bitwę — napastnik strzela pierwszy.';
    return `${battle} ${c.fort ? `Bez obrony ${FORT_GUARD.toLowerCase()}.` : 'Bez obrony wyspa przepada razem z połową magazynu.'}`;
}

/** Skutek nieobecności na wyspie z fortem w zapowiedzi (zasada telegrafowania, #81). */
export const FORT_GUARD = 'Fort obroni wyspę przed utratą, magazyn straci połowę';

/** Bonus pełnej bandery w zapowiedzi (zasada telegrafowania, #79). */
export const FULL_FLAG_BONUS = 'Pełna bandera: strzelasz pierwszy, a napastnik płynie bez największego statku';

/**
 * Statek, bez którego płynie napastnik: różnica składów stron bitwy.
 * null = ten sam skład (napastnik z jednym statkiem zachowuje go, #79).
 */
export function attackerMissingShip(own: Record<string, number>, attacker: Record<string, number>): number | null {
    const missing = Object.keys(own).map(Number).filter(l => (own[l] ?? 0) > (attacker[l] ?? 0));
    return missing.length ? Math.max(...missing) : null;
}

/** Pasek bonusu na ekranie bitwy obronnej przy pełnej banderze. */
export function battleBonusLabel(missing: number | null): string {
    return missing !== null
        ? `Pełna bandera: strzelasz pierwszy, napastnik płynie bez statku: ${shipName(missing).toLowerCase()}`
        : 'Pełna bandera: strzelasz pierwszy (napastnik ma jeden statek, więc płynie w pełnym składzie)';
}

/** Ostatnie rozstrzygnięcie: obrona, przegrana obrona, grabież pod fortem albo utrata pod nieobecność. */
export function lastLabel(c: LastCounterattackDTO): string {
    if (c.held) return `Tik ${c.tick} · wyspa obroniona: ${c.islandName} — bandera została`;
    const lost = formatResources(c.stockpileLost, 'nic — magazyn był pusty');
    if (c.plundered) return `Tik ${c.tick} · grabież: ${c.islandName} (nikt nie stanął do obrony) — fort utrzymał wyspę, z magazynu przepadło: ${lost}`;
    const why = c.absent ? 'nikt nie stanął do obrony' : 'obrona przegrana';
    return `Tik ${c.tick} · wyspa utracona: ${c.islandName} (${why}) — z magazynu przepadło: ${lost}`;
}

/** Ikona rozstrzygnięcia: obrona, grabież pod fortem albo utrata wyspy. */
export function lastIcon(c: LastCounterattackDTO): string {
    return c.held ? '🛡️' : c.plundered ? '🏰' : '🏴';
}

/** Czy na tej wyspie można teraz przyjąć bitwę obronną (flota na miejscu sprawdzana osobno). */
export function canDefend(island: IslandDTO, pending: PendingCounterattackDTO | null): boolean {
    return !!pending && pending.island === island.id && !pending.defenseStarted && pending.ticksLeft > 0;
}
