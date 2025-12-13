import { http } from './http';

export type CellState = 'empty' | 'ship' | 'hit' | 'miss';

export interface EnemyFogGridView {
    hits: [number, number][];
    misses: [number, number][];
    sunk: { cells: [number, number][] }[];
}

export interface PlayerFleetItem { x: number; y: number; o: 'h' | 'v'; l: number }

export interface GameViewDTO {
    id: string;
    status: 'pending' | 'in_progress' | 'won' | 'lost';
    board: { w: number; h: number };
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
    board: { w: number; h: number };
}

export interface ShotResultDTO {
    result: 'miss' | 'hit' | 'sunk' | 'duplicate';
    finished: boolean;
    win: boolean;
    loss: boolean;
    turn: 'player' | 'opponent' | 'none';
    opponentMoves: { x: number; y: number; result: 'hit' | 'miss' | 'sunk' | 'duplicate' }[];
}

export async function createGame(): Promise<CreateGameResponse> {
    // backend przyjmuje puste body (opcjonalne width/height)
    return http.post<CreateGameResponse>('/games', {});
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
