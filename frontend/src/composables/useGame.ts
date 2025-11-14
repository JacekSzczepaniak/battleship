import { ref, onMounted } from 'vue';
import { createGame, getGame, fireShot, type GameDTO, type ShotResultDTO } from '../api/gameApi.ts';

export function useGame() {
    const gameId = ref<string>('');
    const size = ref<number>(10);
    const playerGrid = ref<GameDTO['playerGrid']>([]);
    const enemyFogGrid = ref<GameDTO['enemyFogGrid']>([]);
    const turn = ref<GameDTO['turn']>('player');
    const status = ref<GameDTO['status']>('ongoing');
    const loading = ref<boolean>(false);
    const error = ref<string>('');

    async function start() {
        loading.value = true;
        error.value = '';
        try {
            const g = await createGame();
            gameId.value = g.id;
            size.value = g.size;
            playerGrid.value = g.playerGrid;
            enemyFogGrid.value = g.enemyFogGrid;
            turn.value = g.turn;
            status.value = g.status;
        } catch (e: any) {
            error.value = e?.message ?? 'Start failed';
        } finally {
            loading.value = false;
        }
    }

    async function refresh() {
        if (!gameId.value) return;
        const g = await getGame(gameId.value);
        playerGrid.value = g.playerGrid;
        enemyFogGrid.value = g.enemyFogGrid;
        turn.value = g.turn;
        status.value = g.status;
    }

    async function shot(x: number, y: number) {
        if (!gameId.value || status.value !== 'ongoing' || turn.value !== 'player') return;

        try {
            const res: ShotResultDTO = await fireShot(gameId.value, x, y);
            // zaktualizuj mgłę jeśli backend ją zwraca
            if (res.enemyFogGrid) {
                enemyFogGrid.value = res.enemyFogGrid;
            } else {
                // fallback – zaznacz trafienie/pudło lokalnie
                enemyFogGrid.value[y][x] = res.hit ? 'hit' : 'miss';
            }
            turn.value = res.turn;
            if (res.finished) status.value = res.finished ? 'won' : status.value;
            // (opcjonalnie odpal ruch AI przez osobny endpoint/SSE)
        } catch (e: any) {
            error.value = e?.message ?? 'Shot failed';
        }
    }

    onMounted(start);

    return { gameId, size, playerGrid, enemyFogGrid, turn, status, loading, error, start, refresh, shot };
}
