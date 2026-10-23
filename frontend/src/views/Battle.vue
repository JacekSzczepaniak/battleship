<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch, type Component } from 'vue';
import { useRouter } from 'vue-router';
import { Anchor, Crosshair, Lock, Move, Plane, Radar, Rocket } from 'lucide-vue-next';
import SeaGrid from '../components/SeaGrid.vue';
import { useGame, type WeaponMode } from '../composables/useGame';
import { getExpedition, settleBattle } from '../api/expeditionApi';
import { clearCurrentBattle, getCurrentBattle } from '../lib/expeditionSession';
import { getBattleReport, saveBattleReport, type BattleReport } from '../lib/battleSession';
import { carriedWeapons, fieldsLabel, shipName } from '../lib/ships';
import { attackerMissingShip, battleBonusLabel } from '../lib/counterattack';
import type { BattleActionName, MoveDirection, PlayerFleetItem, TorpedoDirection } from '../api/gameApi';

const {
    gameId, status, finished, turn, loading, shooting, error, disabled, attack, endTurn, moveShip, moveUsed, boardShip, boarding, width, height,
    ruleset, energy, weapons, opponentWeapons, weaponMode, torpedoDirection, sonarMarks, launchableCells, aliveCarriers,
    noteMarks, toggleNote, fort,
    shotsCount, hitsCount, opponentHitsCount, enemySunkLengths, playerSunkLengths, enemyTotal, playerTotal,
    allowedShips, opponentShips, opponentVariants, playerFleet,
    log, toast, toastType,
    playerGrid, playerUnderFireOverlay, enemyFogGrid, lastShot, sunkCells,
} = useGame();
const router = useRouter();

const battle = getCurrentBattle();
const expeditionBattle = computed(() => battle !== null && battle.gameId === gameId.value);
const gameOver = computed(() => finished.value || status.value === 'won' || status.value === 'lost');
// oblężenie fortu (#119): licznik zniszczonych dział zamiast zatopień
const gunsDestroyed = computed(() => (fort.value ? fort.value.guns.total - fort.value.guns.left : 0));

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
    // koszt energii (#59); null = bitwa bez energii
    cost: number | null;
    locked: string | null;
}

// --- energia (#59): koszty działań (z ruchem statku z #60) z backendu ---

const ACTION_OF: Record<WeaponMode, BattleActionName> = {
    shot: 'shot',
    sonar: 'sonar',
    torpedo: 'torpedo',
    airraid: 'airRaid',
};

function energyCost(action: BattleActionName): number | null {
    return energy.value?.costs[action] ?? null;
}

function lacksEnergy(action: BattleActionName): boolean {
    const cost = energyCost(action);
    return energy.value !== null && cost !== null && energy.value.left < cost;
}

// segmenty paska energii w HUD: true = pełny
const energySegments = computed(() => {
    const e = energy.value;
    return e ? Array.from({ length: e.max }, (_, i) => i < e.left) : [];
});

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
    if (lacksEnergy(ACTION_OF[mode])) return 'brak energii';
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
    { key: 'shot', hotkey: '1', name: 'Salwa', icon: Crosshair, area: 'jedno pole', source: 'każdy okręt', charges: '∞', cost: energyCost('shot'), locked: lacksEnergy('shot') ? 'brak energii' : null },
    { key: 'sonar', hotkey: '2', name: 'Sonar', icon: Radar, area: `krzyż · promień ${sonarRadius.value}`, source: `${carrierSource('sonar')} · nie kończy tury`, charges: charges('sonar'), cost: energyCost('sonar'), locked: lockReason('sonar') },
    { key: 'torpedo', hotkey: '3', name: 'Torpeda', icon: Rocket, area: 'linia do krawędzi', source: carrierSource('torpedo'), charges: charges('torpedo'), cost: energyCost('torpedo'), locked: lockReason('torpedo') },
    { key: 'airraid', hotkey: '4', name: 'Nalot', icon: Plane, area: 'obszar 3×3', source: carrierSource('airraid'), charges: charges('airraid'), cost: energyCost('airRaid'), locked: lockReason('airraid') },
]);

function selectWeapon(card: WeaponCard) {
    if (card.locked || gameOver.value) return;
    weaponMode.value = card.key;
    cancelMove();
}

