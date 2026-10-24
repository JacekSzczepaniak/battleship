// Rejs floty na mapie akwenu (#146, ANIMACJE.md sekcja 01). Dopasowanie do reguł
// gry: jeden ruch na kliknięcie (1 sektor albo 2 przez sektor pośredni, #98),
// mgła odsłania się dokładnie o sektory z `discoveredNow` (widoczność akwenu,
// sąsiedztwo jak w grze), brama kończy się mapą nowego akwenu. Wynik rejsu
// jest znany z API przed startem — animacja tylko opóźnia jego pokazanie.

import type { Cell, FxBoard } from './board';
import { animateOnce, fade, mk, px, type FxToken } from './core';

const ROUTE = '#3fb6c4';
const FLEET = '#e8843c';
const PORTAL = '#6f86d6';
const FADE_REDUCED = 150;

/** Sektor pośredni rejsu o 2 jak w CaptainProfile::sail(): krok od startu w stronę celu. */
export function passedSector(from: Cell, to: Cell): Cell | null {
    const distance = Math.max(Math.abs(to[0] - from[0]), Math.abs(to[1] - from[1]));
    if (distance !== 2) return null;
    return [from[0] + Math.sign(to[0] - from[0]), from[1] + Math.sign(to[1] - from[1])];
}

const key = ([x, y]: Cell) => `${x}:${y}`;
const cellOf = (k: string): Cell => k.split(':').map(Number) as Cell;

/**
 * Mgła nad sektorami odkrytymi w tym rejsie: kafle w overlayu, schodzące
 * w chwili wpłynięcia. Mapa pod spodem trzyma te sektory zamaskowane
 * (`onReveal` zdejmuje maskę), żeby wyspa nie wystawała spod kafla.
 */
class Fog {
    private tiles = new Map<string, HTMLDivElement>();
    private onReveal: (keys: string[]) => void;

    constructor(b: FxBoard, keys: string[], onReveal: (keys: string[]) => void) {
        this.onReveal = onReveal;
        for (const k of keys) {
            const r = b.rect(...cellOf(k));
            this.tiles.set(k, mk(b.layer, {
                left: px(r.left), top: px(r.top), width: px(r.size), height: px(r.size),
                borderRadius: '8px', background: '#0a1b23', boxShadow: 'inset 0 0 0 1px #12303a',
            }));
        }
    }

    reveal(tok: FxToken, keys: string[], ms = 380): void {
        const now = keys.filter(k => this.tiles.has(k));
        if (now.length === 0) return;
        this.onReveal(now);
        for (const k of now) {
            const el = this.tiles.get(k)!;
            this.tiles.delete(k);
            animateOnce(el, [{ opacity: 1, transform: 'scale(1)' }, { opacity: 0, transform: 'scale(.85)' }], { duration: tok.d(ms), easing: 'ease-out' });
        }
    }

    rest(): string[] {
        return [...this.tiles.keys()];
    }
}

function boatAt(b: FxBoard, cell: Cell): { el: HTMLDivElement; at: (c: Cell) => string } {
    const size = 26 * b.k;
    const at = (c: Cell) => {
        const p = b.center(...c);
        return `translate(${px(p.x - size / 2)},${px(p.y - size / 2)})`;
    };
    const el = mk(b.layer, {
        left: '0', top: '0', width: px(size), height: px(size), borderRadius: '50%', background: FLEET,
        boxShadow: `0 0 0 ${px(4 * b.k)} #e8843c33, 0 0 16px #e8843c99`, transform: at(cell),
    });
    return { el, at };
}

