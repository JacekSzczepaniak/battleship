import { http } from './http';
import type { CreateGameResponse } from './gameApi';

export type RankName = 'rozbitek' | 'marynarz' | 'kapitan' | 'admiral';

export const RANK_LABELS: Record<RankName, string> = {
    rozbitek: 'Rozbitek',
    marynarz: 'Marynarz',
    kapitan: 'Kapitan',
    admiral: 'Admirał',
};

export interface ProfileDTO {
    id: string;
    name: string;
    xp: number;
    rank: RankName;
}

export interface ExpeditionProfileDTO extends ProfileDTO {
    nextRank: { rank: RankName; xpNeeded: number } | null;
}

export interface IslandDTO {
    id: string;
    name: string;
    description: string;
    mode: 'classic' | 'fun';
    requiredRank: RankName;
    xpWin: number;
    xpLoss: number;
    unlocked: boolean;
    wins: number;
    losses: number;
}

export interface ExpeditionDTO {
    profile: ExpeditionProfileDTO;
    islands: IslandDTO[];
}

export interface SettleResultDTO {
    result: 'won' | 'lost';
    awarded: number;
    xp: number;
    rank: RankName;
    rankUp: boolean;
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