// broń wyczerpana / nośnik zatopiony / brak energii w trakcie wyboru — wróć do salwy
watch(weaponCards, cards => {
    if (cards.find(c => c.key === weaponMode.value)?.locked) weaponMode.value = 'shot';
});
// --- abordaż (#118): ostatni statek wroga, rzut k6, ⚡3, jedna próba na bitwę ---

const BOARDING_BLOCKERS: Record<string, string> = {
    'not-last-ship': 'tylko ostatni statek wroga',
    attempted: 'próba wykorzystana',
    energy: 'brak energii · cała tura',
    'not-available': 'niedostępny',
};

const BOARDING_MODIFIERS: Record<string, string> = {
    'half-hull': 'połowa kadłuba trafiona',
};

const boardingCard = computed(() => {
    const b = boarding.value;
    if (!b) return null;
    const mods = b.modifiers.map(m => `${BOARDING_MODIFIERS[m.code] ?? m.code} ${m.value < 0 ? '−' : '+'}${Math.abs(m.value)}`).join(', ');
    const attempt = b.attempt;
    return {
        area: `k6 · sukces ${b.threshold}+${mods ? ` (${mods})` : ''}`,
        source: 'ostatni statek wroga · kończy turę',
        charges: attempt ? '0/1' : '1/1',
        cost: b.cost,
        locked: attempt
            ? `k6 = ${attempt.roll} · ${attempt.success ? 'zdobyty' : 'odparty'}`
            : (b.blocker ? BOARDING_BLOCKERS[b.blocker] ?? b.blocker : null),
    };
});

function board() {
    if (!boarding.value?.available || gameOver.value) return;
    cancelMove();
    boardShip();
}

// po wyczerpaniu energii zostaje tylko „Zakończ turę” — nawet salwa jest zablokowana
const actionLocked = computed(() => !!weaponCards.value.find(c => c.key === weaponMode.value)?.locked);

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

// --- ruch statku (#60): 1⚡, raz na turę, o 1 pole wzdłuż osi, tylko nietrafiony ---

const MOVE_ROSE: (MoveDirection | null)[] = [null, 'N', null, 'W', null, 'E', null, 'S', null];
const MOVE_ARROWS: Record<MoveDirection, string> = { N: '↑', E: '→', S: '↓', W: '←' };
const MOVE_VECTORS: Record<MoveDirection, [number, number]> = { N: [0, -1], E: [1, 0], S: [0, 1], W: [-1, 0] };
const MOVE_KEYS: Record<string, MoveDirection> = { ArrowUp: 'N', ArrowRight: 'E', ArrowDown: 'S', ArrowLeft: 'W' };

// tryb ruchu włączony przyciskiem „Przesuń flotę”; wybrany statek = indeks we flocie
const moving = ref(false);
const movingShip = ref<number | null>(null);
const moveHoverDir = ref<MoveDirection | null>(null);

function fleetCells(s: PlayerFleetItem): [number, number][] {
    const horizontal = s.o.toLowerCase() === 'h';
    return Array.from({ length: s.l }, (_, i) => [s.x + (horizontal ? i : 0), s.y + (horizontal ? 0 : i)]);
}

// statki bez trafienia — tylko one manewrują
const movableShips = computed<Set<number>>(() => {
    const ov = playerUnderFireOverlay.value;
    const set = new Set<number>();
    playerFleet.value.forEach((s, i) => {
        if (fleetCells(s).every(([x, y]) => ov[y]?.[x] !== 'opp-hit')) set.add(i);
    });
    return set;
});

const moveLock = computed<string | null>(() => {
    if (!energy.value) return 'tylko w trybie z broniami';
    if (gameOver.value) return 'bitwa rozstrzygnięta';
    if (moveUsed.value) return 'ruch wykorzystany w tej turze';
    if (lacksEnergy('move')) return 'brak energii';
    if (movableShips.value.size === 0) return 'każdy statek trafiony';
    return null;
});

