// Animacje broni (#145, ANIMACJE.md 02–05) dopasowane do reguł gry:
// torpeda z wyrzutni w dowolnym z 8 kierunków, sonar krzyżem o promieniu
// z reguł broni, nalot na wybranym (przyciętym do planszy) obszarze.
// Wynik pól przychodzi z silnika — animacja tylko opóźnia jego pokazanie.
// Ruch AI odtwarzają te same funkcje na planszy gracza.

import type { Cell, FxBoard, Point } from './board';
import { animateOnce, fade, mk, px, type FxToken } from './core';
import { blast, impact, impactReduced, REDUCED_MS, sonarTile, sunk, type ImpactResult } from './effects';
import { fxMode } from './speed';

export interface CellResult { x: number; y: number; result: ImpactResult }
export interface SonarResult { x: number; y: number; occupied: boolean }

/**
 * Skutki ruchu znane z projekcji po strzale: statki zatopione tym ruchem
 * (uciszone działo fortu to też „zatopienie”) i eksplozja magazynu.
 */
export interface FxOutcome {
    sunk: Cell[][];
    blast?: { at: Cell; cells: Cell[] } | null;
}

const NO_OUTCOME: FxOutcome = { sunk: [] };

/** Wynik pola, eksplozja w chwili trafienia magazynu. Zwraca obietnicę fali eksplozji. */
function resolveCell(tok: FxToken, b: FxBoard, c: CellResult, outcome: FxOutcome): Promise<void> | null {
    impact(tok, b, c.x, c.y, c.result);
    const bl = outcome.blast;
    // fala eksplozji biegnie równolegle z resztą sekwencji; przerwanie łapie główny tok
    if (bl && bl.at[0] === c.x && bl.at[1] === c.y) return blast(tok, b, bl.at, bl.cells).catch(() => undefined);
    return null;
}

async function finishSunk(tok: FxToken, b: FxBoard, outcome: FxOutcome): Promise<void> {
    if (outcome.sunk.length === 0) return;
    await tok.wait(450);
    for (const ship of outcome.sunk) {
        sunk(tok, b, ship);
        await tok.wait(400);
    }
}

/** prefers-reduced-motion: wszystkie wyniki naraz z fade 150 ms. */
async function reduced(tok: FxToken, b: FxBoard, cells: CellResult[], outcome: FxOutcome): Promise<void> {
    const blastCells = outcome.blast?.cells ?? [];
    cells.forEach(c => impactReduced(tok, b, c.x, c.y, c.result));
    blastCells.forEach(([x, y]) => impactReduced(tok, b, x, y, 'hit'));
    await tok.wait(REDUCED_MS);
}

// --- 05 · Salwa ---

async function shell(tok: FxToken, b: FxBoard, x: number, y: number): Promise<void> {
    const k = b.k;
    const r = b.rect(x, y);
    const chS = r.size + 4 * k;
    const ch = mk(b.layer, {
        left: px(r.left - 2 * k), top: px(r.top - 2 * k), width: px(chS), height: px(chS),
        borderRadius: '50%', border: '2px solid #e8843c', boxSizing: 'border-box',
        display: 'flex', alignItems: 'center', justifyContent: 'center',
    });
    const pip = document.createElement('div');
    Object.assign(pip.style, { width: '6px', height: '6px', borderRadius: '50%', background: '#e8843c' });
    ch.appendChild(pip);
    ch.animate([{ opacity: 0, transform: 'scale(1.4)' }, { opacity: 1, transform: 'scale(1)' }],
        { duration: tok.d(220), easing: 'ease-out', fill: 'forwards' });
    await tok.wait(260);

    // start spod dolnej krawędzi planszy, łuk przez apex nad niższym z punktów
    const sx = b.width / 2;
    const sy = b.height + 1.5 * b.pitch;
    const t0 = b.center(x, y);
    const apex = Math.min(sy, t0.y) - 70 * k;
    const kf: Keyframe[] = [];
    const sk: Keyframe[] = [];
    for (let i = 0; i <= 8; i++) {
        const t = i / 8;
        const px0 = sx + (t0.x - sx) * t;
        const py = (1 - t) * (1 - t) * sy + 2 * (1 - t) * t * apex + t * t * t0.y;
        const s = 1 + 0.8 * Math.sin(Math.PI * t);
        kf.push({ transform: `translate(${px0 - 4}px,${py - 4}px) scale(${s})` });
        const gy = sy + (t0.y - sy) * t;
        sk.push({ transform: `translate(${px0 - 6}px,${gy - 3}px) scale(${0.4 + 0.6 * t})`, opacity: 0.15 + 0.45 * t });
    }
    const shadow = mk(b.layer, { left: '0', top: '0', width: '12px', height: '6px', borderRadius: '50%', background: 'rgba(0,0,0,.6)', filter: 'blur(2px)' });
    const ball = mk(b.layer, { left: '0', top: '0', width: '8px', height: '8px', borderRadius: '50%', background: '#e8f0f0', boxShadow: '0 0 8px #e8f0f0, 0 0 14px #e8843c88' });
    animateOnce(shadow, sk, { duration: tok.d(650), easing: 'linear' });
    animateOnce(ball, kf, { duration: tok.d(650), easing: 'cubic-bezier(.3,0,.7,1)' });
    await tok.wait(650);
    fade(tok, ch, 200);
}