/** Dwa pierścienie dokowania na sektorze floty (2px, scale 1→2,4, 700 ms, odstęp 220 ms). */
async function dockRings(tok: FxToken, b: FxBoard, cell: Cell, color = FLEET): Promise<void> {
    const c = b.center(...cell);
    const size = 36 * b.k;
    for (let i = 0; i < 2; i++) {
        const ring = mk(b.layer, {
            left: px(c.x - size / 2), top: px(c.y - size / 2), width: px(size), height: px(size),
            borderRadius: '50%', border: `2px solid ${color}`,
        });
        animateOnce(ring, [{ transform: 'scale(1)', opacity: .9 }, { transform: 'scale(2.4)', opacity: 0 }], { duration: tok.d(700), easing: 'ease-out' });
        await tok.wait(220);
    }
    await tok.wait(480);
}

export interface VoyageFx {
    from: Cell;
    to: Cell;
    /** Sektory odkryte w tym rejsie (SailResultDTO.discoveredNow). */
    discovered: string[];
    /** Mapa zdejmuje maskę z sektorów w chwili odsłonięcia. */
    onReveal: (keys: string[]) => void;
    /** Po ostatnim kroku: zapis stanu (TIK +1, baner, spotkanie). */
    onArrive: () => void;
    /** Sztorm albo spotkanie: rejs staje na sektorze docelowym, bez dokowania. */
    stopped: boolean;
    /** Wpłynięcie na wyspę, port albo bramę: pierścienie dokowania. */
    docks: boolean;
}

/**
 * Pełna sekwencja: trasa (700 ms), cel (500 ms), pauza 850 ms, krok na każdy
 * sektor drogi (420 ms, ślad, mgła po 240 ms), potem zapis stanu i dokowanie
 * albo zatrzymanie na zdarzeniu.
 */
export async function playVoyage(tok: FxToken, b: FxBoard, v: VoyageFx): Promise<void> {
    const passed = passedSector(v.from, v.to);
    const path: Cell[] = passed ? [v.from, passed, v.to] : [v.from, v.to];
    // sektor pośredni odkrywa tylko siebie (#98); reszta to sąsiedztwo celu
    const atPassed = passed && v.discovered.includes(key(passed)) ? [key(passed)] : [];
    const fog = new Fog(b, v.discovered, v.onReveal);

    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('width', String(b.width));
    svg.setAttribute('height', String(b.height));
    Object.assign(svg.style, { position: 'absolute', left: '0', top: '0', overflow: 'visible', pointerEvents: 'none' });
    const line = document.createElementNS(ns, 'polyline');
    line.setAttribute('points', path.map(c => { const p = b.center(...c); return `${p.x},${p.y}`; }).join(' '));
    Object.entries({ fill: 'none', stroke: ROUTE, 'stroke-width': '2.5', 'stroke-linejoin': 'round', 'stroke-linecap': 'round', opacity: '.55' })
        .forEach(([k, val]) => line.setAttribute(k, val));
    svg.appendChild(line);
    b.layer.appendChild(svg);
    const len = line.getTotalLength();
    line.style.strokeDasharray = String(len);
    line.animate([{ strokeDashoffset: len }, { strokeDashoffset: 0 }], { duration: tok.d(700), easing: 'ease-out', fill: 'forwards' });

    const t = b.center(...v.to);
    const tsize = 48 * b.k;
    const target = mk(b.layer, {
        left: px(t.x - tsize / 2), top: px(t.y - tsize / 2), width: px(tsize), height: px(tsize),
        borderRadius: '50%', border: `2px dashed ${FLEET}`, opacity: '0',
    });
    target.animate([{ opacity: 0, transform: 'scale(1.4) rotate(0deg)' }, { opacity: 1, transform: 'scale(1) rotate(90deg)' }], { duration: tok.d(500), easing: 'ease-out', fill: 'forwards' });

    const boat = boatAt(b, v.from);
    await tok.wait(850);

    for (let i = 1; i < path.length; i++) {
        const left = b.center(...path[i - 1]);
        const wsize = 8 * b.k;
        const wake = mk(b.layer, { left: px(left.x - wsize / 2), top: px(left.y - wsize / 2), width: px(wsize), height: px(wsize), borderRadius: '50%', background: ROUTE });
        animateOnce(wake, [{ opacity: .9, transform: 'scale(1)' }, { opacity: 0, transform: 'scale(.4)' }], { duration: tok.d(1400) });
        boat.el.animate([{ transform: boat.at(path[i - 1]) }, { transform: boat.at(path[i]) }], { duration: tok.d(420), easing: 'cubic-bezier(.45,0,.25,1)', fill: 'forwards' });
        await tok.wait(240);
        fog.reveal(tok, i < path.length - 1 ? atPassed : fog.rest());
        await tok.wait(200);
    }

    v.onArrive();
    fade(tok, target, 300);
    fade(tok, svg, 300);
    if (v.stopped) return;
    if (v.docks) await dockRings(tok, b, v.to);
    else await tok.wait(300);
}

