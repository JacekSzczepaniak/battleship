<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch, type Component } from 'vue';
import { useRouter } from 'vue-router';
import { Crosshair, Lock, Plane, Radar, Rocket } from 'lucide-vue-next';
import SeaGrid from '../components/SeaGrid.vue';
import { useGame, type WeaponMode } from '../composables/useGame';
import { getExpedition, settleBattle } from '../api/expeditionApi';
import { clearCurrentBattle, getCurrentBattle } from '../lib/expeditionSession';
import { getBattleReport, saveBattleReport, type BattleReport } from '../lib/battleSession';
import { carriedWeapons, fieldsLabel, shipName } from '../lib/ships';
import { attackerMissingShip, battleBonusLabel } from '../lib/counterattack';
import type { TorpedoDirection } from '../api/gameApi';

const {
    gameId, status, finished, turn, loading, shooting, error, disabled, attack, width, height,
    ruleset, weapons, opponentWeapons, weaponMode, torpedoDirection, sonarMarks, launchableCells, aliveCarriers,
    noteMarks, toggleNote,
    shotsCount, hitsCount, opponentHitsCount, enemySunkLengths, playerSunkLengths, enemyTotal, playerTotal,
    allowedShips, opponentShips, opponentVariants, playerFleet,
    log, toast, toastType,
    playerGrid, playerUnderFireOverlay, enemyFogGrid, lastShot, sunkCells,
} = useGame();
const router = useRouter();

const battle = getCurrentBattle();
const expeditionBattle = computed(() => battle !== null && battle.gameId === gameId.value);
const gameOver = computed(() => finished.value || status.value === 'won' || status.value === 'lost');

// bonus pełnej bandery (#79): obrona tej wyspy przy wszystkich wyspach pod banderą
const fullFlagDefense = ref(false);
const bonusLabel = computed(() => battleBonusLabel(attackerMissingShip(allowedShips.value, opponentShips.value)));
onMounted(async () => {
    if (!battle) return;
    try {
        const pending = (await getExpedition(battle.profileId)).counterattack.pending;
        fullFlagDefense.value = !!pending && pending.island === battle.islandId && pending.defenseStarted && pending.fullFlag;
    } catch {
        // bez paska bonusu — sama bitwa działa dalej
    }
});

// --- bronie ---

interface WeaponCard {
    key: WeaponMode;
    hotkey: string;
    name: string;
    icon: Component;
    area: string;
    source: string;
    charges: string;
    locked: string | null;
}

const CARRIER_NAME: Record<Exclude<WeaponMode, 'shot'>, string> = {
    sonar: 'kuter',
    torpedo: 'niszczyciel',
    airraid: 'lotniskowiec',
};

// dopełniacz nazw nośników do podpisu „z kutra”, „ze zwiadowcy” na karcie broni
const CARRIER_GENITIVE: Record<string, string> = {
    kuter: 'kutra',
    niszczyciel: 'niszczyciela',
    lotniskowiec: 'lotniskowca',
    'niszczyciel zwiadowczy': 'zwiadowcy',
};

/** Nazwy własnych statków niosących broń (z rozstawionej floty, z wariantami #99). */
function carrierNames(mode: Exclude<WeaponMode, 'shot'>): string[] {
    const names = new Set<string>();
    for (const s of playerFleet.value) {
        if (carriedWeapons(s.l, s.w).includes(mode)) names.add(shipName(s.l, s.w).toLowerCase());
    }
    return names.size ? [...names] : [CARRIER_NAME[mode]];
}

function carrierSource(mode: Exclude<WeaponMode, 'shot'>): string {
    const from = carrierNames(mode).map(n => CARRIER_GENITIVE[n] ?? n).join(' i ');
    return `${/^(z|s)/.test(from) ? 'ze' : 'z'} ${from}`;
}

function lockReason(mode: Exclude<WeaponMode, 'shot'>): string | null {
    if (ruleset.value !== 'fun' || !weapons.value) return 'tylko w trybie z broniami';
    const state = mode === 'airraid' ? weapons.value.airRaid : weapons.value[mode];
    if (state.limit === 0) return `brak nośnika · ${CARRIER_NAME[mode]}`;
    if (!aliveCarriers.value.has(mode)) {
        const names = carrierNames(mode);
        return names.length > 1 ? 'nośniki zatopione' : `${names[0]} zatopiony`;
    }
    if (state.used >= state.limit) return 'wyczerpane';
    return null;
}

