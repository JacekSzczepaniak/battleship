// Efekty wspólne (#145, ANIMACJE.md „Wspólne efekty”): trafienie, pudło,
// zatopienie, plus eksplozja magazynu w oblężeniu (#119). Kafle trafienia
// i pudła są trwałe — zostają do przerysowania planszy (`FxBoard.clear`).

import type { Cell, FxBoard } from './board';
import { animateOnce, mk, px, type FxToken } from './core';

export type ImpactResult = 'hit' | 'miss' | 'sunk' | 'duplicate';

const FADE_REDUCED = 150;

/** Kafel w miejscu komórki (promień jak w SeaGrid), z treścią wyśrodkowaną. */
function tile(b: FxBoard, x: number, y: number, background: string, boxShadow: string): HTMLDivElement {
    const r = b.rect(x, y);
    return mk(b.layer, {
        left: px(r.left), top: px(r.top), width: px(r.size), height: px(r.size),
        borderRadius: '4px', background, boxShadow,
        display: 'flex', alignItems: 'center', justifyContent: 'center',
    });
}

function dot(parent: HTMLElement, style: Partial<CSSStyleDeclaration>): void {
    const el = document.createElement('div');
    Object.assign(el.style, { borderRadius: '50%', pointerEvents: 'none' }, style);
    parent.appendChild(el);
}

export function shake(tok: FxToken, b: FxBoard, strength: 3 | 4): void {
    const s = strength;
    const half = s === 4 ? 2 : 1;
    b.shaker.animate([
        { transform: 'translate(0,0)' },
        { transform: `translate(${-s}px,${half}px)` },
        { transform: `translate(${s}px,${-half}px)` },
        { transform: `translate(-2px,${half - 1}px)` },
        { transform: 'translate(0,0)' },
    ], { duration: tok.d(s === 4 ? 340 : 260) });
}

function hitTile(tok: FxToken, b: FxBoard, x: number, y: number, fadeMs: number): void {
    const m = tile(b, x, y, '#4a3327', 'inset 0 0 0 1px #7a4a33');
    const d = Math.round(15 * b.k);
    dot(m, { width: px(d), height: px(d), background: '#e8843c', boxShadow: '0 0 12px #e8843c99' });
    m.animate([{ opacity: 0 }, { opacity: 1 }], { duration: tok.d(fadeMs), fill: 'forwards' });
}

function missTile(tok: FxToken, b: FxBoard, x: number, y: number, fadeMs: number): void {
    const w = tile(b, x, y, '#0c2029', 'inset 0 0 0 1px #163540');
    const d = Math.max(6, Math.round(8 * b.k));
    dot(w, { width: px(d), height: px(d), boxShadow: 'inset 0 0 0 1.6px #5f8089' });
    w.animate([{ opacity: 0 }, { opacity: 1 }], { duration: tok.d(fadeMs), fill: 'forwards' });
}

/** Trafienie: kafel, kropka, błysk, pierścień, 6 iskier, wstrząs planszy. */
export function hit(tok: FxToken, b: FxBoard, x: number, y: number, withShake = true): void {
    hitTile(tok, b, x, y, 200);
    const c = b.center(x, y);
    const k = b.k;

    const fs = 60 * k;
    const flash = mk(b.layer, {
        left: px(c.x - fs / 2), top: px(c.y - fs / 2), width: px(fs), height: px(fs), borderRadius: '50%',
        background: 'radial-gradient(circle,#fff3e0 0%,#f3a45f 35%,#e8843c55 60%,transparent 70%)',
    });
    animateOnce(flash, [{ transform: 'scale(.3)', opacity: 1 }, { transform: 'scale(1.3)', opacity: 0 }],
        { duration: tok.d(520), easing: 'ease-out' });

    const r = b.rect(x, y);
    const ring = mk(b.layer, {
        left: px(r.left), top: px(r.top), width: px(r.size), height: px(r.size),
        borderRadius: '50%', border: `${Math.max(2, 3 * k)}px solid #e8843c`, boxSizing: 'border-box',
    });
    animateOnce(ring, [{ transform: 'scale(.4)', opacity: 1 }, { transform: 'scale(2)', opacity: 0 }],
        { duration: tok.d(600), easing: 'ease-out' });

    for (let i = 0; i < 6; i++) {
        const a = (i * 60 + 15) * Math.PI / 180;
        const s = mk(b.layer, { left: px(c.x - 2), top: px(c.y - 2), width: '4px', height: '4px', borderRadius: '50%', background: '#f3a45f' });
        animateOnce(s, [
            { transform: 'translate(0,0)', opacity: 1 },
            { transform: `translate(${Math.cos(a) * 28 * k}px,${Math.sin(a) * 28 * k}px)`, opacity: 0 },
        ], { duration: tok.d(480), easing: 'cubic-bezier(.1,.7,.3,1)' });
    }

    if (withShake) shake(tok, b, 3);
}

