import { http } from './http';
import type { CreateGameResponse } from './gameApi';

// kolejność jak w Domain/Expedition/Rank::ordered() — od najniższej
export const RANK_ORDER = [
    'rozbitek', 'majtek', 'marynarz', 'bosman', 'sternik', 'porucznik', 'kapitan', 'komandor', 'admiral',
] as const;

export type RankName = typeof RANK_ORDER[number];

export const RANK_LABELS: Record<RankName, string> = {
    rozbitek: 'Rozbitek',
    majtek: 'Majtek',
    marynarz: 'Marynarz',
    bosman: 'Bosman',
    sternik: 'Sternik',
    porucznik: 'Porucznik',
    kapitan: 'Kapitan',
    komandor: 'Komandor',
    admiral: 'Admirał',
};

// dopełniacz do zdań typu „XP do kapitana”
export const RANK_LABELS_GENITIVE: Record<RankName, string> = {
    rozbitek: 'rozbitka',
    majtek: 'majtka',
    marynarz: 'marynarza',
    bosman: 'bosmana',
    sternik: 'sternika',
    porucznik: 'porucznika',
    kapitan: 'kapitana',
    komandor: 'komandora',
    admiral: 'admirała',
};

export type ShipTypeName = 'tratwa' | 'kuter' | 'niszczyciel' | 'lotniskowiec' | 'transportowiec';

export const SHIP_LABELS: Record<ShipTypeName, string> = {
    tratwa: 'Tratwa',
    kuter: 'Kuter',
    niszczyciel: 'Niszczyciel',
    lotniskowiec: 'Lotniskowiec',
    transportowiec: 'Transportowiec',
};

export const SHIP_ICONS: Record<ShipTypeName, string> = {
    tratwa: '🛶',
    kuter: '🚤',
    niszczyciel: '🚢',
    lotniskowiec: '🛳️',
    transportowiec: '⛴️',
};

export type BuildingTypeName = 'tartak' | 'kopalnia' | 'rozbudowa-stoczni' | 'fort' | 'uczelnia';

export const BUILDING_LABELS: Record<BuildingTypeName, string> = {
    tartak: 'Tartak',
    kopalnia: 'Kopalnia',
    'rozbudowa-stoczni': 'Rozbudowa stoczni',
    fort: 'Fort',
    uczelnia: 'Uczelnia',
};

export const BUILDING_ICONS: Record<BuildingTypeName, string> = {
    tartak: '🪚',
    kopalnia: '⚒️',
    'rozbudowa-stoczni': '🏗️',
    fort: '🏰',
    uczelnia: '🎓',
};

// powód blokady budowy (Domain/Expedition/BuildingBlock); null = można budować
export type BuildingBlockCode = 'not-owned' | 'already-built' | 'site' | 'not-present' | 'resources';

export interface BuildingTypeDTO {
    type: BuildingTypeName;
    buildCost: ResourcesDTO;
}

export interface ResourcesDTO {
    wood: number;
    steel: number;
    gold: number;
}

export interface ProfileDTO {
    id: string;
    name: string;
    xp: number;
    rank: RankName;
}

export interface ExpeditionProfileDTO extends ProfileDTO {
    nextRank: { rank: RankName; xpNeeded: number } | null;
    // surowce istnieją fizycznie: ładownia floty + magazyny wysp (IslandDTO.stockpile)
    cargo: ResourcesDTO;
    cargoCapacity: number;
    // jednostki wroga zatopione we wszystkich rozliczonych bitwach
    enemyShipsSunk: number;
    // wyspy pod banderą kapitana
    ownedIslands: number;
}

export interface FleetShipDTO {
    id: string;
    type: ShipTypeName;
    length: number;
    damaged: boolean;
    repairCost: ResourcesDTO;
}

