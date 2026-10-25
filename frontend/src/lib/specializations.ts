// Specjalizacje kapitana (#160, Epic K): etykiety ścieżek, źródeł punktów i perków.
// Liczby (progi, punkty za akcję) przychodzą z backendu; tu tylko słowa.
import type {
    PerkName, SpecializationSourceName, SpecializationTrackDTO, SpecializationTrackName,
} from '../api/expeditionApi';

export const TRACK_LABELS: Record<SpecializationTrackName, string> = {
    combat: 'bojowa',
    economy: 'ekonomiczna',
};

export const TRACK_ICONS: Record<SpecializationTrackName, string> = {
    combat: '⚔️',
    economy: '⚖️',
};

// źródło punktów: nazwa jednostki akcji w liczbie mnogiej (licznik w panelu)
export const SOURCE_LABELS: Record<SpecializationSourceName, string> = {
    win: 'wygrane bitwy',
    'enemy-sunk': 'zatopieni wrogowie',
    boarding: 'udane abordaże',
    defense: 'wygrane obrony',
    siege: 'wygrane oblężenia',
    'market-turnover': 'złoto obrotu na rynku',
    transport: 'ładunek przewieziony między wyspami',
    mining: 'urobek złóż',
    discovery: 'odkryte sektory',
};

// nazwa perka i jego skutek (efekty wchodzą w #161 i #162)
export const PERK_LABELS: Record<PerkName, string> = {
    'repair-discount': 'Remont po wygranej bitwie −20%',
    'boarding-edge': 'Próg abordażu −1',
    'extra-sonar': '+1 użycie sonaru w bitwie',
    'loot-bonus': 'Łup z bitew +20%',
    'first-turn-energy': '+1⚡ w pierwszej turze bitwy',
    'larger-hold': 'Ładownia +10%',
    'market-discount': 'Ceny rynku −5%',
    'cheaper-shipbuilding': 'Budowa statków −10%',
    'deposit-storage': 'Magazyn złóż +5 tików produkcji',
    'master-trader': 'Ceny rynku −10% łącznie, handlarz bez dopłaty',
};

// jednostka akcji w regule źródła: „+5 za wygraną”, „+1 za 20 złota”
const SOURCE_UNITS: Record<SpecializationSourceName, string> = {
    win: 'wygraną',
    'enemy-sunk': 'zatopionego',
    boarding: 'abordaż',
    defense: 'obronę',
    siege: 'oblężenie',
    'market-turnover': 'złota',
    transport: 'jednostek',
    mining: 'jednostek',
    discovery: 'sektor',
};

/** Reguła źródła: „+5 za wygraną”, „+1 za 20 złota”. */
export function sourceRule(s: SpecializationTrackDTO['sources'][number]): string {
    const unit = SOURCE_UNITS[s.source];
    return s.unitsPerPoint === 1 ? `+${s.pointsPerUnit} za ${unit}` : `+${s.pointsPerUnit} za ${s.unitsPerPoint} ${unit}`;
}

/** Postęp paska w procentach od progu obecnego poziomu do kolejnego; 100 na ostatnim. */
export function levelProgress(t: SpecializationTrackDTO): number {
    if (!t.next) return 100;
    return Math.max(0, Math.min(100, ((t.points - t.levelFrom) / (t.next.points - t.levelFrom)) * 100));
}

export function trackLevelText(track: SpecializationTrackName, level: number): string {
    return `${TRACK_ICONS[track]} ${TRACK_LABELS[track]} — poziom ${level}`;
}

/** Perk ścieżki na poziomie, bez DTO (wpis w dzienniku zna tylko ścieżkę i poziom). */
const PERK_ORDER: Record<SpecializationTrackName, PerkName[]> = {
    combat: ['repair-discount', 'boarding-edge', 'extra-sonar', 'loot-bonus', 'first-turn-energy'],
    economy: ['larger-hold', 'market-discount', 'cheaper-shipbuilding', 'deposit-storage', 'master-trader'],
};

export function perkFor(track: SpecializationTrackName, level: number): PerkName | null {
    return PERK_ORDER[track][level - 1] ?? null;
}

// --- Komunikat o awansie: poziomy widziane ostatnio, osobno dla każdego profilu ---

const SEEN_KEY = 'expedition.specializationLevels';

type SeenLevels = Partial<Record<SpecializationTrackName, number>>;

function readSeen(profileId: string): SeenLevels | null {
    try {
        const all = JSON.parse(localStorage.getItem(SEEN_KEY) ?? '{}') as Record<string, SeenLevels>;
        return all[profileId] ?? null;
    } catch {
        return null;
    }
}

export function markLevelsSeen(profileId: string, tracks: SpecializationTrackDTO[]): void {
    try {
        const all = JSON.parse(localStorage.getItem(SEEN_KEY) ?? '{}') as Record<string, SeenLevels>;
        all[profileId] = Object.fromEntries(tracks.map(t => [t.track, t.level]));
        localStorage.setItem(SEEN_KEY, JSON.stringify(all));
    } catch { /* tryb prywatny: komunikat pokaże się znowu po przeładowaniu */ }
}

/**
 * Awanse od ostatnio widzianych poziomów. Pierwsze wejście profilu (brak zapisu)
 * tylko zapamiętuje stan — bez komunikatu za historię.
 */
export function unseenLevelUps(profileId: string, tracks: SpecializationTrackDTO[]): { track: SpecializationTrackName; from: number; to: number }[] {
    const seen = readSeen(profileId);
    if (!seen) {
        markLevelsSeen(profileId, tracks);
        return [];
    }
    return tracks
        .filter(t => t.level > (seen[t.track] ?? 0))
        .map(t => ({ track: t.track, from: seen[t.track] ?? 0, to: t.level }));
}