function charges(mode: Exclude<WeaponMode, 'shot'>): string {
    if (ruleset.value !== 'fun' || !weapons.value) return '—';
    const state = mode === 'airraid' ? weapons.value.airRaid : weapons.value[mode];
    return `${state.limit - state.used}/${state.limit}`;
}

// promień skanu z reguł gry (technologie gracza ustala backend przy starcie bitwy)
const sonarRadius = computed(() => weapons.value?.sonar.radius ?? 3);

const weaponCards = computed<WeaponCard[]>(() => [
    { key: 'shot', hotkey: '1', name: 'Salwa', icon: Crosshair, area: 'jedno pole', source: 'każdy okręt', charges: '∞', locked: null },
    { key: 'sonar', hotkey: '2', name: 'Sonar', icon: Radar, area: `krzyż · promień ${sonarRadius.value}`, source: `${carrierSource('sonar')} · nie kończy tury`, charges: charges('sonar'), locked: lockReason('sonar') },
    { key: 'torpedo', hotkey: '3', name: 'Torpeda', icon: Rocket, area: 'linia do krawędzi', source: carrierSource('torpedo'), charges: charges('torpedo'), locked: lockReason('torpedo') },
    { key: 'airraid', hotkey: '4', name: 'Nalot', icon: Plane, area: 'obszar 3×3', source: carrierSource('airraid'), charges: charges('airraid'), locked: lockReason('airraid') },
]);

function selectWeapon(card: WeaponCard) {
    if (card.locked || gameOver.value) return;
    weaponMode.value = card.key;
}

// broń wyczerpana / nośnik zatopiony w trakcie wyboru — wróć do salwy
watch(weaponCards, cards => {
    if (cards.find(c => c.key === weaponMode.value)?.locked) weaponMode.value = 'shot';
});

// Torpeda ukośna: osobny limit (zwiad — nie trafi jednego statku dwa razy)
const diagonalAvailable = computed(() => {
    const d = weapons.value?.torpedoDiagonal;
    return !!d && d.used < d.limit;
});
const DIAGONALS: TorpedoDirection[] = ['NE', 'SE', 'SW', 'NW'];
watch([diagonalAvailable, torpedoDirection], () => {
    if (!diagonalAvailable.value && DIAGONALS.includes(torpedoDirection.value)) {
        torpedoDirection.value = 'E';
    }
});
const ROSE: (TorpedoDirection | null)[] = ['NW', 'N', 'NE', 'W', null, 'E', 'SW', 'S', 'SE'];
const ROSE_ARROWS: Record<TorpedoDirection, string> = {
    N: '↑', NE: '↗', E: '→', SE: '↘', S: '↓', SW: '↙', W: '←', NW: '↖',
};

// --- podgląd zasięgu pod kursorem ---

const hoverCell = ref<{ x: number; y: number } | null>(null);

const previewCells = computed<Set<string>>(() => {
    const set = new Set<string>();
    const hc = hoverCell.value;
    if (!hc || disabled.value) return set;
    const w = width.value;
    const h = height.value;
    const add = (x: number, y: number) => {
        if (x >= 0 && y >= 0 && x < w && y < h) set.add(`${x}:${y}`);
    };

    switch (weaponMode.value) {
        case 'torpedo': {
            if (!launchableCells.value.has(`${hc.x}:${hc.y}`)) break;
            const [dx, dy] = {
                N: [0, -1], NE: [1, -1], E: [1, 0], SE: [1, 1],
                S: [0, 1], SW: [-1, 1], W: [-1, 0], NW: [-1, -1],
            }[torpedoDirection.value];
            let cx = hc.x, cy = hc.y;
            while (cx >= 0 && cy >= 0 && cx < w && cy < h) {
                set.add(`${cx}:${cy}`);
                cx += dx;
                cy += dy;
            }
            break;
        }
        case 'sonar':
            add(hc.x, hc.y);
            for (let i = 1; i <= sonarRadius.value; i++) {
                add(hc.x, hc.y - i);
                add(hc.x + i, hc.y);
                add(hc.x, hc.y + i);
                add(hc.x - i, hc.y);
            }
            break;
        case 'airraid':
            for (let dx = -1; dx <= 1; dx++) {
                for (let dy = -1; dy <= 1; dy++) add(hc.x + dx, hc.y + dy);
            }
            break;
        default:
            add(hc.x, hc.y);
    }
    return set;
});

