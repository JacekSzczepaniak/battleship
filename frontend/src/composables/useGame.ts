import { ref, onMounted, computed } from 'vue';
import { useRoute } from 'vue-router';
import {
    getGame, fireShot, fireTorpedo, sonarPing, sendAirRaid,
    type GameViewDTO, type ShotResultDTO, type RulesetName, type WeaponsState,
    type TorpedoDirection, type TurnOutcomeDTO, type SonarCell,
} from '../api/gameApi.ts';

export type WeaponMode = 'shot' | 'torpedo' | 'sonar' | 'airraid';

export type PlayerCell = 'empty'|'ship'|'hit'|'miss';
export type EnemyCell = 'empty'|'hit'|'miss';
export type OverlayCell = 'none'|'opp-hit'|'opp-miss';

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
    // NOWE:
    const lastShot = ref<{ x: number; y: number; result: ShotResultDTO['result'] } | null>(null);
    const sunkCells = ref<Array<[number, number]>>([]);

    // Tryb fun: bronie specjalne
    const ruleset = ref<RulesetName>('classic');
    const weapons = ref<WeaponsState | null>(null);
    const weaponMode = ref<WeaponMode>('shot');
    const torpedoDirection = ref<TorpedoDirection>('E');
    // Wyniki sonaru — tylko po stronie klienta (backend nie zapisuje skanów)
    const sonarMarks = ref<SonarCell[]>([]);


    function buildEmptyGrid<T extends string>(w: number, h: number, fill: T): T[][] {
        return Array.from({ length: h }, () => Array.from({ length: w }, () => fill));
    }

    function applyProjection(dto: GameViewDTO) {
        width.value = dto.board.w;
        height.value = dto.board.h;
        // player grid with ships
        const pg = buildEmptyGrid<PlayerCell>(dto.board.w, dto.board.h, 'empty');
        for (const s of dto.playerFleet) {
            const o = (s.o as unknown as string).toLowerCase() as 'h'|'v';
            for (let i = 0; i < s.l; i++) {
                const x = s.x + (o === 'h' ? i : 0);
                const y = s.y + (o === 'v' ? i : 0);
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


        turn.value = dto.turn;
        status.value = dto.status;
        finished.value = dto.finished;
        ruleset.value = dto.ruleset ?? 'classic';
        weapons.value = dto.weapons ?? null;
    }

    function applyTurnOutcome(o: TurnOutcomeDTO) {
        turn.value = o.turn;
        if (o.finished) {
            status.value = o.win ? 'won' : (o.loss ? 'lost' : status.value);
            finished.value = true;
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
        } catch (e: any) {
            error.value = e?.message ?? 'Start failed';
            try { console.warn('[useGame.start] ERROR', e); } catch {}
        } finally {
            loading.value = false;
        }
    }

    async function refresh() {
        if (!gameId.value) return;
        const g = await getGame(gameId.value);
        applyProjection(g);
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
            // Zastosuj zmianę na siatce przeciwnika lokalnie (opcj.)
            if (enemyFogGrid.value[y] && typeof enemyFogGrid.value[y][x] !== 'undefined') {
                enemyFogGrid.value[y][x] = res.result === 'miss' ? 'miss' : 'hit';
            }
            if (res.result === 'hit' || res.result === 'miss') {
                lastShot.value = { x, y, result: res.result };

                // Po krótkim czasie wyczyść, żeby animacja była jednorazowa
                setTimeout(() => {
                    if (
                        lastShot.value &&
                        lastShot.value.x === x &&
                        lastShot.value.y === y &&
                        lastShot.value.result === res.result
                    ) {
                        lastShot.value = null;
                    }
                }, 400);
            }
            if (res.result === 'duplicate') {
                duplicates.value += 1;
                showToast('Duplikat: to pole było już ostrzelane.', 'warn', 2200);
            }
            // Ruch przeciwnika można wizualizować na osobnej planszy (na MVP pomijamy)
            turn.value = res.turn;
            if (res.finished) {
                status.value = res.win ? 'won' : (res.loss ? 'lost' : status.value);
                finished.value = true;
            }
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
                lastShot.value = { x: cell.x, y: cell.y, result: cell.result };
                await sleep(90);
            }
            lastShot.value = null;
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
    const shotsCount = computed(() => {
        // liczba strzałów z projekcji (liczone po stronie backendu)
        // uwzględnia wszystkie wyniki (hit/miss/sunk/duplicate)
        return Math.max(
            // spróbuj policzyć z bieżącej mgły, gdyby projekcja była opóźniona
            enemyFogGrid.value.flat().filter(c => c === 'hit' || c === 'miss').length,
            // fallback do ostatniej znanej wartości z projekcji (z logów)
            // realna wartość wróci po refresh()
            0
        );
    });
    const hitsCount = computed(() => enemyFogGrid.value.flat().filter(c => c === 'hit').length);
    const missesCount = computed(() => enemyFogGrid.value.flat().filter(c => c === 'miss').length);
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
        loading, error, start, refresh, shot, attack, disabled,
        // tryb fun
        ruleset, weapons, weaponMode, torpedoDirection, sonarMarks,
        // stats
        shotsCount, hitsCount, missesCount, duplicatesCount, opponentHitsCount,
        // toast
        toast, toastType,
        lastShot, sunkCells,
    };
}
