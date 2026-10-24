// Rdzeń efektów (#145): token anulowania sekwencji, odliczanie czasu
// w skali prędkości i pomocnicy DOM. Tylko Web Animations API, animowane
// transform i opacity (wyjątki: width kilwateru, strokeDashoffset trasy).

import { fxSpeed } from './speed';

/** Rzucany z `wait`, gdy sekwencję przerwano (ponowne kliknięcie, wyjście z ekranu). */
export class FxCancelled extends Error {
    constructor() {
        super('fx cancelled');
    }
}

/**
 * Token jednej sekwencji. Prędkość jest zamrożona na starcie, żeby zmiana
 * ustawienia w trakcie nie rozjechała odliczonych już czasów.
 */
export class FxToken {
    alive = true;
    readonly speed: number;
    private pending = new Map<ReturnType<typeof setTimeout>, () => void>();

    constructor(speed = Math.max(0.25, fxSpeed.value || 1)) {
        this.speed = speed;
    }

    /** Czas z projektu (ms przy 1×) przeliczony na bieżącą prędkość. */
    d(ms: number): number {
        return ms / this.speed;
    }

    /** Pauza w sekwencji; po anulowaniu odrzuca od razu, nie po upływie czasu. */
    wait(ms: number): Promise<void> {
        if (!this.alive) return Promise.reject(new FxCancelled());
        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => {
                this.pending.delete(timer);
                resolve();
            }, Math.max(0, this.d(ms)));
            this.pending.set(timer, () => reject(new FxCancelled()));
        });
    }

    cancel(): void {
        if (!this.alive) return;
        this.alive = false;
        for (const [timer, reject] of this.pending) {
            clearTimeout(timer);
            reject();
        }
        this.pending.clear();
    }
}

/**
 * Sekwencje na jednym ekranie: nowa sekwencja przerywa poprzednią,
 * `cancel` przy odmontowaniu albo na żądanie gracza.
 */
export class FxSequencer {
    private current: FxToken | null = null;

    begin(): FxToken {
        this.cancel();
        this.current = new FxToken();
        return this.current;
    }

    end(token: FxToken): void {
        if (this.current === token) this.current = null;
    }

    get running(): boolean {
        return this.current !== null && this.current.alive;
    }

    cancel(): void {
        this.current?.cancel();
        this.current = null;
    }
}

export function isCancelled(e: unknown): boolean {
    return e instanceof FxCancelled;
}

/** Element efektu w warstwie overlay: absolutny, bez zdarzeń myszy. */
export function mk(parent: HTMLElement, style: Partial<CSSStyleDeclaration>): HTMLDivElement {
    const el = document.createElement('div');
    Object.assign(el.style, { position: 'absolute', pointerEvents: 'none' }, style);
    parent.appendChild(el);
    return el;
}

/** Animacja, która po skończeniu usuwa element (efekty przejściowe). */
export function animateOnce(el: Element, keyframes: Keyframe[], options: KeyframeAnimationOptions): Animation {
    const a = el.animate(keyframes, { fill: 'forwards', ...options });
    a.onfinish = () => el.remove();
    return a;
}

export function fade(tok: FxToken, el: Element, ms: number, from = 1, to = 0, remove = true): Animation {
    const a = el.animate([{ opacity: from }, { opacity: to }], { duration: tok.d(ms), fill: 'forwards' });
    if (remove) a.onfinish = () => el.remove();
    return a;
}

export const px = (n: number) => `${n}px`;
