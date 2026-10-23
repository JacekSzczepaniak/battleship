import { http } from './http';

export type CellState = 'empty' | 'ship' | 'hit' | 'miss';

export interface EnemyFogGridView {
    hits: [number, number][];
    misses: [number, number][];
    sunk: { cells: [number, number][] }[];
}

// wyposażenie statku (Loadout::toArray): broń => liczba użyć
export type Loadout = Partial<Record<'torpedo' | 'sonar' | 'airRaid', number>>;

// `w` tylko przy wyposażeniu innym niż domyślne dla długości (wariant statku, #99)
export interface PlayerFleetItem { x: number; y: number; o: 'h' | 'v'; l: number; w?: Loadout }

export type RulesetName = 'classic' | 'fun';

export interface WeaponState { used: number; limit: number }
// promień krzyża sonaru: 3, gracz 4 po zbadaniu Sonaru dalekiego zasięgu
export interface SonarState extends WeaponState { radius: number }
export interface WeaponsState {
    torpedo: WeaponState;
    sonar: SonarState;
    airRaid: WeaponState;
    // ile z torped może płynąć po przekątnej (podzbiór limitu torpedo)
    torpedoDiagonal?: WeaponState;
}

// działania w turze i ich koszt energii (#59; ruch statku z #60, abordaż z #118)
export type BattleActionName = 'shot' | 'sonar' | 'torpedo' | 'airRaid' | 'move' | 'board';

// modyfikator progu k6 abordażu (#118); value < 0 = próg niższy
export interface BoardingModifier { code: 'half-hull' | string; value: number }

export interface BoardingAttemptDTO {
    round: number;
    roll: number;
    threshold: number;
    modifiers: BoardingModifier[];
    // indeks statku we flocie wroga
    ship: number;
    success: boolean;
}

// stan abordażu (#118); null w GameViewDTO = bitwa bez abordażu (trening, classic)
export interface BoardingState {
    available: boolean;
    blocker: 'not-available' | 'attempted' | 'not-last-ship' | 'energy' | null;
    // próg k6 dla ostatniego statku wroga (przy kilku statkach — bazowy)
    threshold: number;
    modifiers: BoardingModifier[];
    cost: number;
    attempt: BoardingAttemptDTO | null;
}

// energia gracza w bieżącej turze; null = bitwa bez energii (classic)
export interface EnergyState {
    left: number;
    max: number;
    costs: Partial<Record<BattleActionName, number>> & Record<string, number>;
}

// fort w bitwie oblężniczej (#119): rodzaj konstrukcji tylko na trafionych polach
export type FortPieceKind = 'gun' | 'wall' | 'magazine';
export interface FortView {
    seed: number;
    guns: { left: number; total: number };
    revealed: { x: number; y: number; kind: FortPieceKind }[];
    // pola eksplozji magazynu prochu; null = magazyn jeszcze stoi
    explosion: [number, number][] | null;
}

export interface GameViewDTO {
    id: string;
    status: 'pending' | 'in_progress' | 'won' | 'lost';
    board: { w: number; h: number };
    ruleset: RulesetName;
    // skład floty do wystawienia: mapa długość => liczba sztuk (klucze JSON jako stringi)
    allowedShips?: Record<string, number>;
    // skład floty przeciwnika; inny niż allowedShips przy bonusie pełnej bandery (#79)
    opponentShips?: Record<string, number>;
    // warianty: długość => wyposażenia odbiegające od domyślnego (pierwsze statki tej długości); pusta tablica = brak
    variants?: Record<string, Loadout[]> | [];
    opponentVariants?: Record<string, Loadout[]> | [];
    weapons: WeaponsState;
    opponentWeapons?: WeaponsState;
    energy?: EnergyState | null;
    // ruch statku wykorzystany w tej turze (#60)
    moveUsed?: boolean;
    // abordaż ostatniego statku wroga (#118)
    boarding?: BoardingState | null;
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
    // bitwa oblężnicza (#119); null = zwykła bitwa z flotą
    fort?: FortView | null;
}

export interface CreateGameResponse {
    id: string;
    status: string;
    ruleset: RulesetName;
    board: { w: number; h: number };
    fortSeed?: number | null;
}

export interface ShotResultDTO extends TurnOutcomeDTO {
    result: 'miss' | 'hit' | 'sunk' | 'duplicate';
}

// siege = bitwa oblężnicza (#119): fort 10×10 z zasadami fun narzuca backend
export async function createGame(mode: RulesetName = 'classic', size = 10, siege = false): Promise<CreateGameResponse> {
    return http.post<CreateGameResponse>('/games', siege ? { mode: 'fun', siege: true } : { mode, width: size, height: size });
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
    // energia gracza na nową turę (#59)
    energy?: EnergyState | null;
}

export interface WeaponShotsResponse extends TurnOutcomeDTO {
    results: CellShotResult[];
}

export interface SonarCell { x: number; y: number; occupied: boolean }
export interface SonarResponse {
    results: SonarCell[];
    shape: string;
    radius: number;
    energy?: EnergyState | null;
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

// „Zakończ turę” (#59): tura przechodzi do AI bez ataku; tylko w trybie z energią
export async function endTurn(id: string): Promise<TurnOutcomeDTO> {
    return http.post<TurnOutcomeDTO>(`/games/${id}/end-turn`, {});
}

// --- ruch statku (#60): 1⚡, raz na turę, nie kończy tury ---

export type MoveDirection = 'N' | 'E' | 'S' | 'W';

export interface MoveShipResponse {
    ship: { x: number; y: number; o: 'H' | 'V'; l: number };
    moveUsed: boolean;
    energy: EnergyState | null;
}

// (x, y) = dowolne pole własnego statku
export async function moveShip(id: string, x: number, y: number, direction: MoveDirection): Promise<MoveShipResponse> {
    return http.post<MoveShipResponse>(`/games/${id}/move`, { x, y, direction });
}

// --- abordaż (#118): ⚡3, jedna próba na bitwę, kończy turę ---

export interface BoardShipResponse extends TurnOutcomeDTO {
    boarding: BoardingAttemptDTO;
}

export async function boardShip(id: string): Promise<BoardShipResponse> {
    return http.post<BoardShipResponse>(`/games/${id}/board`, {});
}