/** Pola po ruchu i powód odrzucenia (te same reguły co w domenie; backend i tak waliduje). */
function moveTarget(index: number, dir: MoveDirection): { cells: [number, number][]; reason: string | null } {
    const ship = playerFleet.value[index];
    const horizontal = ship.o.toLowerCase() === 'h';
    const [dx, dy] = MOVE_VECTORS[dir];
    const cells = fleetCells(ship).map(([x, y]) => [x + dx, y + dy] as [number, number]);
    if (ship.l > 1 && horizontal !== (dir === 'E' || dir === 'W')) return { cells: [], reason: 'tylko wzdłuż osi statku' };
    if (cells.some(([x, y]) => x < 0 || y < 0 || x >= width.value || y >= height.value)) return { cells, reason: 'poza planszą' };
    const ov = playerUnderFireOverlay.value;
    if (cells.some(([x, y]) => (ov[y]?.[x] ?? 'none') !== 'none')) return { cells, reason: 'pole ostrzelane przez wroga' };
    const touches = playerFleet.value.some((other, i) => i !== index && fleetCells(other).some(([ox, oy]) =>
        cells.some(([x, y]) => Math.abs(x - ox) <= 1 && Math.abs(y - oy) <= 1)));
    if (touches) return { cells, reason: 'styk z innym statkiem' };
    return { cells, reason: null };
}

const moveOptions = computed(() => {
    const index = movingShip.value;
    if (index === null || !playerFleet.value[index]) return null;
    const out = {} as Record<MoveDirection, { cells: [number, number][]; reason: string | null }>;
    for (const dir of ['N', 'E', 'S', 'W'] as MoveDirection[]) out[dir] = moveTarget(index, dir);
    return out;
});

const movingShipName = computed(() => {
    const ship = movingShip.value !== null ? playerFleet.value[movingShip.value] : null;
    return ship ? shipName(ship.l, ship.w) : '';
});

function cancelMove() {
    moving.value = false;
    movingShip.value = null;
    moveHoverDir.value = null;
}

function toggleMove() {
    if (moving.value) return cancelMove();
    if (moveLock.value || disabled.value) return;
    weaponMode.value = 'shot';
    moving.value = true;
}

async function doMove(dir: MoveDirection) {
    const option = moveOptions.value?.[dir];
    if (!option || option.reason || movingShip.value === null) return;
    const ship = playerFleet.value[movingShip.value];
    if (await moveShip(ship.x, ship.y, dir)) cancelMove();
}

// ruch wykorzystany, brak energii albo koniec bitwy w trakcie wyboru — zamknij tryb ruchu
watch(moveLock, lock => { if (lock) cancelMove(); });

// --- podgląd zasięgu pod kursorem ---

const hoverCell = ref<{ x: number; y: number } | null>(null);

const previewCells = computed<Set<string>>(() => {
    const set = new Set<string>();
    const hc = hoverCell.value;
    if (!hc || disabled.value || actionLocked.value) return set;
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

// nakładki trybu ruchu: statki do wyboru, wybrany statek i pola po ruchu w kierunku pod kursorem
const moveOverlay = computed<Map<string, string>>(() => {
    const out = new Map<string, string>();
    if (!moving.value) return out;
    playerFleet.value.forEach((s, i) => {
        if (i === movingShip.value) {
            fleetCells(s).forEach(([x, y]) => out.set(`${x}:${y}`, ' preview'));
        } else if (movingShip.value === null && movableShips.value.has(i)) {
            fleetCells(s).forEach(([x, y]) => out.set(`${x}:${y}`, ' launch'));
        }
    });
    const hovered = moveHoverDir.value && moveOptions.value?.[moveHoverDir.value];
    if (hovered) {
        hovered.cells.forEach(([x, y]) => out.set(`${x}:${y}`, hovered.reason ? ' preview-bad' : ' preview-ok'));
    }
    return out;
});

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
        if (moving.value) cls += moveOverlay.value.get(key) ?? '';
        return cls;
    }));
});

const enemyCells = computed<string[][]>(() => {
    const ls = lastShot.value;
    const sunk = new Set(sunkCells.value.map(([x, y]) => `${x}:${y}`));
    const sonar = new Map(sonarMarks.value.map(c => [`${c.x}:${c.y}`, c.occupied]));

    // fort: rodzaj odsłoniętej konstrukcji zastępuje trafienie/zatopienie
    // (mur nie pulsuje jak trafiony statek — nie ma czego dobijać)
    const fortKinds = new Map((fort.value?.revealed ?? []).map(c => [`${c.x}:${c.y}`, c.kind]));
    const blast = new Set((fort.value?.explosion ?? []).map(([x, y]) => `${x}:${y}`));

    return enemyFogGrid.value.map((row, y) => row.map((cell, x) => {
        const key = `${x}:${y}`;
        let cls = 'fog';
        if (fortKinds.has(key)) cls += ` fort-${fortKinds.get(key)}`;
        else if (sunk.has(key)) cls = 'sunk';
        else if (cell === 'hit') cls += ' hit';
        else if (cell === 'miss') cls += ' miss';
        else {
            if (sonar.has(key)) cls += sonar.get(key) ? ' sonar-ship' : ' sonar-water';
            if (noteMarks.value.has(key)) cls += ' note';
        }
        if (blast.has(key)) cls += ' blast';
        // torpeda: kursor stoi na własnej wyrzutni, tor rysujemy na wodach wroga
        if (previewCells.value.has(key)) cls += ' preview';
        if (ls && ls.x === x && ls.y === y) cls += ' anim';
        return cls;
    }));
});

