<script setup lang="ts">
import GameGameBoard from './GameBoard.vue';
import { useGame } from '../composables/useGame';
import { computed } from 'vue'
import { useRouter } from 'vue-router'

// Destrukturyzacja: ref-y stają się top-level, więc template odpakowuje je
// automatycznie (zagnieżdżone w zwykłym obiekcie NIE są odpakowywane).
const {
    status, finished, turn, loading, error, disabled, attack,
    ruleset, weapons, weaponMode, torpedoDirection, sonarMarks,
    shotsCount, hitsCount, missesCount, duplicatesCount, opponentHitsCount,
    toast, toastType,
    playerGrid, playerUnderFireOverlay, enemyFogGrid, lastShot, sunkCells,
} = useGame();
const router = useRouter();

// Połącz siatkę gracza z overlayem strzałów przeciwnika (opp-hit/opp-miss)
const mergedPlayerGrid = computed<string[][]>(() => {
    const base = playerGrid.value;
    const ov = playerUnderFireOverlay.value;
    if (base.length !== ov.length) return base;
    return base.map((row, y) => row.map((cell, x) => {
        const o = ov[y]?.[x] ?? 'none';
        if (o === 'none') return cell as string;
        // złącz klasy, aby widoczny był statek i overlay
        return `${cell} ${o}`.trim();
    }));
});

