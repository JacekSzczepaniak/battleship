// types.ts
export type CellState = 'empty' | 'ship' | 'hit' | 'miss';

export interface Coordinate {
    x: number;
    y: number;
}

export interface Ship {
    size: number;
    coordinates: Coordinate[];
    hits: number;
}
