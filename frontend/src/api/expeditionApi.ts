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

// wariant statku (#99): ten sam kadłub, inne wyposażenie; trzy ostatnie ma tylko wróg,
// dopóki gracz nie zbada konstrukcji zdobytego statku (#121)
export type ShipVariantName = 'torpedowy' | 'zwiadowczy' | 'kuter-torpedowy' | 'ciezki-niszczyciel' | 'lotniskowiec-eskortowy';

// krótka etykieta wariantu przy przycisku budowy („Buduj zwiadowczy”)
export const SHIP_VARIANT_LABELS: Record<ShipVariantName, string> = {
    torpedowy: 'torpedowy',
    zwiadowczy: 'zwiadowczy',
    'kuter-torpedowy': 'torpedowy',
    'ciezki-niszczyciel': 'ciężki',
    'lotniskowiec-eskortowy': 'eskortowy',
};

// pełna nazwa statku w wariancie
export const SHIP_VARIANT_NAMES: Record<ShipVariantName, string> = {
    torpedowy: 'Niszczyciel torpedowy',
    zwiadowczy: 'Niszczyciel zwiadowczy',
    'kuter-torpedowy': 'Kuter torpedowy',
    'ciezki-niszczyciel': 'Ciężki niszczyciel',
    'lotniskowiec-eskortowy': 'Lotniskowiec eskortowy',
};

/** Nazwa statku floty z wariantem: „Niszczyciel zwiadowczy”, „Ciężki niszczyciel”, „Kuter”. */
export function fleetShipLabel(ship: { type: ShipTypeName; variant?: ShipVariantName | null }): string {
    return ship.variant ? SHIP_VARIANT_NAMES[ship.variant] : SHIP_LABELS[ship.type];
}

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
    // stały od budowy; null = typ bez wariantów
    variant: ShipVariantName | null;
    damaged: boolean;
    repairCost: ResourcesDTO;
    // złomowanie (#124): zwrot do magazynu wyspy (uszkodzony oddaje mniej o remont) i blokada domeny;
    // wymóg stoczni w porcie sprawdza widok
    scrapValue: ResourcesDTO;
    scrapBlock: ScrapBlockCode | null;
    // sprzedaż w porcie handlowym akwenu (#140): złoto za statek (blokady jak przy złomowaniu); null = akwen bez portu
    saleValue: number | null;
}

// Domain/Expedition/ScrapBlock
export type ScrapBlockCode = 'in-battle' | 'last-seaworthy';

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
    // wariant statku budowanego bez wyboru; null = obok wariantów jest statek podstawowy (kuter, lotniskowiec)
    defaultVariant: ShipVariantName | null;
    // warianty do wyboru przy budowie (pusta lista = typ bez wariantów)
    variants: ShipVariantDTO[];
}