export interface ShipTypeDTO {
    type: ShipTypeName;
    length: number;
    cargoCapacity: number;
    // nośnik broni — tylko taki statek eskortuje konwój
    armed: boolean;
    buildCost: ResourcesDTO;
    repairCost: ResourcesDTO;
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
    lootWin: ResourcesDTO;
    lootLoss: ResourcesDTO;
    // 0 = brak stoczni — na wyspie zbudujesz tylko tratwę; z rozbudową pod banderą (#82)
    shipyardLevel: number;
    // bandera kapitana: pierwsza wygrana bitwa o wyspę ją zdobywa
    owned: boolean;
    // złoże: urobek na tik trafia do magazynu wyspy (do limitu), dopóki wyspa jest pod banderą;
    // tartak i kopalnia podwajają urobek i limit
    deposit: { yield: ResourcesDTO; cap: ResourcesDTO; active: boolean } | null;
    // osada: stojące budynki (bez bandery nieczynne) i powód blokady budowy każdego typu
    buildings: BuildingTypeName[];
    buildBlockers: Record<BuildingTypeName, BuildingBlockCode | null>;
    board: { w: number; h: number };
    // ile pól planszy może zająć skład bitewny (20% planszy, #73)
    squadLimit: number;
    unlocked: boolean;
    wins: number;
    losses: number;
    // wolne morze
    discovered: boolean;
    position: { x: number; y: number } | null;
    present: boolean;
    stockpile: ResourcesDTO;
}

export interface WorldDTO {
    width: number;
    height: number;
    position: { x: number; y: number };
    discovered: string[]; // sektory "x:y"
    visited: string[]; // sektory "x:y", na których kapitan był — tam sztorm nie grozi
    atIsland: string | null;
    stormChance: number;
    // najdalszy sektor osiągalny w jednym rejsie (większa z różnic x i y); Żegluga dwusektorowa: 2
    sailRange: number;
    // zegar świata: liczba akcji morskich (rejs, bitwa)
    tick: number;
}

export interface SailResultDTO {
    position: { x: number; y: number };
    discoveredNow: string[];
    cartography: number;
    // dochód kartograficzny, który nie zmieścił się w ładowni
    cartographyLost: number;
    // effect: 'cargo-lost' (fale zabrały część ładunku) | 'ship-damaged' (ładunek zostaje); unescorted = konwój bez eskorty, podwójne straty
    event: { type: string; effect: string; ship?: ShipTypeName; cargoLost?: ResourcesDTO; unescorted?: boolean } | null;
    cargo: ResourcesDTO;
    cargoCapacity: number;
    atIsland: string | null;
}

// kontratak (#78): zapowiedź uderza po 12 tikach; obronę przyjmuje flota stojąca na wyspie celu
export interface PendingCounterattackDTO {
    island: string;
    islandName: string;
    announcedAt: number;
    strikeAt: number;
    ticksLeft: number;
    // rozpoczęta obrona wstrzymuje termin do rozliczenia bitwy
    defenseStarted: boolean;
    // pełna bandera (#79): obrońca strzela pierwszy, napastnik bez największego statku
    fullFlag: boolean;
    // czynny fort na wyspie celu (#81): bez obrony wyspa zostaje, magazyn traci połowę
    fort: boolean;
}

export interface LastCounterattackDTO {
    island: string;
    islandName: string;
    tick: number;
    held: boolean;
    // wyspa utracona, bo nikt nie rozpoczął obrony przed uderzeniem
    absent: boolean;
    // nieobecność pod fortem (#81): grabież połowy magazynu, bandera została
    plundered: boolean;
    stockpileLost: ResourcesDTO;
}

// drzewko technologii (Epic G): katalog zawiera tylko działające technologie
export type TechBranchName = 'kadluby' | 'uzbrojenie' | 'nawigacja' | 'osady';
export type TechnologyName = 'spichlerze' | 'mapy-sztormowe' | 'sonar-dalekiego-zasiegu' | 'zegluga-dwusektorowa';

export const TECH_BRANCH_LABELS: Record<TechBranchName, string> = {
    kadluby: 'Kadłuby',
    uzbrojenie: 'Uzbrojenie',
    nawigacja: 'Nawigacja',
    osady: 'Osady',
};

export const TECH_LABELS: Record<TechnologyName, string> = {
    spichlerze: 'Spichlerze',
    'mapy-sztormowe': 'Mapy sztormowe',
    'sonar-dalekiego-zasiegu': 'Sonar dalekiego zasięgu',
    'zegluga-dwusektorowa': 'Żegluga dwusektorowa',
};

// powód blokady badania (Domain/Expedition/ResearchBlock); null = można badać
export type ResearchBlockCode = 'researched' | 'busy' | 'prerequisite' | 'rank' | 'no-academy' | 'not-present' | 'resources';

export interface TechLevelDTO {
    level: number;
    cost: ResourcesDTO;
    ticks: number;
    requiredRank: RankName | null;
}