export interface GateFx {
    /** Sektor bramy na starym akwenie. */
    at: Cell;
    /** Przełączenie mapy na nowy akwen; zwraca jej geometrię po przerysowaniu. */
    swap: () => Promise<FxBoard | null>;
    /** Sektor floty na nowym akwenie i sektory odkryte przy wpłynięciu. */
    to: Cell;
    discovered: string[];
    onReveal: (keys: string[]) => void;
    onArrive: () => void;
    stopped: boolean;
}

/**
 * Przejście przez bramę: flota wpływa w portal, mapa gaśnie, a sekwencja
 * kończy się na mapie nowego akwenu — wynurzenie floty, mgła, pierścienie.
 */
export async function playGate(tok: FxToken, b: FxBoard, g: GateFx): Promise<void> {
    const boat = boatAt(b, g.at);
    const c = b.center(...g.at);
    const size = 36 * b.k;
    const ring = mk(b.layer, {
        left: px(c.x - size / 2), top: px(c.y - size / 2), width: px(size), height: px(size),
        borderRadius: '50%', border: `2px solid ${PORTAL}`,
    });
    animateOnce(ring, [{ transform: 'scale(2.2)', opacity: 0 }, { transform: 'scale(1)', opacity: .9 }, { transform: 'scale(.4)', opacity: 0 }], { duration: tok.d(700), easing: 'ease-in' });
    boat.el.animate([{ transform: `${boat.at(g.at)} scale(1) rotate(0deg)`, opacity: 1 }, { transform: `${boat.at(g.at)} scale(.1) rotate(200deg)`, opacity: 0 }], { duration: tok.d(500), easing: 'ease-in', fill: 'forwards' });
    await tok.wait(450);
    b.shaker.animate([{ opacity: 1 }, { opacity: 0 }], { duration: tok.d(220), fill: 'forwards' });
    await tok.wait(220);

    // warstwa bez wstrząsu: wygaszenie mapy trwa do wynurzenia na nowym akwenie
    b.layer.replaceChildren();
    const nb = await g.swap();
    if (!nb) { b.shaker.getAnimations().forEach(a => a.cancel()); return; }
    nb.shaker.getAnimations().forEach(a => a.cancel());
    const fog = new Fog(nb, g.discovered, g.onReveal);
    nb.shaker.animate([{ opacity: 0 }, { opacity: 1 }], { duration: tok.d(320), easing: 'ease-out' });
    const nboat = boatAt(nb, g.to);
    nboat.el.animate([{ transform: `${nboat.at(g.to)} scale(.1)`, opacity: 0 }, { transform: `${nboat.at(g.to)} scale(1)`, opacity: 1 }], { duration: tok.d(420), easing: 'ease-out', fill: 'forwards' });
    await tok.wait(320);
    fog.reveal(tok, fog.rest());
    await tok.wait(200);
    g.onArrive();
    if (g.stopped) return;
    await dockRings(tok, nb, g.to, PORTAL);
}

/** prefers-reduced-motion: stan od razu, mgła nad odkrytymi sektorami schodzi w 150 ms. */
export function revealReduced(tok: FxToken, b: FxBoard, discovered: string[]): void {
    new Fog(b, discovered, () => {}).reveal(tok, discovered, FADE_REDUCED);
}