// --- plansze ---

const ownCells = computed<string[][]>(() => {
    const ov = playerUnderFireOverlay.value;
    const sunk = new Set<string>();
    // pola zatopionych własnych statków: każde pole statku trafione
    const grid = playerGrid.value;
    const visited = new Set<string>();
    for (let y = 0; y < grid.length; y++) {
        for (let x = 0; x < (grid[y]?.length ?? 0); x++) {
            if (grid[y][x] !== 'ship' || visited.has(`${x}:${y}`)) continue;
            // flood po statku (statki się nie stykają, więc spójna składowa = jeden statek)
            const stack: [number, number][] = [[x, y]];
            const cells: [number, number][] = [];
            while (stack.length) {
                const [cx, cy] = stack.pop()!;
                const key = `${cx}:${cy}`;
                if (visited.has(key) || grid[cy]?.[cx] !== 'ship') continue;
                visited.add(key);
                cells.push([cx, cy]);
                stack.push([cx + 1, cy], [cx - 1, cy], [cx, cy + 1], [cx, cy - 1]);
            }
            if (cells.every(([cx, cy]) => ov[cy]?.[cx] === 'opp-hit')) {
                for (const [cx, cy] of cells) sunk.add(`${cx}:${cy}`);
            }
        }
    }

    return grid.map((row, y) => row.map((cell, x) => {
        const key = `${x}:${y}`;
        const o = ov[y]?.[x] ?? 'none';
        let cls = cell === 'ship' ? 'ship' : 'water';
        if (sunk.has(key)) cls = 'sunk';
        else if (o === 'opp-hit') cls += ' hit';
        if (o === 'opp-miss') cls += ' miss';
        if (weaponMode.value === 'torpedo' && launchableCells.value.has(key)) cls += ' launch';
        return cls;
    }));
});

const enemyCells = computed<string[][]>(() => {
    const ls = lastShot.value;
    const sunk = new Set(sunkCells.value.map(([x, y]) => `${x}:${y}`));
    const sonar = new Map(sonarMarks.value.map(c => [`${c.x}:${c.y}`, c.occupied]));

    return enemyFogGrid.value.map((row, y) => row.map((cell, x) => {
        const key = `${x}:${y}`;
        let cls = 'fog';
        if (sunk.has(key)) cls = 'sunk';
        else if (cell === 'hit') cls += ' hit';
        else if (cell === 'miss') cls += ' miss';
        else {
            if (sonar.has(key)) cls += sonar.get(key) ? ' sonar-ship' : ' sonar-water';
            if (noteMarks.value.has(key)) cls += ' note';
        }
        // torpeda: kursor stoi na własnej wyrzutni, tor rysujemy na wodach wroga
        if (previewCells.value.has(key)) cls += ' preview';
        if (ls && ls.x === x && ls.y === y) cls += ' anim';
        return cls;
    }));
});

function onEnemyClick(x: number, y: number) {
    if (disabled.value || weaponMode.value === 'torpedo') return;
    attack(x, y);
}

function onOwnClick(x: number, y: number) {
    if (disabled.value || weaponMode.value !== 'torpedo') return;
    if (!launchableCells.value.has(`${x}:${y}`)) return;
    attack(x, y);
}

function onEnemyHover(x: number, y: number) {
    if (weaponMode.value !== 'torpedo') hoverCell.value = { x, y };
}

function onOwnHover(x: number, y: number) {
    if (weaponMode.value === 'torpedo') hoverCell.value = { x, y };
}

const enemyHint = computed(() => {
    if (gameOver.value) return 'Bitwa rozstrzygnięta.';
    if (turn.value !== 'player') return 'Wróg mierzy…';
    switch (weaponMode.value) {
        case 'torpedo': return 'Torpeda: kliknij podświetlony niszczyciel na SWOICH wodach — popłynie w wybranym kierunku.';
        case 'sonar': return `Sonar: kliknij środek skanu (krzyż o promieniu ${sonarRadius.value}).`;
        case 'airraid': return 'Nalot: kliknij środek obszaru 3×3.';
        default: return 'Kliknij pole, by oddać salwę · PPM — notatka „tu pusto”.';
    }
});