export async function playShot(tok: FxToken, b: FxBoard, cell: CellResult, outcome: FxOutcome = NO_OUTCOME): Promise<void> {
    const mode = fxMode();
    if (mode === 'off') return;
    if (mode === 'reduced') return reduced(tok, b, [cell], outcome);
    await shell(tok, b, cell.x, cell.y);
    const wave = resolveCell(tok, b, cell, outcome);
    if (wave) await wave;
    await finishSunk(tok, b, outcome);
}

// --- 02 · Torpeda ---

/**
 * Torpeda: `cells` to tor z silnika w kolejności przejścia, od pola wyrzutni
 * do krawędzi; `step` = wektor kierunku (dx, dy), także po skosie.
 */
export async function playTorpedo(tok: FxToken, b: FxBoard, cells: CellResult[], step: Cell, outcome: FxOutcome = NO_OUTCOME): Promise<void> {
    const mode = fxMode();
    if (mode === 'off' || cells.length === 0) return;
    if (mode === 'reduced') return reduced(tok, b, cells, outcome);
    const k = b.k;
    const first = b.center(cells[0].x, cells[0].y);
    const last = b.center(cells[cells.length - 1].x, cells[cells.length - 1].y);
    const v: Point = { x: step[0] * b.pitch, y: step[1] * b.pitch };
    const angle = Math.atan2(v.y, v.x) * 180 / Math.PI;

    // 1. pas toru (obrócony wzdłuż kierunku)
    const along = Math.hypot(last.x - first.x, last.y - first.y);
    const bandLen = along + b.size + 12 * k;
    const bandH = b.size + 8 * k;
    const band = mk(b.layer, {
        left: px((first.x + last.x) / 2 - bandLen / 2), top: px((first.y + last.y) / 2 - bandH / 2),
        width: px(bandLen), height: px(bandH), borderRadius: px(Math.max(8, 12 * k)),
        border: '1.5px dashed #e8843c', background: '#e8843c14', boxSizing: 'border-box',
        transform: `rotate(${angle}deg)`,
    });
    band.animate([{ opacity: 0 }, { opacity: 1 }], { duration: tok.d(300), fill: 'forwards' });
    await tok.wait(600);

    // 2. torpeda z kilwaterem: od pola przed wyrzutnią do pola za krawędzią
    const start: Point = { x: first.x - v.x, y: first.y - v.y };
    const end: Point = { x: last.x + v.x, y: last.y + v.y };
    const dist = Math.hypot(end.x - start.x, end.y - start.y);
    // 1,5 s na 10 kroków w projekcie; tor 2–16 pól → 0,9–2,4 s
    const DUR = Math.min(2400, Math.max(900, 1500 * (cells.length + 2) / 10));
    const tw = 24 * k;
    const th = Math.max(4, 6 * k);
    const at = (p: Point) => `translate(${p.x - tw / 2}px,${p.y - th / 2}px) rotate(${angle}deg)`;
    const wake = mk(b.layer, {
        left: px(start.x), top: px(start.y - 1), height: '2px', width: '0px', borderRadius: '2px',
        background: 'linear-gradient(90deg,transparent,#3fb6c4bb)',
        transformOrigin: '0 50%', transform: `rotate(${angle}deg)`,
    });
    const torpedo = mk(b.layer, {
        left: '0', top: '0', width: px(tw), height: px(th), borderRadius: px(th / 2),
        background: '#e8f0f0', boxShadow: '0 0 10px #e8f0f0aa, -8px 0 14px #3fb6c4', transform: at(start),
    });
    torpedo.animate([{ transform: at(start) }, { transform: at(end) }], { duration: tok.d(DUR), easing: 'linear', fill: 'forwards' });
    wake.animate([{ width: '0px' }, { width: px(dist) }], { duration: tok.d(DUR), easing: 'linear', fill: 'forwards' });

    // 3. wynik pola w chwili, gdy torpeda mija jego środek
    let t = 0;
    const waves: Promise<void>[] = [];
    for (const c of cells) {
        const p = b.center(c.x, c.y);
        const when = Math.hypot(p.x - start.x, p.y - start.y) / dist * DUR;
        await tok.wait(when - t);
        t = when;
        const wave = resolveCell(tok, b, c, outcome);
        if (wave) waves.push(wave);
    }
    await tok.wait(DUR - t);
    await Promise.all(waves);

    // 4. sprzątanie
    fade(tok, torpedo, 200);
    fade(tok, wake, 600);
    fade(tok, band, 400);
    await finishSunk(tok, b, outcome);
}

