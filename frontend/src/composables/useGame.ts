import { ref, onMounted, computed } from 'vue';
import { useRoute } from 'vue-router';
import {
    getGame, fireShot, fireTorpedo, sonarPing, sendAirRaid,
    type GameViewDTO, type ShotResultDTO, type RulesetName, type WeaponsState,
    type TorpedoDirection, type TurnOutcomeDTO, type SonarCell, type PlayerFleetItem, type CellShotResult,
} from '../api/gameApi.ts';
import { cellLabel, fieldsLabel, shipName } from '../lib/ships';

export type WeaponMode = 'shot' | 'torpedo' | 'sonar' | 'airraid';

export type PlayerCell = 'empty'|'ship'|'hit'|'miss';
export type EnemyCell = 'empty'|'hit'|'miss';
export type OverlayCell = 'none'|'opp-hit'|'opp-miss';

export type LogSide = 'TY' | 'WRÓG' | 'SONAR' | 'INFO';
export interface LogEntry { id: number; side: LogSide; text: string }

const TORPEDO_ARROWS: Record<TorpedoDirection, string> = {
    N: '↑', NE: '↗', E: '→', SE: '↘', S: '↓', SW: '↙', W: '←', NW: '↖',
};

export function useGame() {
    const route = useRoute();
    const gameId = ref<string>(String(route.params.id ?? ''));
    const width = ref<number>(10);
    const height = ref<number>(10);
    const playerGrid = ref<PlayerCell[][]>([]);
    // Overlay trafień/pudeł przeciwnika na planszy gracza
    const playerUnderFireOverlay = ref<OverlayCell[][]>([]);
    const enemyFogGrid = ref<EnemyCell[][]>([]);
    const turn = ref<GameViewDTO['turn']>('player');
    const status = ref<GameViewDTO['status']>('pending');
    const finished = ref<boolean>(false);
    const loading = ref<boolean>(false);
    const shooting = ref<boolean>(false);
    const error = ref<string>('');
    const toast = ref<string>('');
    const toastType = ref<'info'|'warn'|'error'>('info');
    const duplicates = ref<number>(0);
    const lastShot = ref<{ x: number; y: number; result: ShotResultDTO['result'] } | null>(null);
    const sunkCells = ref<Array<[number, number]>>([]);
    const shotsTotal = ref<number>(0);

    // Tryb fun: bronie specjalne
    const ruleset = ref<RulesetName>('classic');
    const weapons = ref<WeaponsState | null>(null);
    const opponentWeapons = ref<WeaponsState | null>(null);
    const weaponMode = ref<WeaponMode>('shot');
    const torpedoDirection = ref<TorpedoDirection>('E');
    // Wyniki sonaru — tylko po stronie klienta (backend nie zapisuje skanów)
    const sonarMarks = ref<SonarCell[]>([]);
    // Flota gracza (do wyznaczania wyrzutni torped)
    const playerFleet = ref<PlayerFleetItem[]>([]);
    // skład floty (ten sam dla obu stron — AI losuje flotę z rulesetu)
    const allowedShips = ref<Record<string, number>>({});
    // długości zatopionych statków: wroga (z projekcji) i własnych (z overlayu)
    const enemySunkLengths = ref<number[]>([]);
    // Notatki gracza a'la saper: „tu nic nie może być" — szkic po stronie klienta,
    // nie kosztuje tury, klucze "x:y"
    const noteMarks = ref<Set<string>>(new Set());

    // Dziennik bitwy — po stronie klienta (po przeładowaniu zaczyna się od nowa)
    const log = ref<LogEntry[]>([]);
    let logSeq = 0;
    function pushLog(side: LogSide, text: string) {
        log.value = [{ id: ++logSeq, side, text }, ...log.value].slice(0, 40);
    }

    function toggleNote(x: number, y: number) {
        // notatka ma sens tylko na nieostrzelanym polu
        if (enemyFogGrid.value[y]?.[x] !== 'empty') return;
        const key = `${x}:${y}`;
        const next = new Set(noteMarks.value);
        if (next.has(key)) {
            next.delete(key);
        } else {
            next.add(key);
        }
        noteMarks.value = next;
    }


    function buildEmptyGrid<T extends string>(w: number, h: number, fill: T): T[][] {
        return Array.from({ length: h }, () => Array.from({ length: w }, () => fill));
    }

    function shipCellsOf(s: PlayerFleetItem): Array<[number, number]> {
        const o = (s.o as string).toLowerCase();
        return Array.from({ length: s.l }, (_, i) => [s.x + (o === 'h' ? i : 0), s.y + (o === 'v' ? i : 0)] as [number, number]);
    }

    function applyProjection(dto: GameViewDTO) {
        width.value = dto.board.w;
        height.value = dto.board.h;
        // player grid with ships
        const pg = buildEmptyGrid<PlayerCell>(dto.board.w, dto.board.h, 'empty');
        for (const s of dto.playerFleet) {
            for (const [x, y] of shipCellsOf(s)) {
                if (pg[y] && typeof pg[y][x] !== 'undefined') pg[y][x] = 'ship';
            }
        }
        playerGrid.value = pg;

        // overlay trafień/pudeł przeciwnika na planszy gracza
        const ov = buildEmptyGrid<OverlayCell>(dto.board.w, dto.board.h, 'none');
        if (dto.playerUnderFireGrid) {
            for (const [x,y] of dto.playerUnderFireGrid.hits) {
                if (ov[y] && typeof ov[y][x] !== 'undefined') ov[y][x] = 'opp-hit';
            }
            for (const [x,y] of dto.playerUnderFireGrid.misses) {
                if (ov[y] && typeof ov[y][x] !== 'undefined') ov[y][x] = 'opp-miss';
            }
        }
        playerUnderFireOverlay.value = ov;

        // enemy fog grid from hits/misses/sunk
        const eg = buildEmptyGrid<EnemyCell>(dto.board.w, dto.board.h, 'empty');
        const sunk: Array<[number, number]> = [];

        for (const [x, y] of dto.enemyFogGrid.hits) {
            if (eg[y] && typeof eg[y][x] !== 'undefined') eg[y][x] = 'hit';
        }
        for (const [x, y] of dto.enemyFogGrid.misses) {
            if (eg[y] && typeof eg[y][x] !== 'undefined') eg[y][x] = 'miss';
        }
        for (const sunkShip of dto.enemyFogGrid.sunk) {
            for (const [x, y] of sunkShip.cells) {
                if (eg[y] && typeof eg[y][x] !== 'undefined') {
                    eg[y][x] = 'hit';
                    sunk.push([x, y]);
                }
            }
        }

        enemyFogGrid.value = eg;
        sunkCells.value = sunk;
        enemySunkLengths.value = dto.enemyFogGrid.sunk.map(s => s.cells.length);

        turn.value = dto.turn;
        status.value = dto.status;
        finished.value = dto.finished;
        ruleset.value = dto.ruleset ?? 'classic';
        weapons.value = dto.weapons ?? null;
        opponentWeapons.value = dto.opponentWeapons ?? null;
        playerFleet.value = dto.playerFleet ?? [];
        allowedShips.value = dto.allowedShips ?? {};
        shotsTotal.value = dto.shotsCount ?? 0;
    }

    function isShipSunk(s: PlayerFleetItem): boolean {
        const ov = playerUnderFireOverlay.value;
        return shipCellsOf(s).every(([x, y]) => ov[y]?.[x] === 'opp-hit');
    }

    /** Długości własnych statków, które wciąż pływają (żywe nośniki broni). */
    const aliveShipLengths = computed<Set<number>>(() => {
        const alive = new Set<number>();
        for (const s of playerFleet.value) {
            if (!isShipSunk(s)) alive.add(s.l);
        }
        return alive;
    });

    const playerSunkLengths = computed<number[]>(() => playerFleet.value.filter(isShipSunk).map(s => s.l));

    const enemyTotal = computed(() => Object.values(allowedShips.value).reduce((a, b) => a + b, 0));

    /**
     * Komórki własnych NIEZATOPIONYCH NISZCZYCIELI (3-masztowców) — legalne
     * wyrzutnie torped (reguła domenowa: broń wynika ze składu floty).
     */
    const launchableCells = computed<Set<string>>(() => {
        const set = new Set<string>();
        for (const s of playerFleet.value) {
            if (s.l !== 3 || isShipSunk(s)) continue;
            for (const [x, y] of shipCellsOf(s)) set.add(`${x}:${y}`);
        }
        return set;
    });

    function describeResult(r: CellShotResult['result']): string {
        switch (r) {
            case 'sunk': return 'zatopienie!';
            case 'hit': return 'trafienie';
            case 'miss': return 'pudło';
            default: return 'pole już ostrzelane';
        }
    }

    function logOpponentMoves(o: TurnOutcomeDTO) {
        const moves = o.opponentMoves ?? [];
        if (moves.length === 0) return;
        if (o.opponentTorpedoLaunch) {
            const hits = moves.filter(m => m.result === 'hit' || m.result === 'sunk').length;
            pushLog('WRÓG', `Torpeda z ${cellLabel(o.opponentTorpedoLaunch.x, o.opponentTorpedoLaunch.y)} — ${fieldsLabel(moves.length)}, trafienia: ${hits}. Wyrzutnia namierzona.`);
            return;
        }
        if (moves.length === 1) {
            const m = moves[0];
            pushLog('WRÓG', `Salwa w ${cellLabel(m.x, m.y)} — ${describeResult(m.result)}`);
            return;
        }
        // AI oddaje jeden ruch na turę — kilka pól naraz bez wyrzutni torpedy to nalot
        const hits = moves.filter(m => m.result === 'hit' || m.result === 'sunk').length;
        pushLog('WRÓG', `Nalot na ${fieldsLabel(moves.length)} (${moves.map(m => cellLabel(m.x, m.y)).join(', ')}) — trafienia: ${hits}`);
    }

    function applyTurnOutcome(o: TurnOutcomeDTO) {
        turn.value = o.turn;
        logOpponentMoves(o);
        if (o.finished) {
            status.value = o.win ? 'won' : (o.loss ? 'lost' : status.value);
            finished.value = true;
        }
        // torpeda AI zdradza wyrzutnię — oznacz jak wykryty sonarem statek
        if (o.opponentTorpedoLaunch) {
            const { x, y } = o.opponentTorpedoLaunch;
            const merged = new Map(sonarMarks.value.map(c => [`${c.x}:${c.y}`, c]));
            merged.set(`${x}:${y}`, { x, y, occupied: true });
            sonarMarks.value = [...merged.values()];
            showToast('Namierzono wyrzutnię torpedy przeciwnika!', 'info', 3000);
        }
    }

    function canAct(): boolean {
        return !!gameId.value && turn.value === 'player' && !shooting.value
            && status.value !== 'won' && status.value !== 'lost';
    }

    const sleep = (ms: number) => new Promise(resolve => setTimeout(resolve, ms));

    async function start() {
        loading.value = true;
        error.value = '';
        try {
            if (!gameId.value) {
                throw new Error('Brak identyfikatora gry w adresie URL');
            }
            const g = await getGame(gameId.value);
            applyProjection(g);
            pushLog('INFO', g.shotsCount > 0
                ? `Wczytano trwającą bitwę — oddano już ${g.shotsCount} strzałów.`
                : 'Flota na pozycjach. Wróg czeka we mgle — oddaj pierwszą salwę.');
        } catch (e: any) {
            error.value = e?.message ?? 'Start failed';
            try { console.warn('[useGame.start] ERROR', e); } catch {}
        } finally {
            loading.value = false;
        }
    }

    /** Odświeża projekcję i dopisuje do dziennika zatopienia, które z niej wynikają. */
    async function refresh() {
        if (!gameId.value) return;
        const enemyBefore = enemySunkLengths.value.length;
        const ownBefore = playerSunkLengths.value.length;
        const g = await getGame(gameId.value);
        applyProjection(g);
        for (const l of enemySunkLengths.value.slice(enemyBefore)) {
            pushLog('TY', `Zatopiono jednostkę wroga: ${shipName(l).toLowerCase()} (${fieldsLabel(l)}).`);
        }
        const ownSunk = playerSunkLengths.value;
        if (ownSunk.length > ownBefore) {
            pushLog('WRÓG', `Stracono: ${ownSunk.slice(ownBefore).map(l => shipName(l).toLowerCase()).join(', ')}.`);
        }
    }

    async function shot(x: number, y: number) {
        // Blokady: brak id, nie Twoja tura, już trwa strzał, gra skończona, pole poza zakresem lub już ostrzelane
        if (!gameId.value || turn.value !== 'player' || shooting.value) {
            return;
        }
        if (status.value === 'won' || status.value === 'lost') return;
        if (!enemyFogGrid.value[y] || typeof enemyFogGrid.value[y][x] === 'undefined') return;
        if (enemyFogGrid.value[y][x] === 'hit' || enemyFogGrid.value[y][x] === 'miss') return;

        try {
            shooting.value = true;
            const res: ShotResultDTO = await fireShot(gameId.value, x, y);
            if (enemyFogGrid.value[y] && typeof enemyFogGrid.value[y][x] !== 'undefined') {
                enemyFogGrid.value[y][x] = res.result === 'miss' ? 'miss' : 'hit';
            }
            if (res.result !== 'duplicate') {
                lastShot.value = { x, y, result: res.result === 'sunk' ? 'hit' : res.result };
                setTimeout(() => {
                    if (lastShot.value && lastShot.value.x === x && lastShot.value.y === y) {
                        lastShot.value = null;
                    }
                }, 400);
            }
            if (res.result === 'duplicate') {
                duplicates.value += 1;
                showToast('Duplikat: to pole było już ostrzelane.', 'warn', 2200);
            } else if (res.result !== 'sunk') {
                pushLog('TY', `Salwa w ${cellLabel(x, y)} — ${describeResult(res.result)}`);
            }
            // Tura/koniec gry + ewentualne ujawnienie wyrzutni torpedy AI
            // (AI może odpalić torpedę także w odpowiedzi na zwykły strzał!)
            applyTurnOutcome(res);
            // Refetch pełnej projekcji, by zsynchronizować mgłę i stany
            await refresh();
        } catch (e: any) {
            error.value = e?.message ?? 'Shot failed';
            showToast(error.value, 'error', 3000);
            try { console.warn('[useGame.shot] ERROR', e); } catch {}
        } finally {
            shooting.value = false;
        }
    }

    async function torpedoAt(x: number, y: number) {
        if (!canAct()) return;
        try {
            shooting.value = true;
            const res = await fireTorpedo(gameId.value, x, y, torpedoDirection.value);
            // animacja przejścia: odsłaniaj tor komórka po komórce
            for (const cell of res.results) {
                if (cell.result !== 'duplicate' && enemyFogGrid.value[cell.y]?.[cell.x] !== undefined) {
                    enemyFogGrid.value[cell.y][cell.x] = cell.result === 'miss' ? 'miss' : 'hit';
                }
                lastShot.value = { x: cell.x, y: cell.y, result: cell.result === 'sunk' ? 'hit' : cell.result };
                await sleep(90);
            }
            lastShot.value = null;
            const hits = res.results.filter(c => c.result === 'hit' || c.result === 'sunk').length;
            pushLog('TY', `Torpeda z ${cellLabel(x, y)} ${TORPEDO_ARROWS[torpedoDirection.value]} — ${fieldsLabel(res.results.length)}, trafienia: ${hits}`);
            applyTurnOutcome(res);
            await refresh();
        } catch (e: any) {
            showToast(e?.message ?? 'Torpeda nie wypaliła', 'warn', 2500);
        } finally {
            shooting.value = false;
            weaponMode.value = 'shot';
        }
    }

    async function sonarAt(x: number, y: number) {
        if (!canAct()) return;
        try {
            shooting.value = true;
            const res = await sonarPing(gameId.value, x, y);
            // animacja: odsłaniaj skan od środka na zewnątrz
            const ordered = [...res.results].sort(
                (a, b) => (Math.abs(a.x - x) + Math.abs(a.y - y)) - (Math.abs(b.x - x) + Math.abs(b.y - y))
            );
            const merged = new Map(sonarMarks.value.map(c => [`${c.x}:${c.y}`, c]));
            let lastDistance = -1;
            for (const c of ordered) {
                const distance = Math.abs(c.x - x) + Math.abs(c.y - y);
                if (distance !== lastDistance) {
                    await sleep(140);
                    lastDistance = distance;
                }
                merged.set(`${c.x}:${c.y}`, c);
                sonarMarks.value = [...merged.values()];
            }
            const found = res.results.filter(c => c.occupied).length;
            pushLog('SONAR', found > 0
                ? `Skan wokół ${cellLabel(x, y)}: echo kadłuba na ${found} ${found === 1 ? 'polu' : 'polach'}. Sonar nie kończy tury.`
                : `Skan wokół ${cellLabel(x, y)}: czysta woda. Sonar nie kończy tury.`);
            await refresh(); // licznik użyć
        } catch (e: any) {
            showToast(e?.message ?? 'Sonar nie zadziałał', 'warn', 2500);
        } finally {
            shooting.value = false;
            weaponMode.value = 'shot';
        }
    }

    async function airRaidAt(x: number, y: number) {
        if (!canAct()) return;
        try {
            shooting.value = true;
            const res = await sendAirRaid(gameId.value, x, y);
            const hits = res.results.filter(c => c.result === 'hit' || c.result === 'sunk').length;
            pushLog('TY', `Nalot na ${cellLabel(x, y)} (3×3) — trafienia: ${hits}`);
            applyTurnOutcome(res);
            await refresh();
        } catch (e: any) {
            showToast(e?.message ?? 'Nalot się nie powiódł', 'warn', 2500);
        } finally {
            shooting.value = false;
            weaponMode.value = 'shot';
        }
    }

    /** Jeden punkt wejścia dla kliknięcia w planszę przeciwnika — wg aktywnego trybu. */
    async function attack(x: number, y: number) {
        switch (weaponMode.value) {
            case 'torpedo': return torpedoAt(x, y);
            case 'sonar': return sonarAt(x, y);
            case 'airraid': return airRaidAt(x, y);
            default: return shot(x, y);
        }
    }

    onMounted(start);

    const size = computed(() => Math.max(width.value, height.value));
    const hitsCount = computed(() => enemyFogGrid.value.flat().filter(c => c === 'hit').length);
    const missesCount = computed(() => enemyFogGrid.value.flat().filter(c => c === 'miss').length);
    const shotsCount = computed(() => hitsCount.value + missesCount.value);
    const duplicatesCount = computed(() => duplicates.value);
    const opponentHitsCount = computed(() => playerUnderFireOverlay.value.flat().filter(c => c === 'opp-hit').length);
    const disabled = computed(() => loading.value || shooting.value || turn.value !== 'player' || status.value === 'won' || status.value === 'lost');

    function showToast(message: string, type: 'info'|'warn'|'error' = 'info', ms = 2000) {
        toast.value = message;
        toastType.value = type;
        if (ms > 0) setTimeout(() => { if (toast.value === message) toast.value = ''; }, ms);
    }

    return {
        gameId, size, width, height, playerGrid, playerUnderFireOverlay, enemyFogGrid, turn, status, finished,
        loading, shooting, error, start, refresh, shot, attack, disabled,
        // tryb fun
        ruleset, weapons, opponentWeapons, weaponMode, torpedoDirection, sonarMarks, launchableCells, aliveShipLengths,
        // notatki (saper-style)
        noteMarks, toggleNote,
        // stats
        shotsCount, shotsTotal, hitsCount, missesCount, duplicatesCount, opponentHitsCount,
        enemySunkLengths, playerSunkLengths, enemyTotal,
        // dziennik i toast
        log, toast, toastType,
        lastShot, sunkCells,
    };
}