const ownFooter = computed(() => {
    const parts = [`wróg zadał ${opponentHitsCount.value} ${opponentHitsCount.value === 1 ? 'trafienie' : 'trafień'}`];
    if (playerSunkLengths.value.length) {
        parts.push(`stracono: ${playerSunkLengths.value.map(l => shipName(l).toLowerCase()).join(', ')}`);
    }
    return parts.join(' · ');
});

const turnLabel = computed(() => {
    if (status.value === 'won') return 'Zwycięstwo';
    if (status.value === 'lost') return 'Porażka';
    if (shooting.value) return 'Ogień…';
    return turn.value === 'player' ? 'Twój ruch' : 'Ruch wroga';
});

// warianty statków wroga (lustro składu gracza, #54 D6): pokazane obok jego broni
const enemyVariants = computed(() => {
    if (ruleset.value !== 'fun') return [];
    return Object.entries(opponentVariants.value).flatMap(([len, loadouts]) => {
        const counts = new Map<string, { name: string; weapon: string; count: number }>();
        for (const w of loadouts) {
            const name = shipName(Number(len), w);
            const entry = counts.get(name) ?? { name, weapon: carriedWeapons(Number(len), w).join(', '), count: 0 };
            entry.count++;
            counts.set(name, entry);
        }
        return [...counts.values()];
    });
});

const aiArsenal = computed(() => {
    const w = opponentWeapons.value;
    if (ruleset.value !== 'fun' || !w) return [];
    return [
        { icon: Radar, label: 'sonar', left: w.sonar.limit - w.sonar.used, limit: w.sonar.limit },
        { icon: Rocket, label: 'torpeda', left: w.torpedo.limit - w.torpedo.used, limit: w.torpedo.limit },
        { icon: Plane, label: 'nalot', left: w.airRaid.limit - w.airRaid.used, limit: w.airRaid.limit },
    ];
});

// --- klawiatura: 1–4 broń, Esc = salwa ---

function onKey(e: KeyboardEvent) {
    const card = weaponCards.value.find(c => c.hotkey === e.key);
    if (card) {
        e.preventDefault();
        selectWeapon(card);
    } else if (e.key === 'Escape') {
        weaponMode.value = 'shot';
    }
}

onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    if (redirectTimer) clearTimeout(redirectTimer);
});

// --- koniec bitwy: raport + rozliczenie wyprawy → ekran wyniku ---

const finalizing = ref(false);
let redirectTimer: ReturnType<typeof setTimeout> | null = null;

async function finalize() {
    if (finalizing.value || getBattleReport(gameId.value)) return;
    finalizing.value = true;
    const report: BattleReport = {
        gameId: gameId.value,
        outcome: status.value === 'lost' ? 'lost' : 'won',
        ruleset: ruleset.value,
        board: { w: width.value, h: height.value },
        shots: shotsCount.value,
        hits: hitsCount.value,
        enemySunk: [...enemySunkLengths.value],
        enemyTotal: enemyTotal.value,
        playerSunk: [...playerSunkLengths.value],
        expedition: null,
    };

    if (battle && expeditionBattle.value) {
        try {
            const before = await getExpedition(battle.profileId);
            // idempotentne po stronie backendu; przy błędzie rozliczy się
            // przy następnym wejściu do portu (siatka bezpieczeństwa)
            const settle = await settleBattle(battle.profileId, battle.gameId);
            const after = await getExpedition(battle.profileId);
            clearCurrentBattle();
            const next = after.profile.nextRank;
            report.expedition = {
                profileId: battle.profileId,
                islandId: battle.islandId,
                islandName: battle.islandName,
                kind: battle.kind,
                seaName: after.world.sea.name,
                settle,
                xpBefore: before.profile.xp,
                rankBefore: before.profile.rank,
                nextRank: next ? { rank: next.rank, xpTarget: after.profile.xp + next.xpNeeded } : null,
                fleet: after.fleet.map(s => ({ id: s.id, type: s.type, damaged: s.damaged, repairCost: s.repairCost })),
            };
        } catch {
            // wpis w localStorage zostaje — port ponowi rozliczenie
        }
    }

    saveBattleReport(report);
    finalizing.value = false;
    redirectTimer = setTimeout(goToResult, 1800);
}

