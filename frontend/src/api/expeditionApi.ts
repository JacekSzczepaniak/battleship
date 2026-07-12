import { http } from './http';
import type { CreateGameResponse } from './gameApi';

export type RankName = 'rozbitek' | 'marynarz' | 'kapitan' | 'admiral';

export const RANK_LABELS: Record<RankName, string> = {
    rozbitek: 'Rozbitek',
    marynarz: 'Marynarz',
    kapitan: 'Kapitan',
    admiral: 'Admirał',
};

export type ShipTypeName = 'tratwa' | 'kuter' | 'niszczyciel' | 'lotniskowiec';

export const SHIP_LABELS: Record<ShipTypeName, string> = {
    tratwa: 'Tratwa',
    kuter: 'Kuter',
    niszczyciel: 'Niszczyciel',
    lotniskowiec: 'Lotniskowiec',
};

export const SHIP_ICONS: Record<ShipTypeName, string> = {
    tratwa: '🛶',
    kuter: '🚤',
    niszczyciel: '🚢',
    lotniskowiec: '🛳️',
};

export interface ProfileDTO {
    id: string;
    name: string;
    xp: number;
    rank: RankName;
}

export interface ExpeditionProfileDTO extends ProfileDTO {
    nextRank: { rank: RankName; xpNeeded: number } | null;
    materials: number;
}

export interface FleetShipDTO {
    id: string;
    type: ShipTypeName;
    length: number;
    damaged: boolean;
    repairCost: number;
}

export interface ShipTypeDTO {
    type: ShipTypeName;
    length: number;
    buildCost: number;
    repairCost: number;
    requiredRank: RankName;
    requiredShipyardLevel: number;
}

export interface IslandDTO {
    id: string;
    name: string;
    description: string;
    mode: 'classic' | 'fun';
    requiredRank: RankName;
    xpWin: number;
    xpLoss: number;
    materialsWin: number;
    materialsLoss: number;
    shipyardLevel: number;
    board: { w: number; h: number };
    unlocked: boolean;
    wins: number;
    losses: number;
    // wolne morze
    discovered: boolean;
    position: { x: number; y: number } | null;
    present: boolean;
}

export interface WorldDTO {
    width: number;
    height: number;
    position: { x: number; y: number };
    discovered: string[]; // sektory "x:y"
    atIsland: string | null;
    stormChance: number;
}

export interface SailResultDTO {
    position: { x: number; y: number };
    discoveredNow: string[];
    cartography: number;
    event: { type: string; effect: string; ship?: ShipTypeName; materials?: number } | null;
    materials: number;
    atIsland: string | null;
}

export interface ExpeditionDTO {
    profile: ExpeditionProfileDTO;
    fleet: FleetShipDTO[];
    shipTypes: ShipTypeDTO[];
    islands: IslandDTO[];
    world: WorldDTO;
}

export interface SettleResultDTO {
    result: 'won' | 'lost';
    awarded: number;
    xp: number;
    rank: RankName;
    rankUp: boolean;
    materialsAwarded: number;
    materials: number;
    lostShips: ShipTypeName[];
    damagedShips: ShipTypeName[];
}

export async function createProfile(name: string): Promise<ProfileDTO> {
    return http.post<ProfileDTO>('/profiles', { name });
}

export async function getExpedition(profileId: string): Promise<ExpeditionDTO> {
    return http.get<ExpeditionDTO>(`/profiles/${profileId}/expedition`);
}

export async function startIslandBattle(profileId: string, islandId: string): Promise<CreateGameResponse> {
    return http.post<CreateGameResponse>(`/profiles/${profileId}/islands/${islandId}/battle`, {});
}

export async function settleBattle(profileId: string, gameId: string): Promise<SettleResultDTO> {
    return http.post<SettleResultDTO>(`/profiles/${profileId}/battles/${gameId}/settle`, {});
}

export async function buildShip(profileId: string, islandId: string, type: ShipTypeName): Promise<FleetShipDTO> {
    return http.post<FleetShipDTO>(`/profiles/${profileId}/ships`, { type, islandId });
}

export async function repairShip(profileId: string, islandId: string, shipId: string): Promise<FleetShipDTO> {
    return http.post<FleetShipDTO>(`/profiles/${profileId}/ships/${shipId}/repair`, { islandId });
}

export async function sail(profileId: string, x: number, y: number): Promise<SailResultDTO> {
    return http.post<SailResultDTO>(`/profiles/${profileId}/sail`, { x, y });
}