const enemyAnimatedGrid = computed<string[][]>(() => {
    const ls = lastShot.value;
    const sunk = sunkCells.value;
    const sonar = new Map(sonarMarks.value.map(c => [`${c.x}:${c.y}`, c.occupied]));

    return enemyFogGrid.value.map((row, y) => row.map((cell, x) => {
        let classes: string = cell;

        // Skan sonaru — tylko na polach jeszcze nieostrzelanych
        if (cell === 'empty' && sonar.has(`${x}:${y}`)) {
            classes += sonar.get(`${x}:${y}`) ? ' sonar-ship' : ' sonar-water';
        }

        // Zatopione statki – stała klasa 'sink'
        if (sunk.some(([sx, sy]) => sx === x && sy === y)) {
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
    if (!disabled.value) {
        attack(x, y);
    }
}

function selectWeapon(mode: 'shot' | 'torpedo' | 'sonar' | 'airraid') {
    weaponMode.value = mode;
}

const weaponHint = computed(() => {
    switch (weaponMode.value) {
        case 'torpedo': return 'Kliknij pole startowe torpedy — popłynie w wybranym kierunku do krawędzi.';
        case 'sonar': return 'Kliknij centrum skanu — sonar sprawdzi krzyż o promieniu 3.';
        case 'airraid': return 'Kliknij centrum nalotu — ostrzał obszaru 3×3.';
        default: return '';
    }
});

function newGame() {
    router.push({ name: 'home' })
}
</script>

<template>
    <!-- Banner końca gry u góry sekcji -->
    <div v-if="finished || status === 'won' || status === 'lost'" class="game-banner" :class="status">
        Koniec gry: {{ status === 'won' ? 'Wygrana' : 'Przegrana' }}
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
            <GameGameBoard :grid="enemyAnimatedGrid" :onCellClick="handleShot" :disabled="disabled" />

            <!-- Panel broni specjalnych (tylko tryb fun) -->
            <div v-if="ruleset === 'fun' && weapons" class="weapons">
                <button class="wbtn" :class="{ active: weaponMode === 'shot' }" @click="selectWeapon('shot')">
                    🎯 Strzał
                </button>
                <button class="wbtn" :class="{ active: weaponMode === 'torpedo' }"
                        :disabled="weapons.torpedo.used >= weapons.torpedo.limit"
                        @click="selectWeapon('torpedo')">
                    🚀 Torpeda {{ weapons.torpedo.limit - weapons.torpedo.used }}/{{ weapons.torpedo.limit }}
                </button>
                <button class="wbtn" :class="{ active: weaponMode === 'sonar' }"
                        :disabled="weapons.sonar.used >= weapons.sonar.limit"
                        @click="selectWeapon('sonar')">
                    📡 Sonar {{ weapons.sonar.limit - weapons.sonar.used }}/{{ weapons.sonar.limit }}
                </button>
                <button class="wbtn" :class="{ active: weaponMode === 'airraid' }"
                        :disabled="weapons.airRaid.used >= weapons.airRaid.limit"
                        @click="selectWeapon('airraid')">
                    ✈️ Nalot {{ weapons.airRaid.limit - weapons.airRaid.used }}/{{ weapons.airRaid.limit }}
                </button>
            </div>
            <div v-if="ruleset === 'fun' && weaponMode === 'torpedo'" class="weapon-opts">
                Kierunek:
                <label><input type="radio" value="N" v-model="torpedoDirection" /> ↑ N</label>
                <label><input type="radio" value="E" v-model="torpedoDirection" /> → E</label>
                <label><input type="radio" value="S" v-model="torpedoDirection" /> ↓ S</label>
                <label><input type="radio" value="W" v-model="torpedoDirection" /> ← W</label>
            </div>
            <div v-if="weaponHint" class="weapon-hint">{{ weaponHint }}</div>

            <div v-if="loading">Ładowanie…</div>
            <div v-if="error" style="color:crimson">{{ error }}</div>
            <div class="hud">
                <div>Ruch: <strong>{{ turn }}</strong> <span v-if="disabled">(zablokowane)</span></div>
                <div class="stats">
                    <span class="pill">Strzały: <strong>{{ shotsCount }}</strong></span>
                    <span class="pill hit">Trafienia: <strong>{{ hitsCount }}</strong></span>
                    <span class="pill miss">Pudła: <strong>{{ missesCount }}</strong></span>
                    <span class="pill dup">Duplikaty: <strong>{{ duplicatesCount }}</strong></span>
                    <span class="pill opp">Trafienia przeciwnika: <strong>{{ opponentHitsCount }}</strong></span>
                </div>
                <div class="legend">
                    <span class="item"><span class="box ship"></span> Statek (Twoja plansza)</span>
                    <span class="item"><span class="box hit"></span> Trafienie</span>
                    <span class="item"><span class="box miss"></span> Pudło</span>
                    <span class="item"><span class="box opp-hit"></span> Trafienie przeciwnika</span>
                    <span class="item"><span class="box opp-miss"></span> Pudło przeciwnika</span>
                    <template v-if="ruleset === 'fun'">
                        <span class="item"><span class="box sonar-ship"></span> Sonar: statek</span>
                        <span class="item"><span class="box sonar-water"></span> Sonar: woda</span>
                    </template>
                </div>
                <div v-if="toast" class="toast" :class="toastType">{{ toast }}</div>
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
.legend .box.sonar-ship { background: #fef3c7; border-color:#d97706; box-shadow: inset 0 0 0 2px #d97706; }
.legend .box.sonar-water { background: #f0f9ff; border-color:#7dd3fc; }
.weapons { display:flex; gap:.4rem; margin-top:.6rem; flex-wrap:wrap; }
.wbtn { padding:.3rem .6rem; border:1px solid #cbd5e1; border-radius:6px; background:#f8fafc; cursor:pointer; }
.wbtn.active { background:#1f6feb; color:#fff; border-color:#1f6feb; }
.wbtn[disabled] { opacity:.45; cursor:not-allowed; }
.weapon-opts { margin-top:.35rem; display:flex; gap:.6rem; align-items:center; color:#334155; }
.weapon-hint { margin-top:.3rem; font-size:.9rem; color:#475569; }
.toast { display:inline-block; padding:.3rem .6rem; border-radius:6px; border:1px solid #cbd5e1; background:#f8fafc; color:#0f172a; }
.toast.warn { background:#fff7ed; border-color:#fed7aa; color:#7c2d12; }
.toast.error { background:#fee2e2; border-color:#fecaca; color:#7f1d1d; }
</style>