function goToResult() {
    if (router.currentRoute.value.name === 'game') {
        router.push({ name: 'result', params: { id: gameId.value } });
    }
}

// raport dopiero po odświeżeniu projekcji po ostatnim strzale — inaczej
// ostatni zatopiony statek nie trafiłby do liczników
watch([gameOver, shooting], ([over, busy]) => { if (over && !busy) finalize(); });
</script>

<template>
    <section class="frame battle">
        <!-- HUD -->
        <header class="hud">
            <div class="hud-turn">
                <div class="kicker">{{ expeditionBattle && battle ? `BITWA · ${battle.islandName.toUpperCase()}` : 'BITWA TRENINGOWA' }}</div>
                <div class="turn-line">
                    OSTRZELANO {{ fieldsLabel(shotsCount).toUpperCase() }} · <span :class="{ mine: turn === 'player' && !gameOver }">{{ turnLabel }}</span>
                </div>
            </div>
            <span class="sep"></span>
            <div class="hud-block">
                <div class="kicker">ZATOPIONO</div>
                <div class="hud-val">{{ enemySunkLengths.length }} / {{ enemyTotal }}</div>
            </div>
            <div class="hud-block">
                <div class="kicker">STRATY</div>
                <div class="hud-val">{{ playerSunkLengths.length }} / {{ playerTotal }}</div>
            </div>
            <div v-if="aiArsenal.length" class="hud-right">
                <span class="kicker">ARSENAŁ WROGA</span>
                <span v-for="a in aiArsenal" :key="a.label" class="ai-pill" :class="{ empty: a.left === 0 }" :title="a.label">
                    <component :is="a.icon" :size="13" /> {{ a.left }}/{{ a.limit }}
                </span>
                <span v-for="v in enemyVariants" :key="v.name" class="ai-pill variant" :title="`wariant w składzie wroga · nośnik: ${v.weapon}`">
                    {{ v.name.toLowerCase() }}{{ v.count > 1 ? ` ×${v.count}` : '' }}
                </span>
            </div>
        </header>
        <p v-if="fullFlagDefense && expeditionBattle" class="bonus-strip">🚩 {{ bonusLabel }}</p>

        <div class="arena">
            <!-- twoje wody -->
            <div class="waters">
                <div class="waters-head">
                    <span class="kicker">TWOJE WODY</span>
                </div>
                <div class="board-box">
                    <SeaGrid
                        :cells="ownCells"
                        :interactive="weaponMode === 'torpedo' && !disabled"
                        @cell-click="onOwnClick"
                        @cell-hover="onOwnHover"
                        @leave="hoverCell = null"
                    />
                </div>
                <p class="foot">{{ ownFooter }}</p>
            </div>

            <!-- wody wroga -->
            <div class="waters">
                <div class="waters-head">
                    <span class="kicker enemy">WODY WROGA</span>
                    <span class="kicker">zatopiono {{ enemySunkLengths.length }}/{{ enemyTotal }}</span>
                </div>
                <div class="board-box" :class="{ locked: disabled }">
                    <SeaGrid
                        :cells="enemyCells"
                        :interactive="!disabled && weaponMode !== 'torpedo'"
                        @cell-click="onEnemyClick"
                        @cell-hover="onEnemyHover"
                        @cell-context="toggleNote"
                        @leave="hoverCell = null"
                    />
                </div>
                <p class="foot">{{ enemyHint }}</p>
            </div>

            <!-- dziennik -->
            <aside class="log">
                <div v-if="weaponMode === 'torpedo'" class="rose-box">
                    <div class="kicker">KIERUNEK TORPEDY</div>
                    <div class="rose">
                        <template v-for="(dir, i) in ROSE" :key="i">
                            <button
                                v-if="dir"
                                class="rose-cell"
                                :class="{ on: torpedoDirection === dir }"
                                :disabled="DIAGONALS.includes(dir) && !diagonalAvailable"
                                @click="torpedoDirection = dir"
                            >{{ ROSE_ARROWS[dir] }}</button>
                            <span v-else class="rose-center"><Rocket :size="16" /></span>
                        </template>
                    </div>
                    <p v-if="!diagonalAvailable" class="muted small">ukośna zużyta</p>
                </div>

                <div class="kicker">DZIENNIK</div>
                <ol class="entries">
                    <li v-for="(entry, i) in log" :key="entry.id" :class="{ latest: i === 0 }">
                        <div class="entry-side" :class="entry.side">{{ entry.side }}</div>
                        <div class="entry-text">{{ entry.text }}</div>
                    </li>
                </ol>
                <p v-if="toast" class="toast" :class="toastType">{{ toast }}</p>
                <p v-if="error" class="err small">{{ error }}</p>
                <div class="note">
                    Każda salwa, torpeda i nalot oddaje ruch wrogowi — trafienie nie daje dodatkowego
                    strzału. Wyjątkiem jest sonar: skan nie kończy tury.
                </div>
            </aside>
        </div>

        <!-- pasek broni -->
        <footer class="weapons">
            <button
                v-for="card in weaponCards"
                :key="card.key"
                class="weapon"
                :class="{ selected: weaponMode === card.key, locked: !!card.locked }"
                :disabled="!!card.locked || gameOver"
                @click="selectWeapon(card)"
            >
                <div class="w-top">
                    <span class="w-name"><component :is="card.icon" :size="16" /> {{ card.name }}</span>
                    <span class="w-key">{{ card.hotkey }}</span>
                </div>
                <div class="w-area">{{ card.area }}</div>
                <div class="w-bottom">
                    <span v-if="card.locked" class="w-lock"><Lock :size="11" /> {{ card.locked }}</span>
                    <span v-else class="w-src">{{ card.source }}</span>
                    <span class="w-charges">{{ card.charges }}</span>
                </div>
            </button>

            <button v-if="gameOver" class="btn btn-primary end" :disabled="finalizing" @click="goToResult">
                {{ finalizing ? 'Rozliczanie…' : 'Wynik bitwy →' }}
            </button>
            <div v-else class="turn-box" :class="{ mine: turn === 'player' }">
                <span class="kicker">TURA</span>
                <b>{{ loading ? '…' : turnLabel }}</b>
            </div>
        </footer>
    </section>
