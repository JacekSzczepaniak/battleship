<script setup lang="ts">
import GameGameBoard from './GameBoard.vue';
import { useGame } from '../composables/useGame';
import { computed, unref } from 'vue'
import { useRouter } from 'vue-router'

const g = useGame();
const router = useRouter();

// Połącz siatkę gracza z overlayem strzałów przeciwnika (opp-hit/opp-miss)
const mergedPlayerGrid = computed(() => {
    const base = unref(g.playerGrid) as unknown as string[][];
    const ov = unref(g.playerUnderFireOverlay) as unknown as (('none'|'opp-hit'|'opp-miss')[])[];
    if (!Array.isArray(base) || !Array.isArray(ov) || base.length !== ov.length) return base as any;
    return base.map((row, y) => row.map((cell, x) => {
        const o = ov?.[y]?.[x] ?? 'none';
        if (o === 'none') return cell as string;
        // złącz klasy, aby widoczny był statek i overlay
        return `${cell} ${o}`.trim();
    }));
});

const enemyAnimatedGrid = computed(() => {
    const base = unref(g.enemyFogGrid) as unknown as string[][];
    const ls = unref(g.lastShot) as { x: number; y: number; result: string } | null;
    const sunk = unref(g.sunkCells) as Array<[number, number]> | null | undefined;

    if (!Array.isArray(base)) return base as any;

    return base.map((row, y) => row.map((cell, x) => {
        let classes = cell as string;

        // Zatopione statki – stała klasa 'sink'
        if (Array.isArray(sunk) && sunk.some(([sx, sy]) => sx === x && sy === y)) {
            classes += ' sink';
        }

        // Ostatni strzał – krótkotrwała animacja
        if (ls && ls.x === x && ls.y === y) {
            if (ls.result === 'hit') {
                classes += ' hit anim';
            } else if (ls.result === 'miss') {
                classes += ' miss anim';
            }
        }

        return classes.trim();
    }));
});



function handleShot(x: number, y: number) {
    // Uwaga: w <script setup> wartości z composable nie są auto‑odwijane poza template
    // więc g.disabled jest Ref<boolean>. Sprawdźmy .value.
    if (g.disabled && 'value' in g.disabled) {
        if (!g.disabled.value) g.shot(x, y);
    } else {
        // fallback – jeśli z jakiegoś powodu disabled nie jest Refem
        // to i tak spróbujemy oddać strzał
        g.shot(x, y);
    }
}

function newGame() {
    router.push({ name: 'home' })
}
</script>

<template>
    <!-- Banner końca gry u góry sekcji -->
    <div v-if="g.finished || g.status === 'won' || g.status === 'lost'" class="game-banner" :class="g.status">
        Koniec gry: {{ g.status === 'won' ? 'Wygrana' : 'Przegrana' }}
        <button class="btn small" @click="newGame">Nowa gra</button>
    </div>

    <div class="boards">
        <div>
            <h3>Twoja plansza</h3>
            <!-- Pozostawiamy aktywną (niezablokowaną), aby overlay był w pełni czytelny -->
            <GameGameBoard :grid="mergedPlayerGrid" :disabled="false" />
        </div>

        <div>
            <h3>Przeciwnik</h3>
            <GameGameBoard :grid="enemyAnimatedGrid" :onCellClick="handleShot" :disabled="g.disabled" />
            <div v-if="g.loading">Ładowanie…</div>
            <div v-if="g.error" style="color:crimson">{{ g.error }}</div>
            <div class="hud">
                <div>Ruch: <strong>{{ g.turn }}</strong> <span v-if="g.disabled">(zablokowane)</span></div>
                <div class="stats">
                    <span class="pill">Strzały: <strong>{{ g.shotsCount }}</strong></span>
                    <span class="pill hit">Trafienia: <strong>{{ g.hitsCount }}</strong></span>
                    <span class="pill miss">Pudła: <strong>{{ g.missesCount }}</strong></span>
                    <span class="pill dup">Duplikaty: <strong>{{ g.duplicatesCount }}</strong></span>
                    <span class="pill opp">Trafienia przeciwnika: <strong>{{ g.opponentHitsCount }}</strong></span>
                </div>
                <div class="legend">
                    <span class="item"><span class="box ship"></span> Statek (Twoja plansza)</span>
                    <span class="item"><span class="box hit"></span> Trafienie</span>
                    <span class="item"><span class="box miss"></span> Pudło</span>
                    <span class="item"><span class="box opp-hit"></span> Trafienie przeciwnika</span>
                    <span class="item"><span class="box opp-miss"></span> Pudło przeciwnika</span>
                </div>
                <div v-if="g.toast" class="toast" :class="g.toastType">{{ g.toast }}</div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.game-banner {
  position: sticky; top: 0; z-index: 2;
  margin-bottom: .75rem; padding: .5rem .75rem;
  border-radius: 6px; font-weight: 600;
  border: 1px solid transparent;
}
.game-banner.won { background: #e6ffe6; border-color: #b3ffb3; color: #0a5b0a; }
.game-banner.lost { background: #ffe6e6; border-color: #ffb3b3; color: #7a0a0a; }
.btn.small { margin-left: .75rem; padding: .25rem .5rem; font-size: .9rem; background: #1f6feb; color: #fff; border: 0; border-radius: 4px; cursor: pointer; }
.boards { display: flex; gap: 20px; align-items: start; }
.hud { margin-top: .5rem; display: grid; gap: .4rem; }
.stats { display: flex; flex-wrap: wrap; gap: .4rem; }
.pill { display: inline-block; padding: .15rem .5rem; border-radius: 999px; background: #eef2ff; color: #1e293b; }
.pill.hit { background: #fee2e2; color: #7a0a0a; }
.pill.miss { background: #e0f2fe; color: #0c4a6e; }
.pill.dup { background: #f1f5f9; color: #334155; }
.pill.opp { background: #fde68a; color: #7c2d12; }
.legend { display:flex; flex-wrap:wrap; gap:.6rem; align-items:center; margin-top:.25rem; color:#334155; }
.legend .item { display:flex; gap:.35rem; align-items:center; }
.legend .box { width:16px; height:16px; border:1px solid #cbd5e1; border-radius:2px; display:inline-block; }
.legend .box.ship { background:#94a3b8; }
.legend .box.hit { background:#ef4444; border-color:#ef4444; }
.legend .box.miss { background:#bfdbfe; border-color:#bfdbfe; }
.legend .box.opp-hit { background: transparent; border-color:#7a0a0a; box-shadow: inset 0 0 0 2px #7a0a0a; }
.legend .box.opp-miss { background: transparent; border-color:#0c4a6e; box-shadow: inset 0 0 0 2px #0c4a6e; }
.toast { display:inline-block; padding:.3rem .6rem; border-radius:6px; border:1px solid #cbd5e1; background:#f8fafc; color:#0f172a; }
.toast.warn { background:#fff7ed; border-color:#fed7aa; color:#7c2d12; }
.toast.error { background:#fee2e2; border-color:#fecaca; color:#7f1d1d; }
</style>
