// Stan po stronie klienta między ekranami bitwy: ustawienia bitwy treningowej
// i raport ostatniej bitwy dla ekranu wyniku. Rozliczenie wyprawy po stronie
// backendu jest idempotentne, ale ponowne wywołanie zwraca zera — dlatego
// wynik z pierwszego rozliczenia zapisujemy tutaj.

import type { RulesetName } from '../api/gameApi';
import type { RankName, ResourcesDTO, SettleResultDTO, ShipTypeName } from '../api/expeditionApi';

const PREFS_KEY = 'training.prefs';
const REPORT_KEY = 'battle.lastReport';

export const TRAINING_SIZES = [10, 12, 14] as const;

export interface TrainingPrefs {
    ruleset: RulesetName;
    size: number;
    // oblężenie fortu (#119): plansza 10×10 i zasady fun narzuca fort
    siege: boolean;
}

const DEFAULT_PREFS: TrainingPrefs = { ruleset: 'fun', size: 12, siege: false };

export function getTrainingPrefs(): TrainingPrefs {
    try {
        const parsed = JSON.parse(localStorage.getItem(PREFS_KEY) ?? 'null');
        if (parsed && (parsed.ruleset === 'classic' || parsed.ruleset === 'fun') && typeof parsed.size === 'number') {
            return { ruleset: parsed.ruleset, size: parsed.size, siege: parsed.siege === true };
        }
    } catch {
        // uszkodzony wpis — wracamy do domyślnych
    }
    return { ...DEFAULT_PREFS };
}

export function setTrainingPrefs(prefs: TrainingPrefs): void {
    try { localStorage.setItem(PREFS_KEY, JSON.stringify(prefs)); } catch { /* tryb prywatny */ }
}

export interface ExpeditionOutcome {
    profileId: string;
    // bitwa ze strażnikiem (#107): islandId = id bramy, islandName = nazwa strażnika
    islandId: string;
    islandName: string;
    kind?: 'guardian';
    /** akwen, na którym stoi flota po bitwie */
    seaName?: string;
    settle: SettleResultDTO;
    xpBefore: number;
    rankBefore: RankName;
    /** próg XP następnej rangi po rozliczeniu (null = najwyższa ranga) */
    nextRank: { rank: RankName; xpTarget: number } | null;
    /** stan floty po rozliczeniu */
    fleet: { id: string; type: ShipTypeName; damaged: boolean; repairCost: ResourcesDTO }[];
}

export interface BattleReport {
    gameId: string;
    outcome: 'won' | 'lost';
    ruleset: RulesetName;
    board: { w: number; h: number };
    shots: number;
    hits: number;
    enemySunk: number[];
    enemyTotal: number;
    playerSunk: number[];
    // bitwa oblężnicza (#119): zniszczone działa zamiast zatopień
    fort?: { destroyed: number; total: number } | null;
    /** null = bitwa treningowa (bez stawki) */
    expedition: ExpeditionOutcome | null;
}

export function saveBattleReport(report: BattleReport): void {
    try { localStorage.setItem(REPORT_KEY, JSON.stringify(report)); } catch { /* tryb prywatny */ }
}

export function getBattleReport(gameId: string): BattleReport | null {
    try {
        const parsed = JSON.parse(localStorage.getItem(REPORT_KEY) ?? 'null');
        return parsed && parsed.gameId === gameId ? parsed as BattleReport : null;
    } catch {
        return null;
    }
}