</template>

<style scoped>
.frame {
    min-height: 812px;
    border-radius: 18px;
    border: 1px solid var(--line-card);
    background: linear-gradient(160deg, var(--bg-frame-a), var(--bg-frame-b));
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.hud { display: flex; align-items: center; gap: 24px; padding: 20px 32px; border-bottom: 1px solid var(--line); flex-wrap: wrap; }
.turn-line { font: 700 20px var(--font-head); margin-top: 4px; }
.turn-line .mine { color: var(--accent); }
.sep { width: 1px; height: 38px; background: var(--line); }
.hud-val { font: 700 18px var(--font-head); margin-top: 4px; }
.bonus-strip { margin: 0; padding: 8px 32px; border-bottom: 1px solid var(--line); font: 600 12px var(--font-mono); color: var(--info); }
.hud-right { margin-left: auto; display: flex; align-items: center; gap: 8px; }
.ai-pill { display: inline-flex; align-items: center; gap: 6px; padding: 9px 13px; border-radius: 9px; background: var(--bg-card); border: 1px solid var(--line-card); font: 600 12px var(--font-mono); color: var(--text-2); }
.ai-pill.empty { opacity: .45; }

.arena { flex: 1; display: grid; grid-template-columns: 1fr 1fr 320px; }
.waters { padding: 24px 28px; display: flex; flex-direction: column; align-items: center; gap: 12px; }
.waters-head { align-self: stretch; display: flex; justify-content: space-between; align-items: center; min-height: 30px; }
.kicker.enemy { color: var(--accent); }
.board-box { padding: 14px; border-radius: 14px; background: var(--bg-panel); border: 1px solid var(--line); transition: opacity .2s; }
.board-box.locked { opacity: .85; }
.foot { margin: 0; font: 500 11px var(--font-mono); color: var(--text-muted); text-align: center; max-width: 480px; }

.log { border-left: 1px solid var(--line); background: var(--bg-panel-a); padding: 22px; display: flex; flex-direction: column; gap: 10px; min-height: 0; }
.entries { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; overflow-y: auto; max-height: 420px; }
.entries li { padding: 11px 13px; border-radius: 10px; background: var(--bg-card); border: 1px solid var(--line-card); }
.entries li.latest { background: var(--bg-card-active); border-color: var(--info); }
.entry-side { font: 600 10px var(--font-mono); letter-spacing: .14em; color: var(--text-dim); }
.entry-side.TY { color: var(--accent); }
.entry-side.SONAR { color: var(--info); }
.entry-side.INFO { color: var(--text-label); }
.entry-text { font: 400 12px var(--font-body); color: var(--text-2); margin-top: 3px; }
.note { margin-top: auto; padding: 11px 13px; border-radius: 10px; border: 1px dashed var(--line-btn); font: 400 12px var(--font-body); color: var(--text-muted); }
.toast { margin: 0; padding: 9px 12px; border-radius: 9px; font: 500 12px var(--font-body); background: var(--bg-card); border: 1px solid var(--line-btn); }
.toast.warn { border-color: var(--warn); color: var(--warn); }
.toast.error { border-color: var(--danger); color: var(--danger); }
.small { font-size: 11px; margin: 0; }

.rose-box { padding: 12px; border-radius: 12px; background: var(--bg-card); border: 1px solid var(--accent); }
.rose { display: grid; grid-template-columns: repeat(3, 38px); grid-auto-rows: 38px; gap: 5px; margin-top: 10px; justify-content: center; }
.rose-cell { border-radius: 8px; border: 1px solid var(--line-btn); background: var(--bg-card-off); color: var(--text-2); font-size: 17px; cursor: pointer; }
.rose-cell.on { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); }
.rose-cell:disabled { opacity: .3; cursor: not-allowed; }
.rose-center { display: flex; align-items: center; justify-content: center; color: var(--accent); }