function onEnemyClick(x: number, y: number) {
    if (disabled.value || actionLocked.value || weaponMode.value === 'torpedo') return;
    attack(x, y);
}

function onOwnClick(x: number, y: number) {
    if (moving.value) {
        if (disabled.value) return;
        const index = playerFleet.value.findIndex(s => fleetCells(s).some(([cx, cy]) => cx === x && cy === y));
        if (index >= 0 && movableShips.value.has(index)) movingShip.value = index === movingShip.value ? null : index;
        return;
    }
    if (disabled.value || actionLocked.value || weaponMode.value !== 'torpedo') return;
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
    if (energy.value?.left === 0) return 'Energia wyczerpana — zakończ turę.';
    switch (weaponMode.value) {
        case 'torpedo': return 'Torpeda: kliknij podświetlony niszczyciel na SWOICH wodach — popłynie w wybranym kierunku.';
        case 'sonar': return `Sonar: kliknij środek skanu (krzyż o promieniu ${sonarRadius.value}).`;
        case 'airraid': return 'Nalot: kliknij środek obszaru 3×3.';
        default: return 'Kliknij pole, by oddać salwę · PPM — notatka „tu pusto”.';
    }
});

const ownFooter = computed(() => {
    if (moving.value) {
        return movingShip.value === null
            ? 'Ruch statku: kliknij podświetlony, nietrafiony statek.'
            : 'Wybierz kierunek w panelu obok albo strzałką na klawiaturze · Esc — anuluj.';
    }
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
    if (ruleset.value !== 'fun' || fort.value) return [];
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
    // fort nie ma broni specjalnych (#119)
    if (ruleset.value !== 'fun' || !w || fort.value) return [];
    return [
        { icon: Radar, label: 'sonar', left: w.sonar.limit - w.sonar.used, limit: w.sonar.limit },
        { icon: Rocket, label: 'torpeda', left: w.torpedo.limit - w.torpedo.used, limit: w.torpedo.limit },
        { icon: Plane, label: 'nalot', left: w.airRaid.limit - w.airRaid.used, limit: w.airRaid.limit },
    ];
});

// --- klawiatura: 1–4 broń, strzałki = kierunek ruchu statku, Esc = salwa ---

