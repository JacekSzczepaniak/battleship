import { ref, onMounted, onBeforeUnmount, computed, nextTick } from 'vue';
import { useRoute } from 'vue-router';
import {
    getGame, fireShot, fireTorpedo, sonarPing, sendAirRaid, endTurn as apiEndTurn, moveShip as apiMoveShip, boardShip as apiBoardShip,
    type BoardingState, type GameViewDTO, type MoveDirection, type ShotResultDTO, type EnergyState, type RulesetName, type WeaponsState,
    type TorpedoDirection, type TurnOutcomeDTO, type SonarCell, type PlayerFleetItem, type CellShotResult, type Loadout, type FortView,
} from '../api/gameApi.ts';
import { carriedWeapons, cellLabel, fieldsLabel, shipName } from '../lib/ships';
import {
    FxSequencer, fxMode, isCancelled, playAirstrike, playShot, playSonar, playTorpedo,
    type Cell, type FxBoard, type FxOutcome, type FxToken,
} from '../fx';

export type WeaponMode = 'shot' | 'torpedo' | 'sonar' | 'airraid';

export type PlayerCell = 'empty'|'ship'|'hit'|'miss';
export type EnemyCell = 'empty'|'hit'|'miss';
export type OverlayCell = 'none'|'opp-hit'|'opp-miss';

export type LogSide = 'TY' | 'WRÓG' | 'SONAR' | 'INFO';
export interface LogEntry { id: number; side: LogSide; text: string }

const TORPEDO_ARROWS: Record<TorpedoDirection, string> = {
    N: '↑', NE: '↗', E: '→', SE: '↘', S: '↓', SW: '↙', W: '←', NW: '↖',
};

const TORPEDO_STEPS: Record<TorpedoDirection, Cell> = {
    N: [0, -1], NE: [1, -1], E: [1, 0], SE: [1, 1], S: [0, 1], SW: [-1, 1], W: [-1, 0], NW: [-1, -1],
};

/** Plansza z warstwą efektów (#145) — SeaGrid przez template ref. */
export interface FxBoardHost { fxBoard(): FxBoard | null }

/** Animacja broni gracza na wodach wroga; `outcome` z projekcji po ruchu. */
type PlayerFx = (tok: FxToken, board: FxBoard, outcome: FxOutcome) => Promise<void>;