.weapons { display: flex; gap: 14px; padding: 18px 28px; border-top: 1px solid var(--line); }
.weapon {
    flex: 1;
    text-align: left;
    padding: 14px 16px;
    border-radius: 14px;
    background: var(--bg-card);
    border: 1px solid var(--line-btn);
    cursor: pointer;
    display: flex;
    flex-direction: column;
    gap: 6px;
    transition: border-color .15s, background .15s;
}
.weapon:hover:not(:disabled) { border-color: var(--info); }
.weapon.selected { background: #1a2f30; border-color: var(--accent); animation: pulseSel 2.4s ease-in-out infinite; }
.weapon.locked { opacity: .5; background: var(--bg-card-off); cursor: not-allowed; }
.weapon:disabled:not(.locked) { cursor: default; }
@keyframes pulseSel {
    0%, 100% { box-shadow: 0 0 0 1px var(--accent), 0 0 18px #e8843c55; }
    50% { box-shadow: 0 0 0 1px var(--accent), 0 0 26px #e8843c88; }
}
@media (prefers-reduced-motion: reduce) { .weapon.selected { animation: none; box-shadow: 0 0 0 1px var(--accent); } }
.w-top { display: flex; justify-content: space-between; align-items: center; }
.w-name { display: inline-flex; align-items: center; gap: 8px; font: 700 16px var(--font-head); }
.weapon.selected .w-name svg { color: var(--accent); }
.w-key { font: 600 10px var(--font-mono); color: var(--text-label); padding: 1px 6px; border-radius: 4px; border: 1px solid var(--line-btn); }
.w-area { font: 500 12px var(--font-body); color: var(--text-2); }
.w-bottom { display: flex; justify-content: space-between; align-items: center; font: 500 10px var(--font-mono); color: var(--text-muted); }
.w-lock { color: var(--danger); display: inline-flex; align-items: center; gap: 4px; }
.w-charges { font: 700 13px var(--font-head); color: var(--info); }
.weapon.locked .w-charges { color: var(--text-dim); }
.end { padding: 0 30px; }
.turn-box { min-width: 170px; display: flex; flex-direction: column; justify-content: center; gap: 4px; padding: 0 22px; border-radius: 14px; border: 1px solid var(--line-card); background: var(--bg-card-off); }
.turn-box b { font: 700 17px var(--font-head); color: var(--text-dim); }
.turn-box.mine b { color: var(--accent); }

@media (max-width: 1280px) {
    .arena { grid-template-columns: 1fr 1fr; }
    .log { grid-column: 1 / -1; border-left: none; border-top: 1px solid var(--line); }
    .weapons { flex-wrap: wrap; }
    .weapon { flex: 1 1 200px; }
}
@media (max-width: 900px) {
    .arena { grid-template-columns: 1fr; }
}
</style>