// --- 03 · Nalot ---

/** Nalot: obszar = pola z silnika (wybrany, najwyżej 3×3, przycięty do planszy). */
export async function playAirstrike(tok: FxToken, b: FxBoard, cells: CellResult[], outcome: FxOutcome = NO_OUTCOME): Promise<void> {
    const mode = fxMode();
    if (mode === 'off' || cells.length === 0) return;
    if (mode === 'reduced') return reduced(tok, b, cells, outcome);
    const k = b.k;
    const xs = cells.map(c => c.x);
    const ys = cells.map(c => c.y);
    const a = b.rect(Math.min(...xs), Math.min(...ys));
    const z = b.rect(Math.max(...xs), Math.max(...ys));
    const pad = 6 * k;

    // 1. celownik obszaru
    const area = mk(b.layer, {
        left: px(a.left - pad), top: px(a.top - pad),
        width: px(z.left + z.size - a.left + 2 * pad), height: px(z.top + z.size - a.top + 2 * pad),
        borderRadius: px(Math.max(8, 14 * k)), border: '2px dashed #e8843c', background: '#e8843c10', boxSizing: 'border-box',
    });
    area.animate([{ opacity: 0, transform: 'scale(1.25)' }, { opacity: 1, transform: 'scale(1)' }],
        { duration: tok.d(350), easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'forwards' });
    await tok.wait(650);

    // 2. przelot po skosie (40°) przez środek obszaru: cień samolotu i smuga
    const cx = (a.left + z.left + z.size) / 2;
    const cy = (a.top + z.top + z.size) / 2;
    const rad = 40 * Math.PI / 180;
    const reach = Math.hypot(b.width, b.height) * 0.75 + 120 * k;
    const ox = 130 * k;
    const oy = 15 * k;
    const pos = (s: number) => `translate(${cx + Math.cos(rad) * s - ox}px,${cy + Math.sin(rad) * s - oy}px) rotate(40deg)`;
    const plane = mk(b.layer, { left: '0', top: '0', width: px(160 * k), height: px(30 * k), transformOrigin: `${ox}px ${oy}px`, transform: pos(-reach) });
    mk(plane, { left: '0', top: px(13 * k), width: px(110 * k), height: '2px', borderRadius: '2px', background: 'linear-gradient(90deg,transparent,#e8f0f055)' });
    mk(plane, { left: px(100 * k), top: px(2 * k), width: px(60 * k), height: px(26 * k), borderRadius: '50%', background: 'rgba(0,0,0,.55)', filter: 'blur(5px)' });
    animateOnce(plane, [{ transform: pos(-reach) }, { transform: pos(reach) }], { duration: tok.d(1400), easing: 'linear' });
    await tok.wait(420);

    // 3. zrzuty co 90 ms po przekątnej (x+y rosnąco); wynik pola po opadnięciu bomby
    const ordered = [...cells].sort((p, q) => (p.x + p.y) - (q.x + q.y));
    const waves: Promise<void>[] = [];
    const bs = 10 * k;
    for (const c of ordered) {
        const p = b.center(c.x, c.y);
        const bomb = mk(b.layer, { left: px(p.x - bs / 2), top: px(p.y - bs / 2), width: px(bs), height: px(bs), borderRadius: '50%', background: '#e8f0f0', boxShadow: '0 0 8px #e8f0f0' });
        bomb.animate([{ transform: 'scale(2.2)', opacity: 0 }, { transform: 'scale(.5)', opacity: 1 }],
            { duration: tok.d(220), easing: 'ease-in', fill: 'forwards' }).onfinish = () => {
            bomb.remove();
            if (!tok.alive) return;
            const wave = resolveCell(tok, b, c, outcome);
            if (wave) waves.push(wave);
        };
        await tok.wait(90);
    }
    await tok.wait(220 + 280);
    await Promise.all(waves);

    // 4. błysk planszy, celownik gaśnie
    const flash = mk(b.layer, { inset: px(-8 * k), borderRadius: '14px', background: '#e8843c' });
    animateOnce(flash, [{ opacity: 0 }, { opacity: .12 }, { opacity: 0 }], { duration: tok.d(380) });
    fade(tok, area, 500);
    await finishSunk(tok, b, outcome);
}