// odrzucenia ruchu statku (#60) po kodzie z API
const MOVE_ERRORS: Record<string, string> = {
    SHIP_MOVE_DAMAGED: 'Trafiony statek nie manewruje.',
    SHIP_MOVE_SHOT_CELL: 'Nie wpłyniesz na pole, w które strzelał wróg.',
    SHIP_MOVE_OUT_OF_BOUNDS: 'Statek nie wypłynie poza planszę.',
    SHIP_MOVE_TOUCH: 'Statki nie mogą się stykać.',
    SHIP_MOVE_DIRECTION: 'Statek płynie tylko wzdłuż swojej osi.',
    SHIP_ALREADY_MOVED: 'Ruch statku już wykorzystany w tej turze.',
    NOT_ENOUGH_ENERGY: 'Brak energii na ruch.',
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
    const sunkCells = ref<Array<[number, number]>>([]);
    const shotsTotal = ref<number>(0);

    // Tryb fun: bronie specjalne
    const ruleset = ref<RulesetName>('classic');
    const weapons = ref<WeaponsState | null>(null);
    const opponentWeapons = ref<WeaponsState | null>(null);
    // energia w turze (#59); null = bitwa bez energii
    const energy = ref<EnergyState | null>(null);
    // ruch statku wykorzystany w tej turze (#60)
    const moveUsed = ref<boolean>(false);
    // abordaż (#118); null = bitwa bez abordażu
    const boarding = ref<BoardingState | null>(null);
    const weaponMode = ref<WeaponMode>('shot');
    const torpedoDirection = ref<TorpedoDirection>('E');
    // Wyniki sonaru — tylko po stronie klienta (backend nie zapisuje skanów)
    const sonarMarks = ref<SonarCell[]>([]);
    // Flota gracza (do wyznaczania wyrzutni torped)
    const playerFleet = ref<PlayerFleetItem[]>([]);
    // skład floty (ten sam dla obu stron — AI losuje flotę z rulesetu)
    const allowedShips = ref<Record<string, number>>({});
    const opponentShips = ref<Record<string, number>>({});
    // warianty wroga (lustro składu gracza, #99): długość => wyposażenia odbiegające od domyślnego
    const opponentVariants = ref<Record<string, Loadout[]>>({});
    // długości zatopionych statków: wroga (z projekcji) i własnych (z overlayu)
    const enemySunkLengths = ref<number[]>([]);
    // Notatki gracza a'la saper: „tu nic nie może być" — szkic po stronie klienta,
    // nie kosztuje tury, klucze "x:y"
    const noteMarks = ref<Set<string>>(new Set());
    // bitwa oblężnicza (#119): fort zamiast floty wroga; null = zwykła bitwa
    const fort = ref<FortView | null>(null);

    // efekty (#145): plansze z warstwą overlay i sekwencja animacji tury;
    // opponentAnimating = trwa odtwarzanie ruchu AI na planszy gracza
    const enemyBoard = ref<FxBoardHost | null>(null);
    const ownBoard = ref<FxBoardHost | null>(null);
    const fx = new FxSequencer();
    const animating = ref<boolean>(false);
    const opponentAnimating = ref<boolean>(false);

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
        energy.value = dto.energy ?? null;
        moveUsed.value = dto.moveUsed ?? false;
        boarding.value = dto.boarding ?? null;
        playerFleet.value = dto.playerFleet ?? [];
        allowedShips.value = dto.allowedShips ?? {};
        opponentShips.value = dto.opponentShips ?? dto.allowedShips ?? {};
        opponentVariants.value = dto.opponentVariants && !Array.isArray(dto.opponentVariants) ? dto.opponentVariants : {};
        shotsTotal.value = dto.shotsCount ?? 0;
        fort.value = dto.fort ?? null;
    }

    function isShipSunk(s: PlayerFleetItem): boolean {
        const ov = playerUnderFireOverlay.value;
        return shipCellsOf(s).every(([x, y]) => ov[y]?.[x] === 'opp-hit');
    }

    /** Bronie, których nośnik wciąż pływa (wyposażenie statku, także wariantu #99). */
    const aliveCarriers = computed<Set<WeaponMode>>(() => {
        const alive = new Set<WeaponMode>();
        for (const s of playerFleet.value) {
            if (!isShipSunk(s)) carriedWeapons(s.l, s.w).forEach(w => alive.add(w));
        }
        return alive;
    });

    const playerSunkLengths = computed<number[]>(() => playerFleet.value.filter(isShipSunk).map(s => s.l));

    // składy stron mogą się różnić (bonus pełnej bandery, #79)
    const enemyTotal = computed(() => Object.values(opponentShips.value).reduce((a, b) => a + b, 0));
    const playerTotal = computed(() => Object.values(allowedShips.value).reduce((a, b) => a + b, 0));

    /**
     * Komórki własnych NIEZATOPIONYCH nośników torped — legalne wyrzutnie
     * (reguła domenowa: broń wynika z wyposażenia statku; zwiadowca torped nie ma).
     */
    const launchableCells = computed<Set<string>>(() => {
        const set = new Set<string>();
        for (const s of playerFleet.value) {
            if (!carriedWeapons(s.l, s.w).includes('torpedo') || isShipSunk(s)) continue;
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
        if (o.energy !== undefined) energy.value = o.energy;
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

    // --- animacja tury (#145) ---

    /** Skutki ruchu gracza z projekcji: nowo zatopione statki wroga i eksplozja magazynu. */
    function enemyOutcome(g: GameViewDTO): FxOutcome {
        const known = new Set(sunkCells.value.map(([x, y]) => `${x}:${y}`));
        const magazine = g.fort?.revealed.find(c => c.kind === 'magazine');
        const isMagazine = (cells: Cell[]) => !!magazine && cells.length === 1 && cells[0][0] === magazine.x && cells[0][1] === magazine.y;
        const sunk = g.enemyFogGrid.sunk
            .map(s => s.cells)
            .filter(cells => cells.some(([x, y]) => !known.has(`${x}:${y}`)) && !isMagazine(cells));
        const explosion = g.fort?.explosion;
        const blast = explosion && magazine && !fort.value?.explosion
            ? { at: [magazine.x, magazine.y] as Cell, cells: explosion }
            : null;
        return { sunk, blast };
    }

    /** Skutki ruchu AI: statki gracza zatopione w tej turze. */
    function ownOutcome(g: GameViewDTO): FxOutcome {
        const ov = playerUnderFireOverlay.value;
        const hits = new Set((g.playerUnderFireGrid?.hits ?? []).map(([x, y]) => `${x}:${y}`));
        const sunk = (g.playerFleet ?? [])
            .map(shipCellsOf)
            .filter(cells => cells.every(([x, y]) => hits.has(`${x}:${y}`)) && cells.some(([x, y]) => ov[y]?.[x] !== 'opp-hit'));
        return { sunk };
    }

    function hasOpponentAction(o: TurnOutcomeDTO): boolean {
        return (o.opponentMoves?.length ?? 0) > 0 || !!o.opponentSonar;
    }

    /** Ruch AI tymi samymi animacjami na planszy gracza: sonar, potem atak. */
    async function playOpponent(tok: FxToken, board: FxBoard, o: TurnOutcomeDTO, outcome: FxOutcome) {
        const sonar = o.opponentSonar;
        if (sonar) await playSonar(tok, board, [sonar.x, sonar.y], sonar.radius, sonar.results);
        const moves = o.opponentMoves ?? [];
        if (moves.length === 0) return;
        if (o.opponentTorpedoLaunch) {
            const step: Cell = moves.length > 1
                ? [Math.sign(moves[1].x - moves[0].x), Math.sign(moves[1].y - moves[0].y)]
                : [1, 0];
            await playTorpedo(tok, board, moves, step, outcome);
        } else if (moves.length > 1) {
            await playAirstrike(tok, board, moves, outcome);
        } else {
            await playShot(tok, board, moves[0], outcome);
        }
    }

    /**
     * Animacja ruchu przed pokazaniem wyniku: wynik jest już znany z API,
     * animacja tylko opóźnia jego pokazanie. Najpierw broń gracza na wodach
     * wroga, potem ruch AI na planszy gracza. Przerwanie (skipFx, wyjście
     * z ekranu) kończy sekwencję od razu — stan zapisuje potem `commit`.
     */
    async function animateTurn(g: GameViewDTO, playerFx: PlayerFx | null, o: TurnOutcomeDTO | null) {
        if (fxMode() === 'off') return;
        const tok = fx.begin();
        animating.value = true;
        try {
            const enemy = enemyBoard.value?.fxBoard();
            if (playerFx && enemy) await playerFx(tok, enemy, enemyOutcome(g));
            const own = ownBoard.value?.fxBoard();
            if (o && own && hasOpponentAction(o)) {
                // przy prefers-reduced-motion bez pauzy: wynik od razu
                if (playerFx && fxMode() === 'full') await tok.wait(350);
                opponentAnimating.value = true;
                await playOpponent(tok, own, o, ownOutcome(g));
            }
        } catch (e) {
            if (!isCancelled(e)) {
                try { console.warn('[useGame.fx]', e); } catch {}
            }
        } finally {
            fx.end(tok);
            animating.value = false;
            opponentAnimating.value = false;
        }
    }

    /** Ponowne kliknięcie / Esc w trakcie animacji: od razu wynik. */
    function skipFx() {
        fx.cancel();
    }

    /** Znaczniki efektów schodzą dopiero, gdy plansza pokazuje już nowy stan. */
    async function clearFx() {
        await nextTick();
        enemyBoard.value?.fxBoard()?.clear();
        ownBoard.value?.fxBoard()?.clear();
    }

    async function start() {
        loading.value = true;
        error.value = '';
        try {
            if (!gameId.value) {
                throw new Error('Brak identyfikatora gry w adresie URL');
            }
            const g = await getGame(gameId.value);
            applyProjection(g);
            // obrona wyspy (#78): napastnik strzela pierwszy, zanim gracz zobaczy planszę
            const attackerFirst = g.shotsCount === 0
                && (g.playerUnderFireGrid?.hits.length ?? 0) + (g.playerUnderFireGrid?.misses.length ?? 0) > 0;
            pushLog('INFO', g.shotsCount > 0
                ? `Wczytano trwającą bitwę — oddano już ${g.shotsCount} strzałów.`
                : attackerFirst
                    ? 'Napastnik otworzył ogień pierwszy — teraz Twoja salwa.'
                    : g.fort
                        ? `Flota pod fortem. Zniszcz ${g.fort.guns.total} działa nadbrzeżne — mury tylko pochłaniają strzały, magazyn prochu wybucha.`
                        : 'Flota na pozycjach. Wróg czeka we mgle — oddaj pierwszą salwę.');
            if (g.shotsCount === 0 && (g.fort?.spotted.length ?? 0) > 0) {
                const [gun] = g.fort!.spotted;
                pushLog('TY', `Lunety artyleryjskie namierzyły działo fortu na ${cellLabel(gun.x, gun.y)} — zestrzel je.`);
            }
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
        await commit(await getGame(gameId.value));
    }

    /** Pokazuje projekcję po ruchu (po animacji) i dopisuje zatopienia do dziennika. */
    async function commit(g: GameViewDTO) {
        const enemyBefore = enemySunkLengths.value.length;
        const ownBefore = playerSunkLengths.value.length;
        const fortBefore = fort.value;
        applyProjection(g);
        if (fort.value) {
            logFort(fortBefore, fort.value);
        } else {
            for (const l of enemySunkLengths.value.slice(enemyBefore)) {
                pushLog('TY', `Zatopiono jednostkę wroga: ${shipName(l).toLowerCase()} (${fieldsLabel(l)}).`);
            }
        }
        const ownSunk = playerSunkLengths.value;
        if (ownSunk.length > ownBefore) {
            pushLog('WRÓG', `Stracono: ${ownSunk.slice(ownBefore).map(l => shipName(l).toLowerCase()).join(', ')}.`);
        }
        await clearFx();
    }

    /** Dziennik oblężenia: eksplozja magazynu, odsłonięte mury, zniszczone działa. */
    function logFort(before: FortView | null, now: FortView) {
        if (!before) return;
        if (now.explosion && !before.explosion) {
            const side = 2 * (now.blastRadius ?? 1) + 1;
            pushLog('TY', `Magazyn prochu wybuchł! Eksplozja trafiła pola ${side}×${side} wokół niego.`);
        }
        const seen = new Set(before.revealed.map(c => `${c.x}:${c.y}`));
        const walls = now.revealed.filter(c => c.kind === 'wall' && !seen.has(`${c.x}:${c.y}`)).length;
        if (walls > 0) pushLog('TY', `Mur pochłonął ${walls === 1 ? 'trafienie' : `${walls} trafienia`} — nie liczy się do zwycięstwa.`);
        const destroyed = before.guns.left - now.guns.left;
        if (destroyed > 0) {
            pushLog('TY', now.guns.left === 0
                ? 'Ostatnie działo zniszczone — fort zamilkł!'
                : `${destroyed === 1 ? 'Działo zniszczone' : `Zniszczone działa: ${destroyed}`} · fortowi zostało ${now.guns.left}/${now.guns.total}.`);
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
            const g = await getGame(gameId.value);
            const result = res.result;
            await animateTurn(g, result === 'duplicate' ? null : (tok, b, out) => playShot(tok, b, { x, y, result }, out), res);
            if (res.result === 'duplicate') {
                duplicates.value += 1;
                showToast('Duplikat: to pole było już ostrzelane.', 'warn', 2200);
            } else if (res.result !== 'sunk') {
                pushLog('TY', `Salwa w ${cellLabel(x, y)} — ${describeResult(res.result)}`);
            }
            // Tura/koniec gry + ewentualne ujawnienie wyrzutni torpedy AI
            // (AI może odpalić torpedę także w odpowiedzi na zwykły strzał!)
            applyTurnOutcome(res);
            // pełna projekcja (pobrana przed animacją) synchronizuje mgłę i stany
            await commit(g);
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
            const direction = torpedoDirection.value;
            const res = await fireTorpedo(gameId.value, x, y, direction);
            const g = await getGame(gameId.value);
            await animateTurn(g, (tok, b, out) => playTorpedo(tok, b, res.results, TORPEDO_STEPS[direction], out), res);
            const hits = res.results.filter(c => c.result === 'hit' || c.result === 'sunk').length;
            pushLog('TY', `Torpeda z ${cellLabel(x, y)} ${TORPEDO_ARROWS[direction]} — ${fieldsLabel(res.results.length)}, trafienia: ${hits}`);
            applyTurnOutcome(res);
            await commit(g);
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
            const g = await getGame(gameId.value); // licznik użyć
            await animateTurn(g, (tok, b) => playSonar(tok, b, [x, y], res.radius, res.results), null);
            const merged = new Map(sonarMarks.value.map(c => [`${c.x}:${c.y}`, c]));
            res.results.forEach(c => merged.set(`${c.x}:${c.y}`, c));
            sonarMarks.value = [...merged.values()];
            if (res.energy !== undefined) energy.value = res.energy;
            const found = res.results.filter(c => c.occupied).length;
            pushLog('SONAR', found > 0
                ? `Skan wokół ${cellLabel(x, y)}: echo kadłuba na ${found} ${found === 1 ? 'polu' : 'polach'}. Sonar nie kończy tury.`
                : `Skan wokół ${cellLabel(x, y)}: czysta woda. Sonar nie kończy tury.`);
            await commit(g);
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
            const g = await getGame(gameId.value);
            await animateTurn(g, (tok, b, out) => playAirstrike(tok, b, res.results, out), res);
            const hits = res.results.filter(c => c.result === 'hit' || c.result === 'sunk').length;
            pushLog('TY', `Nalot na ${cellLabel(x, y)} (3×3) — trafienia: ${hits}`);
            applyTurnOutcome(res);
            await commit(g);
        } catch (e: any) {
            showToast(e?.message ?? 'Nalot się nie powiódł', 'warn', 2500);
        } finally {
            shooting.value = false;
            weaponMode.value = 'shot';
        }
    }

    /** „Zakończ turę” (#59): oddaj turę bez ataku — AI odpowiada, energia wraca. */
    async function endTurn() {
        if (!canAct() || !energy.value) return;
        try {
            shooting.value = true;
            const res = await apiEndTurn(gameId.value);
            const g = await getGame(gameId.value);
            pushLog('TY', 'Koniec tury bez ataku.');
            await animateTurn(g, null, res);
            applyTurnOutcome(res);
            await commit(g);
        } catch (e: any) {
            showToast(e?.message ?? 'Nie udało się zakończyć tury', 'warn', 2500);
        } finally {
            shooting.value = false;
            weaponMode.value = 'shot';
        }
    }

    /**
     * Ruch statku o 1 pole (#60): statek wskazany dowolnym polem, 1⚡, nie kończy
     * tury. Zwraca true po udanym ruchu (widok zamyka wtedy tryb ruchu).
     */
    async function moveShip(x: number, y: number, direction: MoveDirection): Promise<boolean> {
        if (!canAct() || !energy.value) return false;
        const ship = playerFleet.value.find(s => shipCellsOf(s).some(([cx, cy]) => cx === x && cy === y));
        try {
            shooting.value = true;
            const res = await apiMoveShip(gameId.value, x, y, direction);
            energy.value = res.energy;
            moveUsed.value = res.moveUsed;
            const name = ship ? shipName(ship.l, ship.w) : 'Statek';
            pushLog('TY', `Manewr: ${name.toLowerCase()} ${TORPEDO_ARROWS[direction]} ${cellLabel(res.ship.x, res.ship.y)}. Ruch nie kończy tury.`);
            await refresh();
            return true;
        } catch (e: any) {
            showToast(MOVE_ERRORS[e?.details?.error?.code] ?? e?.message ?? 'Ruch się nie powiódł', 'warn', 2500);
            return false;
        } finally {
            shooting.value = false;
        }
    }

    /**
     * Abordaż ostatniego statku wroga (#118): rzut k6, ⚡3, jedna próba na bitwę.
     * Sukces kończy bitwę wygraną; porażka oddaje turę wrogowi.
     */
    async function boardShip() {
        if (!canAct() || !boarding.value?.available) return;
        try {
            shooting.value = true;
            const res = await apiBoardShip(gameId.value);
            const g = await getGame(gameId.value);
            // abordaż bez animacji (#145 p. 7); odpowiedź AI po odpartym abordażu — tak
            await animateTurn(g, null, res);
            const b = res.boarding;
            pushLog('TY', `Abordaż: k6 = ${b.roll} (próg ${b.threshold}+) — ${b.success ? 'statek wroga zdobyty!' : 'załoga odparta, tura stracona.'}`);
            showToast(b.success ? '⚓ Abordaż udany — statek jest twój!' : 'Abordaż odparty.', b.success ? 'info' : 'warn', 3000);
            applyTurnOutcome(res);
            await commit(g);
        } catch (e: any) {
            showToast(e?.message ?? 'Abordaż się nie powiódł', 'warn', 2500);
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
    // wyjście z ekranu przerywa animację; zapis stanu w toku kończy się bez efektów
    onBeforeUnmount(() => fx.cancel());

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
        loading, shooting, error, start, refresh, shot, attack, endTurn, moveShip, moveUsed, boardShip, boarding, disabled,
        // tryb fun
        ruleset, energy, weapons, opponentWeapons, weaponMode, torpedoDirection, sonarMarks, launchableCells, aliveCarriers,
        // notatki (saper-style)
        noteMarks, toggleNote,
        // oblężenie fortu (#119)
        fort,
        // stats
        shotsCount, shotsTotal, hitsCount, missesCount, duplicatesCount, opponentHitsCount,
        enemySunkLengths, playerSunkLengths, enemyTotal, playerTotal, allowedShips, opponentShips, opponentVariants, playerFleet,
        // dziennik i toast
        log, toast, toastType,
        sunkCells,
        // efekty (#145)
        enemyBoard, ownBoard, animating, opponentAnimating, skipFx,
    };
}
