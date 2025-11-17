import { ref, onMounted, computed } from 'vue';
import { useRoute } from 'vue-router';
import { getGame, fireShot, type GameViewDTO, type ShotResultDTO } from '../api/gameApi.ts';

export function useGame() {
    const route = useRoute();
    const gameId = ref<string>(String(route.params.id ?? ''));
    const width = ref<number>(10);
    const height = ref<number>(10);
    const playerGrid = ref<Array<Array<'empty'|'ship'|'hit'|'miss'>>>([]);
    // Overlay trafień/pudeł przeciwnika na planszy gracza
    const playerUnderFireOverlay = ref<Array<Array<'none'|'opp-hit'|'opp-miss'>>>([]);
    const enemyFogGrid = ref<Array<Array<'empty'|'hit'|'miss'>>>([]);
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


    function buildEmptyGrid(w: number, h: number, fill: 'empty'|'miss'|'hit'|'ship' = 'empty') {
        return Array.from({ length: h }, () => Array.from({ length: w }, () => fill));
    }

    function applyProjection(dto: GameViewDTO) {
        width.value = dto.board.w;
        height.value = dto.board.h;
        // player grid with ships
        const pg = buildEmptyGrid(dto.board.w, dto.board.h, 'empty');
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
        const ov = Array.from({ length: dto.board.h }, () => Array.from({ length: dto.board.w }, () => 'none' as 'none'|'opp-hit'|'opp-miss'));
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
        // const eg = buildEmptyGrid(dto.board.w, dto.board.h, 'empty');
        // for (const [x, y] of dto.enemyFogGrid.hits) {
        //     if (eg[y] && typeof eg[y][x] !== 'undefined') eg[y][x] = 'hit';
        // }
        // for (const [x, y] of dto.enemyFogGrid.misses) {
        //     if (eg[y] && typeof eg[y][x] !== 'undefined') eg[y][x] = 'miss';
        // }
        // for (const sunk of dto.enemyFogGrid.sunk) {
        //     for (const [x, y] of sunk.cells) {
        //         if (eg[y] && typeof eg[y][x] !== 'undefined') eg[y][x] = 'hit';
        //     }
        // }
        // enemyFogGrid.value = eg;

        // enemy fog grid from hits/misses/sunk
        const eg = buildEmptyGrid(dto.board.w, dto.board.h, 'empty');
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

        // Debug: zweryfikuj wymiary i długości wierszy (pomaga przy diagnozie renderu)
        try {
            // eslint-disable-next-line no-console
            console.debug('[useGame] board', dto.board, {
                playerRows: pg.length,
                playerRowLens: pg.map(r => r.length),
                enemyRows: eg.length,
                enemyRowLens: eg.map(r => r.length),
            });
        } catch (_) {
            /* ignore */
        }
    }

    async function start() {
        loading.value = true;
        error.value = '';
        try {
            if (!gameId.value) {
                throw new Error('Brak identyfikatora gry w adresie URL');
            }
            try { console.debug('[useGame.start] gameId=', gameId.value); } catch {}
            const g = await getGame(gameId.value);
            try { console.debug('[useGame.start] getGame →', g); } catch {}
            applyProjection(g);
        } catch (e: any) {
            error.value = e?.message ?? 'Start failed';
            try { console.warn('[useGame.start] ERROR', e); } catch {}
        } finally {
            loading.value = false;
            try { console.debug('[useGame.start] loading=false'); } catch {}
        }
    }

    async function refresh() {
        if (!gameId.value) return;
        try { console.debug('[useGame.refresh] → getGame', gameId.value); } catch {}
        const g = await getGame(gameId.value);
        try { console.debug('[useGame.refresh] ← getGame', g); } catch {}
        applyProjection(g);
    }

    async function shot(x: number, y: number) {
        // Blokady: brak id, nie Twoja tura, już trwa strzał, gra skończona, pole poza zakresem lub już ostrzelane
        if (!gameId.value || turn.value !== 'player' || shooting.value) {
            try { console.debug('[useGame.shot] blocked', { gameId: gameId.value, turn: turn.value, shooting: shooting.value }); } catch {}
            return;
        }
        if (status.value === 'won' || status.value === 'lost') return;
        if (!enemyFogGrid.value[y] || typeof enemyFogGrid.value[y][x] === 'undefined') return;
        if (enemyFogGrid.value[y][x] === 'hit' || enemyFogGrid.value[y][x] === 'miss') return;

        try {
            shooting.value = true;
            try { console.debug('[useGame.shot] → fireShot', { x, y }); } catch {}
            const res: ShotResultDTO = await fireShot(gameId.value, x, y);
            try { console.debug('[useGame.shot] ← fireShot', res); } catch {}
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
            try { console.debug('[useGame.shot] shooting=false'); } catch {}
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
        gameId, size, playerGrid, playerUnderFireOverlay, enemyFogGrid, turn, status, finished,
        loading, error, start, refresh, shot, disabled,
        // stats
        shotsCount, hitsCount, missesCount, duplicatesCount, opponentHitsCount,
        // toast
        toast, toastType,
        lastShot, sunkCells,
    };
}