// --- 04 · Sonar ---

/** Obrys krzyża sonaru (ramiona przycięte do planszy) jako ścieżka SVG. */
function crossPath(b: FxBoard, center: Cell, cells: SonarResult[], pad: number): string {
    const [cx, cy] = center;
    const row = cells.filter(c => c.y === cy).map(c => c.x);
    const col = cells.filter(c => c.x === cx).map(c => c.y);
    const L = b.rect(Math.min(cx, ...row), cy).left - pad;
    const R = b.rect(Math.max(cx, ...row), cy).left + b.size + pad;
    const T = b.rect(cx, Math.min(cy, ...col)).top - pad;
    const B = b.rect(cx, Math.max(cy, ...col)).top + b.size + pad;
    const c = b.rect(cx, cy);
    const l = c.left - pad;
    const r = c.left + b.size + pad;
    const t = c.top - pad;
    const bt = c.top + b.size + pad;
    return `M${l},${T} H${r} V${t} H${R} V${bt} H${r} V${B} H${l} V${bt} H${L} V${t} H${l} Z`;
}

/**
 * Sonar: krzyż o promieniu z reguł broni (3, po technologii 4). Pierścienie
 * od środka, pola odsłaniane po ramionach według odległości od środka.
 * Nie zdradza typu jednostki i nie liczy się jako trafienie.
 */
export async function playSonar(tok: FxToken, b: FxBoard, center: Cell, radius: number, cells: SonarResult[]): Promise<void> {
    const mode = fxMode();
    if (mode === 'off' || cells.length === 0) return;
    if (mode === 'reduced') {
        cells.forEach(c => sonarTile(tok, b, c.x, c.y, c.occupied, false));
        await tok.wait(REDUCED_MS);
        return;
    }
    const k = b.k;

    // 1. obszar krzyża
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('width', String(b.width));
    svg.setAttribute('height', String(b.height));
    Object.assign(svg.style, { position: 'absolute', left: '0', top: '0', overflow: 'visible', pointerEvents: 'none' });
    const path = document.createElementNS(ns, 'path');
    path.setAttribute('d', crossPath(b, center, cells, 4 * k));
    Object.entries({ fill: '#3fb6c40d', stroke: '#3fb6c4', 'stroke-width': '2', 'stroke-dasharray': '6 4', 'stroke-linejoin': 'round' })
        .forEach(([key, val]) => path.setAttribute(key, val));
    svg.appendChild(path);
    b.layer.appendChild(svg);
    svg.animate([{ opacity: 0 }, { opacity: 1 }], { duration: tok.d(300), fill: 'forwards' });
    await tok.wait(450);

    // 2. trzy pierścienie, zasięg do końca ramienia
    const r0 = b.rect(center[0], center[1]);
    const reachScale = 2 * (radius + 0.6) * b.pitch / b.size;
    for (let i = 0; i < 3; i++) {
        const ring = mk(b.layer, {
            left: px(r0.left), top: px(r0.top), width: px(b.size), height: px(b.size),
            borderRadius: '50%', border: '2px solid #3fb6c4', boxSizing: 'border-box', opacity: '0',
        });
        animateOnce(ring, [{ transform: 'scale(.2)', opacity: .9 }, { transform: `scale(${reachScale})`, opacity: 0 }],
            { duration: tok.d(1200 + radius * 100), easing: 'cubic-bezier(.2,.6,.3,1)', delay: tok.d(i * 300), fill: 'both' });
    }
    await tok.wait(200);

    // 3. odsłanianie według odległości od środka, 110 ms na krok
    const dist = (c: SonarResult) => Math.abs(c.x - center[0]) + Math.abs(c.y - center[1]);
    const maxDist = Math.max(...cells.map(dist));
    for (let d = 0; d <= maxDist; d++) {
        cells.filter(c => dist(c) === d).forEach(c => sonarTile(tok, b, c.x, c.y, c.occupied, true));
        await tok.wait(110);
    }
    await tok.wait(900);
    fade(tok, svg, 500);
}
