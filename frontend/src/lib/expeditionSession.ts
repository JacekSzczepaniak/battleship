// Sesja wyprawy w localStorage: identyfikator profilu kapitana oraz
// trwająca bitwa (żeby Game.vue wiedział, że po zakończeniu ma rozliczyć XP).

const PROFILE_KEY = 'expedition.profileId';
const BATTLE_KEY = 'expedition.currentBattle';

export interface CurrentBattle {
    profileId: string;
    gameId: string;
    // bitwa ze strażnikiem (#107): islandId = id bramy, islandName = nazwa strażnika
    islandId: string;
    islandName: string;
    // oblężenie fortu twierdzy (#120); brak = bitwa o wyspę albo obrona
    // bitwa spotkania na morzu (#141): islandId = id akwenu, islandName = nazwa floty wroga
    kind?: 'guardian' | 'siege' | 'encounter';
    // zestaw floty, z którego wybrano skład (#125) — rozstawianie ustawi go samo
    presetId?: string;
}

export function getProfileId(): string | null {
    return localStorage.getItem(PROFILE_KEY);
}

export function setProfileId(id: string): void {
    localStorage.setItem(PROFILE_KEY, id);
}

export function clearProfileId(): void {
    localStorage.removeItem(PROFILE_KEY);
}

export function getCurrentBattle(): CurrentBattle | null {
    const raw = localStorage.getItem(BATTLE_KEY);
    if (!raw) return null;
    try {
        const parsed = JSON.parse(raw);
        if (parsed && typeof parsed.gameId === 'string' && typeof parsed.profileId === 'string') {
            return parsed as CurrentBattle;
        }
    } catch {
        // uszkodzony wpis — sprzątamy
    }
    localStorage.removeItem(BATTLE_KEY);
    return null;
}

export function setCurrentBattle(battle: CurrentBattle): void {
    localStorage.setItem(BATTLE_KEY, JSON.stringify(battle));
}

export function clearCurrentBattle(): void {
    localStorage.removeItem(BATTLE_KEY);
}