/** Pudło: kafel wody z pierścieniem i rozprysk. */
export function miss(tok: FxToken, b: FxBoard, x: number, y: number): void {
    missTile(tok, b, x, y, 250);
    const c = b.center(x, y);
    const s = 28 * b.k;
    const splash = mk(b.layer, {
        left: px(c.x - s / 2), top: px(c.y - s / 2), width: px(s), height: px(s),
        borderRadius: '50%', border: '1.6px solid #8fb4bd', boxSizing: 'border-box',
    });
    animateOnce(splash, [{ transform: 'scale(.3)', opacity: 1 }, { transform: 'scale(1.5)', opacity: 0 }],
        { duration: tok.d(500), easing: 'ease-out' });
}

/** Wynik pola z silnika: trafienie / pudło; duplikat nie zmienia planszy. */
export function impact(tok: FxToken, b: FxBoard, x: number, y: number, result: ImpactResult, withShake = true): void {
    if (result === 'hit' || result === 'sunk') hit(tok, b, x, y, withShake);
    else if (result === 'miss') miss(tok, b, x, y);
}

/** Zatopienie: ciemne kafle z kropką, obrys całego statku, mocniejszy wstrząs. */
export function sunk(tok: FxToken, b: FxBoard, cells: Cell[]): void {
    if (cells.length === 0) return;
    const k = b.k;
    const d = Math.round(16 * k);
    for (const [x, y] of cells) {
        const s = tile(b, x, y, '#3a1c12', 'inset 0 0 0 1px #7a3a22');
        dot(s, { width: px(d), height: px(d), background: '#c8624a', boxShadow: '0 0 6px #c8624a77' });
        s.animate([{ opacity: 0 }, { opacity: 1 }], { duration: tok.d(400), fill: 'forwards' });
    }
    const xs = cells.map(c => c[0]);
    const ys = cells.map(c => c[1]);
    const a = b.rect(Math.min(...xs), Math.min(...ys));
    const z = b.rect(Math.max(...xs), Math.max(...ys));
    const pad = 4 * k;
    const hull = mk(b.layer, {
        left: px(a.left - pad), top: px(a.top - pad),
        width: px(z.left + z.size - a.left + 2 * pad), height: px(z.top + z.size - a.top + 2 * pad),
        borderRadius: px(Math.max(6, 12 * k)), border: '2px solid #e8843c', boxSizing: 'border-box',
    });
    hull.animate([{ opacity: 0, transform: 'scale(1.15)' }, { opacity: 1, transform: 'scale(1)' }],
        { duration: tok.d(400), easing: 'ease-out', fill: 'forwards' });
    shake(tok, b, 4);
}

/**
 * Eksplozja magazynu prochu (#119): efekt trafienia na wszystkich polach
 * eksplozji (9, a z Lontami prochowymi 25, #177), falą od magazynu, pierścień
 * po pierścieniu, z jednym mocnym wstrząsem.
 */
export async function blast(tok: FxToken, b: FxBoard, at: Cell, cells: Cell[]): Promise<void> {
    const ring = (c: Cell) => Math.max(Math.abs(c[0] - at[0]), Math.abs(c[1] - at[1]));
    const rings = Math.max(0, ...cells.map(ring));
    shake(tok, b, 4);
    for (let dist = 0; dist <= rings; dist++) {
        cells.filter(c => ring(c) === dist).forEach(([x, y]) => hit(tok, b, x, y, false));
        await tok.wait(120);
    }
}

// --- prefers-reduced-motion: sam wynik z krótkim fade ---

export function impactReduced(tok: FxToken, b: FxBoard, x: number, y: number, result: ImpactResult): void {
    if (result === 'hit' || result === 'sunk') hitTile(tok, b, x, y, FADE_REDUCED);
    else if (result === 'miss') missTile(tok, b, x, y, FADE_REDUCED);
}

export function sonarTile(tok: FxToken, b: FxBoard, x: number, y: number, occupied: boolean, blink: boolean): void {
    if (occupied) {
        const m = tile(b, x, y, '#123c46', 'inset 0 0 0 2px #3fb6c4, 0 0 14px #3fb6c455');
        m.animate(blink
            ? [{ opacity: 0 }, { opacity: 1 }, { opacity: .35 }, { opacity: 1 }, { opacity: .35 }, { opacity: 1 }]
            : [{ opacity: 0 }, { opacity: 1 }],
        { duration: tok.d(blink ? 900 : FADE_REDUCED), fill: 'forwards' });
    } else {
        const w = tile(b, x, y, '#0f2f38', 'inset 0 0 0 1px #1f5c66');
        w.animate([{ opacity: 0 }, { opacity: 1 }], { duration: tok.d(blink ? 250 : FADE_REDUCED), fill: 'forwards' });
    }
}

export const REDUCED_MS = FADE_REDUCED;