export interface ShipVariantDTO {
    variant: ShipVariantName;
    requiredTechnology: TechnologyName | null;
    // ścieżka odblokowania technologii wariantu; null = wariant bez technologii
    unlock: TechUnlockName | null;
    unlocked: boolean;
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
    // twierdza z fortem (#120): wygrana potyczka oblega wyspę, banderę daje wygrane oblężenie
    stronghold: boolean;
    besieged: boolean;
    // skład oblężenia mieści się w limicie planszy fortu; null = wyspa bez fortu
    siegeSquadLimit: number | null;
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

// motyw akwenu (Epic I): szansa sztormu przy pierwszym wpłynięciu (%), promień odkrywania (0 = tylko sektor floty), mnożnik złóż
export interface SeaThemeDTO {
    stormChance: number;
    visibility: number;
    depositMultiplier: number;
}

// akwen, w którym stoi flota
export interface SeaDTO {
    id: string;
    name: string;
    theme: SeaThemeDTO;
}

// powód zamknięcia bramy (Domain/Expedition/GateBlock); null = brama otwarta
export type GateBlockCode = 'rank' | 'guardian' | 'technology';

// strażnik strzeżonej cieśniny (#107): stała flota wroga, nagrody jak za wyspę
export interface GuardianDTO {
    name: string;
    description: string;
    requiredRank: RankName;
    unlocked: boolean;
    // pokonany = brama otwarta na stałe, w obie strony
    defeated: boolean;
    mode: 'classic' | 'fun';
    fleet: ShipTypeName[];
    fleetCells: number;
    board: { w: number; h: number };
    squadLimit: number;
    xpWin: number;
    xpLoss: number;
    lootWin: ResourcesDTO;
    lootLoss: ResourcesDTO;
    wins: number;
    losses: number;
}

// brama wychodząca z akwenu floty; sektor widać po odkryciu, jak wyspy
export interface GateDTO {
    id: string;
    name: string;
    edge: 'north' | 'east' | 'south' | 'west';
    to: SeaDTO;
    discovered: boolean;
    position: { x: number; y: number } | null;
    present: boolean;
    blocker: GateBlockCode | null;
    open: boolean;
    // technologia, która otwiera bramę (otwarty ocean, #108); null = inny warunek
    technology: TechnologyName | null;
    guardian: GuardianDTO | null;
}

export interface WorldDTO {
    sea: SeaDTO;
    width: number;
    height: number;
    position: { x: number; y: number };
    discovered: string[]; // sektory "x:y"
    visited: string[]; // sektory "x:y", na których kapitan był — tam sztorm nie grozi
    atIsland: string | null;
    // brama na sektorze floty (#107)
    atGate: string | null;
    gates: GateDTO[];
    // port handlowy akwenu (#140): neutralna wyspa z rynkiem; null = akwen bez portu
    tradePort: TradePortDTO | null;
    // szansa sztormu akwenu z Mapami sztormowymi (połowa) albo bez nich
    stormChance: number;
    // najdalszy sektor osiągalny w jednym rejsie (większa z różnic x i y); Żegluga dwusektorowa: 2
    sailRange: number;
    // zegar świata: liczba akcji morskich (rejs, bitwa)
    tick: number;
    // bandery na akwenach, które kapitan zna (#109): pełna bandera działa przy obronie wyspy swojego akwenu
    flags: SeaFlagDTO[];
}

// Domain/Expedition/MarketGood i MarketSide (#140)
export type MarketGoodName = 'wood' | 'steel';
export type MarketSideName = 'buy' | 'sell';

// port handlowy (#140): sektor widać po odkryciu, jak wyspy; kurs = towar za 1 złoto
export interface TradePortDTO {
    id: string;
    name: string;
    description: string;
    discovered: boolean;
    position: { x: number; y: number } | null;
    present: boolean;
    // buy: ile towaru kupuje 1 złoto; sell: ile towaru trzeba oddać za 1 złoto (połowa ceny kupna)
    rate: Record<MarketGoodName, { buy: number; sell: number }>;
    // mnożnik ceny w procentach (100 = kurs bazowy); pod reputację z #142
    pricePercent: number;
    stockpile: ResourcesDTO;
}

export interface SeaFlagDTO {
    sea: string;
    name: string;
    owned: number;
    total: number;
    fullFlag: boolean;
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
    atGate: string | null;
    // spotkanie z handlarzem albo piratem (#141): rejs stoi do decyzji; skutki wyborów w ExpeditionDTO.encounter
    encounter?: { type: EncounterTypeName; tick: number; sea: string; x: number; y: number } | null;
}

// przejście przez bramę (#107): jak rejs, plus akwen docelowy i zegar
export interface PassGateResultDTO extends SailResultDTO {
    sea: SeaDTO;
    tick: number;
}

// kontratak (#78): zapowiedź uderza po 12 tikach; obronę przyjmuje flota stojąca na wyspie celu
export interface PendingCounterattackDTO {
    island: string;
    islandName: string;
    announcedAt: number;
    strikeAt: number;
    ticksLeft: number;
    // akwen celu (#109); fleetAway = flota stoi na innym akwenie
    sea: { id: string; name: string } | null;
    fleetAway: boolean;
    // droga floty na wyspę: rejsy + 1 tik za bramę; inTime = dopłynie przed uderzeniem; null = brak przejścia
    route: { ticks: number; gates: number; inTime: boolean } | null;
    // rozpoczęta obrona wstrzymuje termin do rozliczenia bitwy
    defenseStarted: boolean;
    // pełna bandera akwenu celu (#79, #109): obrońca strzela pierwszy, napastnik bez największego statku
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
export type TechnologyName = 'spichlerze' | 'mapy-sztormowe' | 'sonar-dalekiego-zasiegu' | 'zegluga-dwusektorowa' | 'pojemne-ladownie' | 'dok-remontowy' | 'lepsze-narzedzia' | 'niszczyciel-zwiadowczy' | 'nawigacja-oceaniczna'
    | 'kuter-torpedowy' | 'ciezki-niszczyciel' | 'lotniskowiec-eskortowy';

// ścieżka odblokowania (#121): uczelnia (drzewko z poziomami) albo zbadanie konstrukcji zdobytego statku
export type TechUnlockName = 'academy' | 'boarding';

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
    'pojemne-ladownie': 'Pojemne ładownie',
    'dok-remontowy': 'Dok remontowy',
    'lepsze-narzedzia': 'Lepsze narzędzia',
    'niszczyciel-zwiadowczy': 'Niszczyciel zwiadowczy',
    'nawigacja-oceaniczna': 'Nawigacja oceaniczna',
    'kuter-torpedowy': 'Kuter torpedowy',
    'ciezki-niszczyciel': 'Ciężki niszczyciel',
    'lotniskowiec-eskortowy': 'Lotniskowiec eskortowy',
};

// powód blokady badania (Domain/Expedition/ResearchBlock); null = można badać
export type ResearchBlockCode = 'researched' | 'boarding-only' | 'busy' | 'prerequisite' | 'rank' | 'no-academy' | 'not-present' | 'resources';

export interface TechLevelDTO {
    level: number;
    cost: ResourcesDTO;
    ticks: number;
    requiredRank: RankName | null;
}

export interface TechnologyDTO {
    id: TechnologyName;
    branch: TechBranchName;
    // null = technologia ze zdobyczy, poza poziomami drzewka
    level: number | null;
    unlock: TechUnlockName;
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

// dziennik kapitana (#111): rodzaj zdarzenia (Domain/Expedition/LogEvent) z danymi; treść składa lib/eventLog
// rodzaj bitwy w rejestrze profilu (Domain/Expedition/BattleKind)
export type BattleKindName = 'capture' | 'defense' | 'guardian' | 'siege' | 'encounter';

export type LogEventName =
    | 'storm' | 'island-captured' | 'island-lost' | 'island-defended' | 'island-plundered' | 'counterattack-announced'
    | 'research-completed' | 'ship-built' | 'building-built' | 'gate-opened' | 'gate-passed'
    | 'ship-boarded' | 'prize-decided' | 'island-besieged' | 'siege-failed' | 'ship-scrapped'
    | 'market-trade' | 'ship-sold'
    | 'encounter-met' | 'encounter-resolved' | 'encounter-battle';

export interface LogEntryDTO {
    tick: number;
    event: LogEventName;
    // dane zależne od rodzaju; nazwy wysp, bram i akwenów dokłada backend
    island?: string;
    islandName?: string;
    gate?: string;
    gateName?: string;
    from?: string;
    fromName?: string;
    to?: string;
    toName?: string;
    // sztorm: jak SailResultDTO.event
    effect?: 'cargo-lost' | 'ship-damaged';
    ship?: ShipTypeName;
    variant?: ShipVariantName;
    cargoLost?: ResourcesDTO;
    unescorted?: boolean;
    absent?: boolean;
    stockpileLost?: ResourcesDTO;
    strikeAt?: number;
    technology?: TechnologyName;
    building?: BuildingTypeName;
    // decyzja o zdobyczy (#118): rozbiórka podaje zwrot i to, co nie zmieściło się w ładowni,
    // a zbadanie konstrukcji (#121) odblokowaną technologię (pole technology)
    decision?: PrizeDecisionName | EncounterDecisionName | 'trade';
    refund?: ResourcesDTO;
    lost?: ResourcesDTO;
    // złomowanie (#124): zwrot w refund; ładunek ponad pojemność floty został w magazynie wyspy
    damaged?: boolean;
    unloaded?: ResourcesDTO;
    // rynek portu handlowego (#140): transakcja i sprzedaż statku (złoto w gold)
    side?: MarketSideName;
    good?: MarketGoodName;
    paid?: ResourcesDTO;
    received?: ResourcesDTO;
    gold?: number;
    // spotkanie na morzu (#141): rodzaj; przy rozstrzygnięciu decision (pass|flee|ransom|trade), przy bitwie result
    encounter?: EncounterTypeName;
    result?: 'won' | 'lost';
    sea?: string;
    seaName?: string;
    // okup w złocie (pole gold); handel: sprzedane i kupione surowce z zapłatą w złocie
    sold?: ResourcesDTO;
    bought?: ResourcesDTO;
    goldReceived?: number;
    goldPaid?: number;
}

// decyzja o statku zdobytym abordażem (#118); study = zbadaj konstrukcję (#121)
export type PrizeDecisionName = 'join' | 'scrap' | 'study';

// statek zdobyty abordażem, czekający na decyzję kapitana (#118)
export interface PrizeDTO {
    id: string;
    type: ShipTypeName;
    variant: ShipVariantName | null;
    length: number;
    tick: number;
    // zwrot z rozbiórki: połowa kosztu budowy
    scrapValue: ResourcesDTO;
    // technologia z „Zbadaj konstrukcję” (#121); null = wariant znany albo statek bez wariantu ze zdobyczy
    studyTechnology: TechnologyName | null;
}

export interface DecidePrizeDTO {
    decision: PrizeDecisionName;
    // dołączony statek (uszkodzony); null przy rozbiórce
    ship: Omit<FleetShipDTO, 'repairCost' | 'scrapValue' | 'scrapBlock'> | null;
    refund: ResourcesDTO;
    cargoLost: ResourcesDTO;
    cargo: ResourcesDTO;
    // odblokowana technologia przy zbadaniu konstrukcji (#121)
    technology: TechnologyName | null;
}

export async function decidePrize(profileId: string, prizeId: string, decision: PrizeDecisionName): Promise<DecidePrizeDTO> {
    return http.post<DecidePrizeDTO>(`/profiles/${profileId}/prizes/${prizeId}`, { decision });
}

// spotkania na morzu (#141): kogo flota spotkała i wybór bez bitwy (handel i bitwa mają własne akcje)
export type EncounterTypeName = 'merchant' | 'pirate';
export type EncounterDecisionName = 'pass' | 'flee' | 'ransom';

// spotkanie czekające na decyzję, ze skutkami wyborów podanymi z góry
export interface EncounterDTO {
    type: EncounterTypeName;
    tick: number;
    sea: { id: string; name: string };
    position: { x: number; y: number };
    // bitwa: napad na handlarza albo walka z piratem; łup do ładowni, za przegraną tylko XP
    battle: {
        name: string;
        mode: 'classic' | 'fun';
        fleet: ShipTypeName[];
        board: { w: number; h: number };
        squadLimit: number;
        xpWin: number;
        xpLoss: number;
        lootWin: ResourcesDTO;
        lootLoss: ResourcesDTO;
    };
    // handel u handlarza: towar za 1 złoto u handlarza (rate) i w porcie akwenu (portRate, #140), marża w %;
    // zlecenie w sztukach — kupno płaci za każdą zaczętą porcję, sprzedaż dostaje złoto za pełne
    trade: {
        rate: Record<MarketGoodName, { buy: number; sell: number }>;
        portRate: Record<MarketGoodName, { buy: number; sell: number }>;
        worsePercent: number;
        ticks: number;
    } | null;
    // pirat: ucieczka (tiki i ładunek, który zabierze) i okup w złocie z ładowni
    flee: { ticks: number; cargoLost: ResourcesDTO } | null;
    ransom: { gold: number; affordable: boolean } | null;
}

export interface ResolveEncounterDTO {
    decision: EncounterDecisionName;
    cargoLost: ResourcesDTO;
    goldPaid: number;
    cargo: ResourcesDTO;
    tick: number;
}

export interface MerchantTradeDTO {
    goldReceived: number;
    goldPaid: number;
    cargo: ResourcesDTO;
    tick: number;
}

export type TradeOrder = { wood: number; steel: number };

export async function decideEncounter(profileId: string, decision: EncounterDecisionName): Promise<ResolveEncounterDTO> {
    return http.post<ResolveEncounterDTO>(`/profiles/${profileId}/encounter/decide`, { decision });
}

/** Jedno zlecenie u handlarza: sprzedaż i kupno drewna i stali za złoto z ładowni; 1 tik. */
export async function tradeWithMerchant(profileId: string, sell: TradeOrder, buy: TradeOrder): Promise<MerchantTradeDTO> {
    return http.post<MerchantTradeDTO>(`/profiles/${profileId}/encounter/trade`, { sell, buy });
}

/** Napad na handlarza albo walka z piratem; bez `shipIds` skład domyślny w limicie planszy spotkania. */
export async function startEncounterBattle(profileId: string, shipIds?: string[]): Promise<CreateGameResponse> {
    return http.post<CreateGameResponse>(`/profiles/${profileId}/encounter/battle`, shipIds ? { shipIds } : {});
}

export interface ExpeditionDTO {
    profile: ExpeditionProfileDTO;
    fleet: FleetShipDTO[];
    // statki zdobyte abordażem, czekające na decyzję (#118)
    prizes: PrizeDTO[];
    shipTypes: ShipTypeDTO[];
    buildingTypes: BuildingTypeDTO[];
    islands: IslandDTO[];
    world: WorldDTO;
    counterattack: { pending: PendingCounterattackDTO | null; last: LastCounterattackDTO | null };
    // spotkanie z rejsu czekające na decyzję (#141); null = droga wolna
    encounter: EncounterDTO | null;
    research: ResearchDTO;
    // od najnowszego wpisu, najwyżej 50
    log: LogEntryDTO[];
    // zapisane zestawy floty z rozmieszczeniem (#125), najwyżej 5
    fleetPresets: FleetPresetDTO[];
    // skład nierozliczonych bitew: gameId → id statków floty
    battleSquads: Record<string, string[]>;
}

export interface FleetPresetShipDTO {
    shipId: string;
    // typ z chwili zapisu — statek mógł potem przepaść albo pójść na złom
    type: ShipTypeName;
    x: number;
    y: number;
    o: 'H' | 'V';
}

export interface FleetPresetDTO {
    id: string;
    name: string;
    board: { w: number; h: number };
    ships: FleetPresetShipDTO[];
}

export interface SettleResultDTO {
    result: 'won' | 'lost';
    awarded: number;
    xp: number;
    rank: RankName;
    rankUp: boolean;
    loot: ResourcesDTO;
    // łup leży w magazynie wyspy bitwy (strażnik: null, łup w ładowni); ładunek ponad pojemność przepadł
    stockpile: ResourcesDTO | null;
    cargo: ResourcesDTO;
    cargoLost: ResourcesDTO;
    lostShips: ShipTypeName[];
    damagedShips: ShipTypeName[];
    // wygrana postawiła banderę na wyspie
    captured: boolean;
    // wygrana potyczka obległa twierdzę — banderę da oblężenie (#120)
    besieged: boolean;
    kind: BattleKindName;
    // zdobycie wyspy ze złożem: urobek na tik; null = złoże nie ruszyło teraz
    depositStarted: ResourcesDTO | null;
    // bitwa obronna (#78): czy bandera została i co przepadło z magazynu; null = bitwa o zdobycie
    defense: { held: boolean; stockpileLost: ResourcesDTO } | null;
    // wygrana ze strażnikiem otworzyła bramę akwenu (#107)
    gateOpened: boolean;
    // abordaż (#118): zdobyty statek czeka na decyzję na ekranie Rejs; null = bez udanego abordażu
    prize: Omit<PrizeDTO, 'length' | 'scrapValue' | 'studyTechnology'> | null;
    // bitwa spotkania (#141): merchant = napad, pirate = walka z piratem; null = inna bitwa
    encounter: EncounterTypeName | null;
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

/** Start bitwy ze strażnikiem bramy (#107); flota musi stać na sektorze bramy. */
export async function startGuardianBattle(profileId: string, gateId: string, shipIds?: string[]): Promise<CreateGameResponse> {
    return http.post<CreateGameResponse>(`/profiles/${profileId}/gates/${gateId}/battle`, shipIds ? { shipIds } : {});
}

/** Start oblężenia fortu oblężonej twierdzy (#120); flota musi stać przy wyspie. */
export async function startSiege(profileId: string, islandId: string, shipIds?: string[]): Promise<CreateGameResponse> {
    return http.post<CreateGameResponse>(`/profiles/${profileId}/islands/${islandId}/siege`, shipIds ? { shipIds } : {});
}

/** Przejście przez otwartą bramę na drugi akwen; kosztuje tik. */
export async function passGate(profileId: string, gateId: string): Promise<PassGateResultDTO> {
    return http.post<PassGateResultDTO>(`/profiles/${profileId}/gates/${gateId}/pass`, {});
}

export async function settleBattle(profileId: string, gameId: string): Promise<SettleResultDTO> {
    return http.post<SettleResultDTO>(`/profiles/${profileId}/battles/${gameId}/settle`, {});
}

export async function buildShip(profileId: string, islandId: string, type: ShipTypeName, variant?: ShipVariantName): Promise<FleetShipDTO> {
    return http.post<FleetShipDTO>(`/profiles/${profileId}/ships`, { type, islandId, ...(variant ? { variant } : {}) });
}

export async function repairShip(profileId: string, islandId: string, shipId: string): Promise<FleetShipDTO> {
    return http.post<FleetShipDTO>(`/profiles/${profileId}/ships/${shipId}/repair`, { islandId });
}

export interface ScrapShipDTO {
    refund: ResourcesDTO;
    stockpile: ResourcesDTO;
    cargo: ResourcesDTO;
}

export async function scrapShip(profileId: string, islandId: string, shipId: string): Promise<ScrapShipDTO> {
    return http.post<ScrapShipDTO>(`/profiles/${profileId}/ships/${shipId}/scrap`, { islandId });
}

export interface TradeDTO {
    paid: ResourcesDTO;
    received: ResourcesDTO;
    cargo: ResourcesDTO;
    cargoCapacity: number;
    stockpile: ResourcesDTO;
    tick: number;
}

/** Kupno albo sprzedaż towaru w porcjach po 1 złoto w porcie handlowym (#140); kosztuje tik. */
export async function trade(profileId: string, portId: string, side: MarketSideName, good: MarketGoodName, gold: number): Promise<TradeDTO> {
    return http.post<TradeDTO>(`/profiles/${profileId}/trade-ports/${portId}/trade`, { side, good, gold });
}

export interface SellShipDTO {
    gold: number;
    cargo: ResourcesDTO;
    cargoCapacity: number;
    tick: number;
}

/** Sprzedaż statku za złoto w porcie handlowym (#140); kosztuje tik. */
export async function sellShip(profileId: string, portId: string, shipId: string): Promise<SellShipDTO> {
    return http.post<SellShipDTO>(`/profiles/${profileId}/trade-ports/${portId}/ships/${shipId}/sell`);
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

/** Zapis zestawu floty (#125); zestaw o tej samej nazwie jest nadpisywany. */
export async function saveFleetPreset(
    profileId: string, name: string, board: { w: number; h: number }, ships: Omit<FleetPresetShipDTO, 'type'>[],
): Promise<FleetPresetDTO> {
    return http.post<FleetPresetDTO>(`/profiles/${profileId}/fleet-presets`, { name, board, ships });
}

export async function renameFleetPreset(profileId: string, presetId: string, name: string): Promise<FleetPresetDTO> {
    return http.patch<FleetPresetDTO>(`/profiles/${profileId}/fleet-presets/${presetId}`, { name });
}

export async function deleteFleetPreset(profileId: string, presetId: string): Promise<void> {
    await http.delete<null>(`/profiles/${profileId}/fleet-presets/${presetId}`);
}
