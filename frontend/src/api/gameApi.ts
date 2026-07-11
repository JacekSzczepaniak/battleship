import { http } from './http';

export type CellState = 'empty' | 'ship' | 'hit' | 'miss';

export interface EnemyFogGridView {
    hits: [number, number][];
    misses: [number, number][];
    sunk: { cells: [number, number][] }[];
}

export interface PlayerFleetItem { x: number; y: number; o: 'h' | 'v'; l: number }

export type RulesetName = 'classic' | 'fun';

export interface WeaponState { used: number; limit: number }
export interface WeaponsState {
    torpedo: WeaponState;
    sonar: WeaponState;
    airRaid: WeaponState;
    // ile z torped może płynąć po przekątnej (podzbiór limitu torpedo)
    torpedoDiagonal?: WeaponState;
}

export interface GameViewDTO {
    id: string;
    status: 'pending' | 'in_progress' | 'won' | 'lost';
    board: { w: number; h: number };
    ruleset: RulesetName;
    weapons: WeaponsState;
    opponentWeapons?: WeaponsState;
    mode: 'standard' | 'nonstandard' | string;
    opponent: 'mock' | 'ai' | 'pvp' | string;
    turn: 'player' | 'opponent' | 'none';
    playerFleet: PlayerFleetItem[];
    enemyFogGrid: EnemyFogGridView;
    // overlay trafień/pudeł przeciwnika na planszy gracza
    playerUnderFireGrid?: {
        hits: [number, number][];
        misses: [number, number][];
    };
    shotsCount: number;
    finished: boolean;
}

export interface CreateGameResponse {
    id: string;
    status: string;
    ruleset: RulesetName;
    board: { w: number; h: number };
}

export interface ShotResultDTO extends TurnOutcomeDTO {
    result: 'miss' | 'hit' | 'sunk' | 'duplicate';
}

export async function createGame(mode: RulesetName = 'classic', size = 10): Promise<CreateGameResponse> {
    return http.post<CreateGameResponse>('/games', { mode, width: size, height: size });
}

export async function getGame(id: string): Promise<GameViewDTO> {
    return http.get<GameViewDTO>(`/games/${id}`);
}

export interface PlaceFleetPayload { ships: PlayerFleetItem[] }
export async function placeFleet(id: string, ships: PlayerFleetItem[]): Promise<{ ok: true }> {
    return http.post<{ ok: true }>(`/games/${id}/fleet`, { ships } satisfies PlaceFleetPayload);
}

export async function fireShot(id: string, x: number, y: number): Promise<ShotResultDTO> {
    return http.post<ShotResultDTO>(`/games/${id}/shots`, { x, y });
}

// --- bronie specjalne (tryb fun) ---

export type TorpedoDirection = 'N' | 'NE' | 'E' | 'SE' | 'S' | 'SW' | 'W' | 'NW';

export interface CellShotResult { x: number; y: number; result: 'hit' | 'miss' | 'sunk' | 'duplicate' }

export interface TurnOutcomeDTO {
    finished: boolean;
    win: boolean;
    loss: boolean;
    turn: 'player' | 'opponent' | 'none';
    opponentMoves: CellShotResult[];
    // torpeda AI zdradza wyrzutnię — pozycja statku przeciwnika
    opponentTorpedoLaunch?: { x: number; y: number } | null;
}

export interface WeaponShotsResponse extends TurnOutcomeDTO {
    results: CellShotResult[];
}

export interface SonarCell { x: number; y: number; occupied: boolean }
export interface SonarResponse {
    results: SonarCell[];
    shape: string;
    radius: number;
}

export async function fireTorpedo(id: string, x: number, y: number, direction: TorpedoDirection): Promise<WeaponShotsResponse> {
    return http.post<WeaponShotsResponse>(`/games/${id}/torpedo`, { x, y, direction });
}

export async function sonarPing(id: string, x: number, y: number): Promise<SonarResponse> {
    return http.post<SonarResponse>(`/games/${id}/sonar`, { x, y });
}

export async function sendAirRaid(id: string, x: number, y: number): Promise<WeaponShotsResponse> {
    // pół-zasięgi 1×1 → obszar 3×3 (maksimum dozwolone przez FunRuleset)
    return http.post<WeaponShotsResponse>(`/games/${id}/air-raid`, { x, y, width: 1, height: 1 });
}