function onKey(e: KeyboardEvent) {
    const card = weaponCards.value.find(c => c.hotkey === e.key);
    if (moving.value && movingShip.value !== null && MOVE_KEYS[e.key]) {
        e.preventDefault();
        doMove(MOVE_KEYS[e.key]);
    } else if (card) {
        e.preventDefault();
        selectWeapon(card);
    } else if (e.key === 'Escape') {
        weaponMode.value = 'shot';
        cancelMove();
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
        boarded: boarding.value?.attempt?.success ?? false,
        fort: fort.value ? { destroyed: gunsDestroyed.value, total: fort.value.guns.total } : null,
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
                <div class="kicker">{{ expeditionBattle && battle ? `BITWA · ${battle.islandName.toUpperCase()}` : fort ? 'OBLĘŻENIE · TRENING' : 'BITWA TRENINGOWA' }}</div>
                <div class="turn-line">
                    OSTRZELANO {{ fieldsLabel(shotsCount).toUpperCase() }} · <span :class="{ mine: turn === 'player' && !gameOver }">{{ turnLabel }}</span>
                </div>
            </div>
            <span class="sep"></span>
            <div v-if="fort" class="hud-block" title="wygrana po zniszczeniu wszystkich dział; mury się nie liczą">
                <div class="kicker">DZIAŁA FORTU</div>
                <div class="hud-val">{{ gunsDestroyed }} / {{ fort.guns.total }}</div>
            </div>
            <div v-else class="hud-block">
                <div class="kicker">ZATOPIONO</div>
                <div class="hud-val">{{ enemySunkLengths.length }} / {{ enemyTotal }}</div>
            </div>
            <div class="hud-block">
                <div class="kicker">STRATY</div>
                <div class="hud-val">{{ playerSunkLengths.length }} / {{ playerTotal }}</div>
            </div>
            <template v-if="energy">
                <span class="sep"></span>
                <div class="hud-block" :title="`energia na turę: ${energy.left} z ${energy.max}, odnawia się po ruchu wroga`">
                    <div class="kicker">ENERGIA</div>
                    <div class="energy-bar">
                        <span v-for="(full, i) in energySegments" :key="i" class="energy-seg" :class="{ full }"></span>
                        <span class="energy-num">{{ energy.left }}/{{ energy.max }}</span>
                    </div>
                </div>
            </template>
            <div v-if="fort" class="hud-right">
                <span class="kicker">FORT</span>
                <span class="ai-pill" :class="{ empty: fort.guns.left === 0 }" title="fort strzela, dopóki ma choć jedno działo; bez broni specjalnych i energii">
                    <Crosshair :size="13" /> {{ fort.guns.left > 0 ? '1 salwa na turę' : 'zamilkł' }}
                </span>
            </div>
            <div v-else-if="aiArsenal.length" class="hud-right">
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
                    <button
                        v-if="energy"
                        class="move-btn"
                        :class="{ on: moving }"
                        :disabled="!moving && (!!moveLock || disabled)"
                        :title="moveLock ?? 'Przesuń nietrafiony statek o 1 pole wzdłuż osi — raz na turę, nie kończy tury'"
                        @click="toggleMove"
                    >
                        <Move :size="13" /> {{ moving ? 'Anuluj ruch' : 'Przesuń flotę' }} <span class="w-cost">⚡{{ energyCost('move') }}</span>
                    </button>
                </div>
                <div class="board-box">
                    <SeaGrid
                        :cells="ownCells"
                        :interactive="(weaponMode === 'torpedo' || moving) && !disabled"
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
                    <span class="kicker enemy">{{ fort ? 'FORT' : 'WODY WROGA' }}</span>
                    <span v-if="fort" class="kicker">działa {{ gunsDestroyed }}/{{ fort.guns.total }}</span>
                    <span v-else class="kicker">zatopiono {{ enemySunkLengths.length }}/{{ enemyTotal }}</span>
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
                <div v-if="fort" class="fort-legend" aria-label="legenda fortu">
                    <span><i class="sw fort-gun"></i> działo</span>
                    <span><i class="sw fort-wall"></i> mur</span>
                    <span><i class="sw fort-magazine"></i> magazyn prochu</span>
                    <span><i class="sw blast"></i> eksplozja</span>
                </div>
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

                <div v-if="moving && moveOptions" class="rose-box">
                    <div class="kicker">RUCH · {{ movingShipName.toUpperCase() }}</div>
                    <div class="rose">
                        <template v-for="(dir, i) in MOVE_ROSE" :key="i">
                            <button
                                v-if="dir"
                                class="rose-cell"
                                :disabled="!!moveOptions[dir].reason || disabled"
                                :title="moveOptions[dir].reason ?? `przesuń ${MOVE_ARROWS[dir]}`"
                                @mouseenter="moveHoverDir = dir"
                                @mouseleave="moveHoverDir = null"
                                @click="doMove(dir)"
                            >{{ MOVE_ARROWS[dir] }}</button>
                            <span v-else-if="i === 4" class="rose-center"><Move :size="16" /></span>
                            <span v-else></span>
                        </template>
                    </div>
                    <p class="muted small">⚡{{ energyCost('move') }} · raz na turę · nie kończy tury</p>
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
                <div v-if="fort" class="note">
                    Oblężenie: zniszcz wszystkie działa fortu. Trafiony mur tylko pochłania strzał,
                    a trafiony magazyn prochu wybucha i trafia pola 3×3 wokół siebie. Fort oddaje
                    jedną salwę na turę, dopóki ma choć jedno działo.
                </div>
                <div v-else-if="energy" class="note">
                    Masz {{ energy.max }}⚡ na turę. Salwa, torpeda i nalot kosztują energię i kończą turę;
                    sonar i ruch statku (raz na turę) tylko zużywają energię. „Zakończ turę” oddaje ruch
                    bez ataku, a po ruchu wroga energia wraca.
                </div>
                <div v-else class="note">
                    Każda salwa oddaje ruch wrogowi — trafienie nie daje dodatkowego strzału.
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
                    <span class="w-costs">
                        <span class="w-charges">{{ card.charges }}</span>
                        <span v-if="card.cost !== null" class="w-cost" :title="`koszt: ${card.cost} energii`">⚡{{ card.cost }}</span>
                    </span>
                </div>
            </button>

            <button
                v-if="boardingCard"
                class="weapon boarding"
                :class="{ locked: !!boardingCard.locked }"
                :disabled="!!boardingCard.locked || disabled || gameOver"
                :title="boardingCard.locked ? '' : 'Rzut k6 z progiem z karty. Porażka: tura stracona, druga próba w tej bitwie się nie należy.'"
                @click="board"
            >
                <div class="w-top">
                    <span class="w-name"><Anchor :size="16" /> Abordaż</span>
                    <span class="w-key">k6</span>
                </div>
                <div class="w-area">{{ boardingCard.area }}</div>
                <div class="w-bottom">
                    <span v-if="boardingCard.locked" class="w-lock"><Lock :size="11" /> {{ boardingCard.locked }}</span>
                    <span v-else class="w-src">{{ boardingCard.source }}</span>
                    <span class="w-costs">
                        <span class="w-charges">{{ boardingCard.charges }}</span>
                        <span class="w-cost" :title="`koszt: ${boardingCard.cost} energii`">⚡{{ boardingCard.cost }}</span>
                    </span>
                </div>
            </button>

            <button v-if="gameOver" class="btn btn-primary end" :disabled="finalizing" @click="goToResult">
                {{ finalizing ? 'Rozliczanie…' : 'Wynik bitwy →' }}
            </button>
            <button
                v-else-if="energy"
                class="btn btn-primary end"
                :disabled="disabled"
                :title="turn === 'player' ? 'Oddaj turę bez ataku — energia wróci po ruchu wroga' : ''"
                @click="endTurn"
            >
                {{ turn === 'player' && !shooting ? 'Zakończ turę' : turnLabel }}
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
.energy-bar { display: flex; align-items: center; gap: 5px; margin-top: 8px; }
.energy-seg { width: 22px; height: 11px; border-radius: 3px; background: var(--bg-card); border: 1px solid var(--line-btn); }
.energy-seg.full { background: var(--info); border-color: var(--info); box-shadow: 0 0 8px #3fb6c477; }
.energy-num { margin-left: 6px; font: 600 11px var(--font-mono); color: var(--text-muted); }
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
.fort-legend { display: flex; gap: 14px; flex-wrap: wrap; justify-content: center; font: 500 11px var(--font-mono); color: var(--text-muted); }
.fort-legend span { display: inline-flex; align-items: center; gap: 6px; }
/* próbki legendy: te same klasy co komórki planszy fortu (SeaGrid) */
.fort-legend .sw { position: relative; width: 16px; height: 16px; border-radius: 3px; display: inline-block; }
.fort-legend .sw.fort-gun { background: #24130e; box-shadow: inset 0 0 0 1.5px var(--danger); }
.fort-legend .sw.fort-wall { background: repeating-linear-gradient(0deg, #4a5257 0 3px, #2e3438 3px 4px); box-shadow: inset 0 0 0 1px #6b767c; }
.fort-legend .sw.fort-magazine { background: #3b2d0e; box-shadow: inset 0 0 0 1.5px var(--warn); }
.fort-legend .sw.blast { background: #23160f; outline: 1.5px dashed #e8843caa; outline-offset: -3px; }

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
.move-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 12px; border-radius: 8px;
    border: 1px solid var(--line-btn); background: var(--bg-card-off); color: var(--text-2);
    font: 600 11px var(--font-mono); letter-spacing: .02em; cursor: pointer;
}
.move-btn.on { border-color: var(--accent); color: var(--accent); }
.move-btn:disabled { opacity: .45; cursor: not-allowed; }

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
.w-costs { display: inline-flex; align-items: center; gap: 8px; }
.w-charges { font: 700 13px var(--font-head); color: var(--info); }
.w-cost { font: 700 12px var(--font-mono); color: var(--accent); }
.weapon.locked .w-cost { color: var(--text-dim); }
.weapon.locked .w-charges { color: var(--text-dim); }
.weapon.boarding:not(.locked) { border-color: var(--accent); }
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
