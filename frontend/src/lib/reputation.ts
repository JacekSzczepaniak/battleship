// Reputacja kupiec ↔ korsarz (#142): etykiety, skutki i zapowiedź progów (zasada 4).
import type { ReputationDTO, ReputationTierName } from '../api/expeditionApi';

export const TIER_LABELS: Record<ReputationTierName, string> = {
    merchant: 'kupiec',
    neutral: 'neutralny',
    corsair: 'korsarz',
    embargo: 'embargo',
};

/** Wartość ze znakiem: +5, 0, −20. */
export function signed(n: number): string {
    return n > 0 ? `+${n}` : n < 0 ? `−${-n}` : '0';
}

/** Poziom dla wartości: progi włącznie, jak Reputation::tier(). */
export function tierOf(value: number, rep: ReputationDTO): ReputationTierName {
    if (value >= rep.thresholds.merchant) return 'merchant';
    if (value <= rep.thresholds.embargo) return 'embargo';
    if (value <= rep.thresholds.corsair) return 'corsair';
    return 'neutral';
}

/** Skutek poziomu w jednym zdaniu. */
export function tierEffect(tier: ReputationTierName, rep: ReputationDTO): string {
    switch (tier) {
        case 'merchant': return `ceny na rynku ${signed(rep.prices.merchant - 100)}%`;
        case 'neutral': return 'ceny bazowe';
        case 'corsair': return `ceny na rynku ${signed(rep.prices.corsair - 100)}%`;
        case 'embargo': return 'port odmawia handlu, zamiast handlarzy przychodzą łowcy nagród';
    }
}

/** Najbliższe progi w obie strony, opisane z góry: co się stanie, zanim gracz je przekroczy. */
export function nextThresholds(rep: ReputationDTO): string[] {
    const t = rep.thresholds;
    const out: string[] = [];
    if (rep.value < t.merchant) out.push(`od ${signed(t.merchant)}: kupiec, ${tierEffect('merchant', rep)}`);
    if (rep.value > t.corsair) out.push(`od ${signed(t.corsair)}: korsarz, ${tierEffect('corsair', rep)}`);
    if (rep.value > t.embargo) out.push(`od ${signed(t.embargo)}: embargo — ${tierEffect('embargo', rep)}`);
    return out;
}

/** Skutek zmiany o delta: „reputacja −20 (+10 → −10)” i ostrzeżenie, gdy zmienia się poziom. */
export function changePreview(rep: ReputationDTO, delta: number): { text: string; crossing: string | null } {
    const after = Math.max(rep.min, Math.min(rep.max, rep.value + delta));
    const tier = tierOf(after, rep);
    return {
        text: `reputacja ${signed(delta)} (${signed(rep.value)} → ${signed(after)})`,
        crossing: tier !== rep.tier ? `${TIER_LABELS[tier]} — ${tierEffect(tier, rep)}` : null,
    };
}

/** Pozycja na pasku osi w procentach (0 = min, 100 = max). */
export function axisPercent(value: number, rep: ReputationDTO): number {
    return ((value - rep.min) / (rep.max - rep.min)) * 100;
}