export interface TechnologyDTO {
    id: TechnologyName;
    branch: TechBranchName;
    level: number;
    state: 'researched' | 'in-progress' | 'available' | 'locked';
    // tylko dla badania w toku
    ticksLeft: number | null;
    // powód blokady liczony dla wyspy, na której stoi kapitan
    blocker: ResearchBlockCode | null;
}

export interface ResearchDTO {
    // badanie w toku; paused = wyspa uczelni bez bandery, tiki nie lecą do odbicia
    current: {
        technology: TechnologyName;
        island: string;
        islandName: string;
        progress: number;
        ticks: number;
        ticksLeft: number;
        paused: boolean;
    } | null;
    branches: TechBranchName[];
    levels: TechLevelDTO[];
    technologies: TechnologyDTO[];
}

export interface ExpeditionDTO {
    profile: ExpeditionProfileDTO;
    fleet: FleetShipDTO[];
    shipTypes: ShipTypeDTO[];
    buildingTypes: BuildingTypeDTO[];
    islands: IslandDTO[];
    world: WorldDTO;
    counterattack: { pending: PendingCounterattackDTO | null; last: LastCounterattackDTO | null };
    research: ResearchDTO;
}

export interface SettleResultDTO {
    result: 'won' | 'lost';
    awarded: number;
    xp: number;
    rank: RankName;
    rankUp: boolean;
    loot: ResourcesDTO;
    // łup leży w magazynie wyspy bitwy; ładunek ponad pojemność po stratach przepadł
    stockpile: ResourcesDTO;
    cargo: ResourcesDTO;
    cargoLost: ResourcesDTO;
    lostShips: ShipTypeName[];
    damagedShips: ShipTypeName[];
    // wygrana postawiła banderę na wyspie
    captured: boolean;
    // zdobycie wyspy ze złożem: urobek na tik; null = złoże nie ruszyło teraz
    depositStarted: ResourcesDTO | null;
    // bitwa obronna (#78): czy bandera została i co przepadło z magazynu; null = bitwa o zdobycie
    defense: { held: boolean; stockpileLost: ResourcesDTO } | null;
}

export async function createProfile(name: string): Promise<ProfileDTO> {
    return http.post<ProfileDTO>('/profiles', { name });
}

export async function getExpedition(profileId: string): Promise<ExpeditionDTO> {
    return http.get<ExpeditionDTO>(`/profiles/${profileId}/expedition`);
}

/** Start bitwy o wyspę; bez `shipIds` backend dobiera skład domyślny w limicie wyspy. */
export async function startIslandBattle(profileId: string, islandId: string, shipIds?: string[]): Promise<CreateGameResponse> {
    return http.post<CreateGameResponse>(`/profiles/${profileId}/islands/${islandId}/battle`, shipIds ? { shipIds } : {});
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

export interface BuildBuildingDTO {
    island: string;
    building: BuildingTypeName;
    buildings: BuildingTypeName[];
    stockpile: ResourcesDTO;
    cargo: ResourcesDTO;
}

/** Budowa natychmiastowa na posiadanej wyspie, na której stoi kapitan; płaci magazyn wyspy, resztę ładownia. */
export async function buildBuilding(profileId: string, islandId: string, type: BuildingTypeName): Promise<BuildBuildingDTO> {
    return http.post<BuildBuildingDTO>(`/profiles/${profileId}/islands/${islandId}/buildings`, { type });
}

export interface StartResearchDTO {
    island: string;
    technology: TechnologyName;
    ticksLeft: number;
    stockpile: ResourcesDTO;
    cargo: ResourcesDTO;
}

export async function startResearch(profileId: string, islandId: string, technology: TechnologyName): Promise<StartResearchDTO> {
    return http.post<StartResearchDTO>(`/profiles/${profileId}/islands/${islandId}/research`, { technology });
}

export interface CargoTransferDTO {
    cargo: ResourcesDTO;
    cargoCapacity: number;
    stockpile: ResourcesDTO;
    tick: number;
}

/** Załadunek (magazyn → ładownia) albo rozładunek; dokowanie kosztuje tik. */
export async function transferCargo(
    profileId: string, islandId: string, direction: 'load' | 'unload', amount: Partial<ResourcesDTO>,
): Promise<CargoTransferDTO> {
    return http.post<CargoTransferDTO>(`/profiles/${profileId}/cargo`, { islandId, direction, ...amount });
}

export async function sail(profileId: string, x: number, y: number): Promise<SailResultDTO> {
    return http.post<SailResultDTO>(`/profiles/${profileId}/sail`, { x, y });
}
