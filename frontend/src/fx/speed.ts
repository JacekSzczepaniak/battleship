// Ustawienie prędkości animacji (#145): 1× / 1,5× / 2× / wył., zapamiętane
// w przeglądarce. Wspólne dla bitwy i rejsu (#146). Wszystkie czasy efektów
// dzielimy przez mnożnik; „wył.” i prefers-reduced-motion pokazują wynik od razu.

import { ref } from 'vue';

export type FxSpeed = 0 | 1 | 1.5 | 2;

export const FX_SPEEDS: { value: FxSpeed; label: string }[] = [
    { value: 1, label: '1×' },
    { value: 1.5, label: '1,5×' },
    { value: 2, label: '2×' },
    { value: 0, label: 'wył.' },
];

const SPEED_KEY = 'fx.speed';

function readSpeed(): FxSpeed {
    try {
        const raw = localStorage.getItem(SPEED_KEY);
        const found = FX_SPEEDS.find(s => raw !== null && String(s.value) === raw);
        if (found) return found.value;
    } catch {
        // brak dostępu do localStorage (tryb prywatny, zablokowane dane witryny)
    }
    return 1;
}

/** Bieżąca prędkość; reaktywna, żeby przełącznik w UI widział zmianę od razu. */
export const fxSpeed = ref<FxSpeed>(readSpeed());

export function setFxSpeed(speed: FxSpeed): void {
    fxSpeed.value = speed;
    try { localStorage.setItem(SPEED_KEY, String(speed)); } catch { /* tryb prywatny */ }
}

export function prefersReducedMotion(): boolean {
    try {
        return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
    } catch {
        return false;
    }
}

/**
 * Jak odtwarzać efekty:
 * - off — bez animacji, wynik od razu (ustawienie „wył.”),
 * - reduced — bez przelotów, sam wynik z krótkim fade 150 ms (prefers-reduced-motion),
 * - full — pełna sekwencja z projektu.
 */
export type FxMode = 'off' | 'reduced' | 'full';

export function fxMode(): FxMode {
    if (fxSpeed.value === 0) return 'off';
    return prefersReducedMotion() ? 'reduced' : 'full';
}
