import { http } from './http';

export type CellState = 'empty' | 'ship' | 'hit' | 'miss'; // frontendowe

export interface GameDTO {
    id: string;
    size: number; // 10
    playerGrid: CellState[][];      // pełna plansza gracza
    enemyFogGrid: CellState[][];    // plansza przeciwnika z mgłą (bez statków)
    turn: 'player' | 'enemy';
    status: 'ongoing' | 'won' | 'lost';
}

export interface ShotResultDTO {
    hit: boolean;
    sunk?: { size: number; cells: { x: number; y: number }[] };
    finished?: boolean;
    turn: 'player' | 'enemy';
    // opcjonalnie zaktualizowana mgła w odpowiedzi:
    enemyFogGrid?: CellState[][];
}

export async function createGame(): Promise<GameDTO> {
    return http.post<GameDTO>('/games', { mode: 'classic' });
}

export async function getGame(id: string): Promise<GameDTO> {
    return http.get<GameDTO>(`/games/${id}`);
}

export async function fireShot(id: string, x: number, y: number): Promise<ShotResultDTO> {
    return http.post<ShotResultDTO>(`/games/${id}/shots`, { x, y });
}
