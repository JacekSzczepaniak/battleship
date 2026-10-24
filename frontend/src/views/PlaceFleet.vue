<template>
    <section class="frame setup">
        <!-- lewa: taca statków -->
        <div class="tray">
            <div>
                <h2>Twoja flota</h2>
                <p class="muted intro">Przeciągnij jednostki na akwen albo wybierz i kliknij pole. Klik na wybraną kartę lub <kbd>R</kbd> obraca.</p>
            </div>
            <div class="ships">
                <div
                    v-for="ship in ships"
                    :key="ship.id"
                    class="ship-card"
                    :class="{ placed: !!ship.placed, active: ship.id === selectedId }"
                    :draggable="!ship.placed"
                    @dragstart="onDragStart(ship.id, $event)"
                    @click="onCardClick(ship.id)"
                >
                    <div class="ship-head">
                        <span class="ship-name">{{ shipName(ship.l, ship.w) }}</span>
                        <span v-if="ship.placed" class="state placed-state">ROZSTAWIONY</span>
                        <span v-else-if="ship.id === selectedId" class="state active-state">
                            {{ orientation === 'h' ? 'POZIOMO' : 'PIONOWO' }} <RotateCw :size="11" />
                        </span>
                        <span v-else class="state">DO ROZSTAWIENIA</span>
                    </div>
                    <div class="segments">
                        <span v-for="i in ship.l" :key="i"></span>
                    </div>
                    <div v-if="ruleset === 'fun' && carriedWeapon(ship.l, ship.w)" class="carrier">nośnik · {{ carriedWeapon(ship.l, ship.w) }}</div>
                </div>
            </div>
        </div>

        <!-- środek: plansza -->
        <div class="board-col">
            <div class="board-head">
                <div class="kicker">TWOJE WODY · {{ width }}×{{ height }}</div>
                <div v-if="training" class="pills">
                    <template v-if="!siege">
                    <button
                        v-for="s in TRAINING_SIZES"
                        :key="s"
                        class="pill"
                        :class="{ on: width === s && height === s }"
                        :disabled="busy"
                        @click="switchTraining({ size: s })"
                    >{{ s }}²</button>
                    <span class="pill-gap"></span>
                    </template>
                    <button class="pill" :class="{ on: !siege && ruleset === 'classic' }" :disabled="busy" @click="switchTraining({ ruleset: 'classic', siege: false })">KLASYCZNE</button>
                    <button class="pill" :class="{ on: !siege && ruleset === 'fun' }" :disabled="busy" @click="switchTraining({ ruleset: 'fun', siege: false })">BRONIE</button>
                    <button class="pill" :class="{ on: siege }" :disabled="busy" title="oblężenie fortu 10×10: zniszcz działa, mury pochłaniają strzały, magazyn prochu wybucha" @click="switchTraining({ siege: true })">OBLĘŻENIE</button>
                </div>
                <div v-else-if="battle" class="kicker island">BITWA · {{ battle.islandName.toUpperCase() }}</div>
            </div>
            <div class="board-box">
                <SeaGrid
                    :cells="cells"
                    @cell-click="onCellClick"
                    @cell-hover="onHover"
                    @cell-drop="onDrop"
                    @leave="hover = null"
                />
            </div>
            <p class="hint" :class="{ bad: hintBad }">{{ hint }}</p>
        </div>

        <!-- prawa: zasady i akcje -->
        <div class="side">
            <div class="card rules">
                <div class="kicker">ZASADY AKWENU</div>
                <ul>
                    <li><Ban :size="14" /> Statki nie mogą się stykać — także po skosie.</li>
                    <li v-if="ruleset === 'fun'"><Crosshair :size="14" /> Nośniki dają broń: kuter → sonar, niszczyciel → torpeda<template v-for="v in squadVariants" :key="v.name">, {{ v.name.toLowerCase() }} → {{ v.weapon }}</template>, lotniskowiec → nalot. Zatopiony nośnik odbiera broń.</li>
                    <li v-else><Crosshair :size="14" /> Zasady klasyczne: tylko pojedyncze salwy.</li>
                    <li v-if="battle"><Anchor :size="14" /> Stawka: zatopione jednostki po wygranej idą do remontu, po przegranej przepadają.</li>
                    <li v-else><Anchor :size="14" /> Trening — bez XP, łupów i strat we flocie.</li>
                </ul>
            </div>

            <!-- zestawy floty (#125): tylko w bitwie wyprawy — trening nie ma statków profilu -->
            <div v-if="presetsReady" class="card presets">
                <div class="kicker">ZESTAWY FLOTY · {{ presets.length }}/{{ FLEET_PRESET_LIMIT }}</div>
                <ul v-if="presets.length">
                    <li v-for="p in presets" :key="p.id">
                        <template v-if="renamingId === p.id">
                            <input ref="renameInput" v-model="renameText" class="name" :maxlength="FLEET_PRESET_NAME_MAX" @keydown.enter.prevent="confirmRename(p)" @keydown.esc.prevent="renamingId = null" />
                            <button class="icon-btn" title="zapisz nazwę" :disabled="presetBusy" @click="confirmRename(p)"><Check :size="14" /></button>
                            <button class="icon-btn" title="anuluj" @click="renamingId = null"><X :size="14" /></button>
                        </template>
                        <template v-else>
                            <span class="preset-name" :title="p.name">{{ p.name }}</span>
                            <span class="preset-size" :class="{ other: p.board.w !== width || p.board.h !== height }">{{ p.board.w }}×{{ p.board.h }}</span>
                            <button class="icon-btn" title="wczytaj" :disabled="busy || presetBusy" @click="applyPreset(p)"><Download :size="14" /></button>
                            <button class="icon-btn" title="zmień nazwę" :disabled="presetBusy" @click="startRename(p)"><Pencil :size="14" /></button>
                            <button class="icon-btn" :class="{ danger: deletingId === p.id }" :title="deletingId === p.id ? 'kliknij jeszcze raz, żeby usunąć' : 'usuń'" :disabled="presetBusy" @click="removePreset(p)">
                                <Trash2 :size="14" /><span v-if="deletingId === p.id" class="sure">usuń?</span>
                            </button>
                        </template>
                    </li>
                </ul>
                <p v-else class="muted empty">Brak zapisanych zestawów.</p>
                <div class="save-row">
                    <input v-model="presetName" class="name" placeholder="nazwa zestawu" :maxlength="FLEET_PRESET_NAME_MAX" @keydown.enter.prevent="savePreset" />
                    <button class="btn btn-small btn-secondary" :disabled="!canSavePreset" :title="saveBlocker" @click="savePreset">
                        <Save :size="14" /> {{ overwriteTarget ? 'Nadpisz' : 'Zapisz' }}
                    </button>
                </div>
                <p v-if="presetName.trim() && saveBlocker" class="preset-msg">{{ saveBlocker }}</p>
                <p v-if="presetMsg" class="preset-msg" :class="{ bad: presetMsgBad }">{{ presetMsg }}</p>
            </div>

            <div class="progress">
                <div class="progress-head">
                    <span>Rozstawiono</span>
                    <b>{{ placedCount }} / {{ ships.length }}</b>
                </div>
                <div class="track"><div class="fill" :style="{ width: ships.length ? (placedCount / ships.length * 100) + '%' : '0%' }"></div></div>
            </div>

            <div class="buttons">
                <button class="btn btn-secondary" :disabled="busy" @click="shuffle"><Shuffle :size="16" /> Losuj rozstawienie</button>
                <button class="btn btn-secondary" :disabled="busy || placedCount === 0" @click="clearAll"><Eraser :size="16" /> Wyczyść</button>
                <button class="btn btn-primary" :disabled="busy || !allPlaced" @click="submitFleet">
                    <template v-if="allPlaced">{{ busy ? 'Wypływamy…' : 'Do bitwy →' }}</template>
                    <template v-else>Do bitwy → (rozstaw wszystkie)</template>
                </button>
            </div>
            <p v-if="error" class="err">{{ error }}</p>
            <router-link :to="{ name: 'home' }" class="back">← Port</router-link>
        </div>
    </section>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Anchor, Ban, Check, Crosshair, Download, Eraser, Pencil, RotateCw, Save, Shuffle, Trash2, X } from 'lucide-vue-next';
import SeaGrid from '../components/SeaGrid.vue';
import { createGame, getGame, placeFleet, type GameViewDTO, type Loadout, type PlayerFleetItem, type RulesetName } from '../api/gameApi';
import { deleteFleetPreset, getExpedition, renameFleetPreset, saveFleetPreset, type FleetPresetDTO, type FleetShipDTO } from '../api/expeditionApi';
import { getCurrentBattle, setCurrentBattle } from '../lib/expeditionSession';
import {
    FLEET_PRESET_LIMIT, FLEET_PRESET_NAME_MAX, missingMessage, presetErrorMessage, presetPlacements, slotShipIds,
} from '../lib/fleetPresets';
import { TRAINING_SIZES, getTrainingPrefs, setTrainingPrefs } from '../lib/battleSession';
import { carriedWeapon, cellLabel, fieldsLabel, shipName, variantOf } from '../lib/ships';

type Orientation = 'h' | 'v';
// w = wyposażenie wariantu (#99); bez niego statek ma domyślne dla długości
interface SetupShip { id: number; l: number; w?: Loadout; placed: { x: number; y: number; o: Orientation } | null }

const route = useRoute();
const router = useRouter();
const id = computed(() => String(route.params.id ?? ''));

const width = ref(10);
const height = ref(10);
const ruleset = ref<RulesetName>('classic');
// bitwa oblężnicza (#119): fort zamiast floty wroga, plansza 10×10 i zasady fun
const siege = ref(false);
const ships = ref<SetupShip[]>([]);
const selectedId = ref<number | null>(null);
const orientation = ref<Orientation>('h');
const hover = ref<{ x: number; y: number } | null>(null);
const busy = ref(false);
const error = ref('');
const hint = ref('');
const hintBad = ref(false);
let shuffleCount = 0;

// bitwa wyprawy (plansza i skład narzuca wyspa) vs trening (można zmieniać)
const battle = computed(() => {
    const b = getCurrentBattle();
    return b && b.gameId === id.value ? b : null;
});
const training = computed(() => battle.value === null);

// warianty w składzie (#99, #121): opis ich broni obok domyślnych nośników
const squadVariants = computed(() => {
    const seen = new Map<string, string>();
    for (const s of ships.value) {
        if (variantOf(s.l, s.w) && !seen.has(shipName(s.l, s.w))) seen.set(shipName(s.l, s.w), carriedWeapon(s.l, s.w) ?? '');
    }
    return [...seen].map(([name, weapon]) => ({ name, weapon }));
});

const CLASSIC_INVENTORY: Record<string, number> = { 4: 1, 3: 2, 2: 3, 1: 4 };

function buildShips(allowed: Record<string, number>, variants: GameViewDTO['variants']): SetupShip[] {
    const items: { l: number; w?: Loadout }[] = [];
    for (const [len, count] of Object.entries(allowed)) {
        // warianty dostają pierwsze statki danej długości (FleetComposition::roster)
        const own = (variants && !Array.isArray(variants) ? variants[len] : undefined) ?? [];
        for (let i = 0; i < count; i++) items.push({ l: Number(len), w: own[i] });
    }
    return items.sort((a, b) => b.l - a.l).map((s, i) => ({ id: i, ...s, placed: null }));
}

async function load() {
    error.value = '';
    try {
        const g = await getGame(id.value);
        if (g.status !== 'pending') {
            // flota już stoi — prosto do bitwy
            await router.replace({ name: 'game', params: { id: id.value } });
            return;
        }
        width.value = g.board.w;
        height.value = g.board.h;
        ruleset.value = g.ruleset;
        siege.value = !!g.fort;
        ships.value = buildShips(g.allowedShips && Object.keys(g.allowedShips).length ? g.allowedShips : CLASSIC_INVENTORY, g.variants);
        selectNext();
    } catch (e: any) {
        error.value = e?.message ?? 'Nie udało się wczytać gry';
        return;
    }
    await loadPresets();
}

// --- zestawy floty (#125): skład z rozmieszczeniem zapisany w profilu ---

const presets = ref<FleetPresetDTO[]>([]);
const fleet = ref<FleetShipDTO[]>([]);
// id statków floty, które płyną w tej bitwie — łączą statki planszy z flotą
const squadIds = ref<string[]>([]);
const presetName = ref('');
const presetMsg = ref('');
const presetMsgBad = ref(false);
const presetBusy = ref(false);
const renamingId = ref<string | null>(null);
const renameText = ref('');
const deletingId = ref<string | null>(null);
// pole zmiany nazwy (ref w v-for — tablica, najwyżej jeden element)
const renameInput = ref<HTMLInputElement[] | null>(null);

// bitwa sprzed zapisu składu w profilu nie ma statków do powiązania — bez zestawów
const presetsReady = computed(() => battle.value !== null && squadIds.value.length > 0);
const overwriteTarget = computed(() => {
    const name = presetName.value.trim().toLowerCase();
    return name ? presets.value.find(p => p.name.toLowerCase() === name) ?? null : null;
});
// powód, dla którego zapis jest zablokowany ('' = można zapisać, gdy jest nazwa)
const saveBlocker = computed(() => {
    if (!allPlaced.value) return 'Zapis wymaga rozstawienia wszystkich statków.';
    if (overwriteTarget.value === null && presets.value.length >= FLEET_PRESET_LIMIT) {
        return `Limit ${FLEET_PRESET_LIMIT} zestawów: usuń któryś albo zapisz pod istniejącą nazwą.`;
    }
    return '';
});
const canSavePreset = computed(() => !saveBlocker.value && !presetBusy.value && presetName.value.trim() !== '');

function setPresetMsg(text: string, bad = false) {
    presetMsg.value = text;
    presetMsgBad.value = bad;
}

async function loadPresets() {
    const b = battle.value;
    if (!b) return;
    try {
        const expedition = await getExpedition(b.profileId);
        presets.value = expedition.fleetPresets ?? [];
        fleet.value = expedition.fleet;
        squadIds.value = expedition.battleSquads?.[b.gameId] ?? [];
    } catch {
        // bez profilu zestawy po prostu się nie pokażą — rozstawianie działa dalej
        return;
    }
    // zestaw wybrany do bitwy na ekranie wyprawy ustawia się sam, raz
    const chosen = b.presetId ? presets.value.find(p => p.id === b.presetId) : undefined;
    if (b.presetId) setCurrentBattle({ ...b, presetId: undefined });
    if (chosen && presetsReady.value && placedCount.value === 0) applyPreset(chosen);
}

/**
 * Wczytanie zestawu: na planszy tego samego rozmiaru statki płynące w bitwie
 * wracają na zapisane pozycje; brakujące są pomijane z komunikatem, a te, które
 * się nie mieszczą, czekają na ręczne ustawienie. Na innym rozmiarze planszy
 * zestaw dał już skład (ekran wyprawy) — pozycji się nie przenosi.
 */
function applyPreset(preset: FleetPresetDTO) {
    error.value = '';
    deletingId.value = null;
    presetName.value = preset.name;
    if (preset.board.w !== width.value || preset.board.h !== height.value) {
        setPresetMsg(`„${preset.name}” zapisano na planszy ${preset.board.w}×${preset.board.h}, a ta ma ${width.value}×${height.value}. Wczytany jest sam skład, statki rozstaw ręcznie.`);
        return;
    }

    for (const s of ships.value) s.placed = null;
    const { placements, missing } = presetPlacements(preset, ships.value, fleet.value, squadIds.value);
    let placed = 0;
    for (const p of placements) {
        const ship = ships.value.find(s => s.id === p.slotId);
        if (ship && canPlace(ship, p.x, p.y, p.o)) {
            ship.placed = { x: p.x, y: p.y, o: p.o };
            placed++;
        }
    }
    const manual = ships.value.length - placed;
    setPresetMsg([
        `Wczytano „${preset.name}”: ${placed} z ${ships.value.length} statków na pozycjach.`,
        missingMessage(missing),
        manual ? `Do ręcznego ustawienia: ${manual}.` : '',
    ].filter(Boolean).join(' '), missing.length > 0);
    selectNext();
}

async function savePreset() {
    const b = battle.value;
    if (!b || !canSavePreset.value) return;
    const ids = slotShipIds(ships.value, fleet.value, squadIds.value);
    if (ids.size !== ships.value.length) {
        setPresetMsg('Nie udało się powiązać statków planszy z flotą — odśwież ekran.', true);
        return;
    }
    presetBusy.value = true;
    try {
        const saved = await saveFleetPreset(b.profileId, presetName.value, { w: width.value, h: height.value },
            ships.value.map(s => ({ shipId: ids.get(s.id)!, x: s.placed!.x, y: s.placed!.y, o: s.placed!.o === 'h' ? 'H' as const : 'V' as const })));
        const i = presets.value.findIndex(p => p.id === saved.id);
        if (i >= 0) presets.value.splice(i, 1, saved); else presets.value.push(saved);
        presetName.value = saved.name;
        setPresetMsg(i >= 0 ? `Nadpisano zestaw „${saved.name}”.` : `Zapisano zestaw „${saved.name}”.`);
    } catch (e: any) {
        setPresetMsg(presetErrorMessage(e, 'Nie udało się zapisać zestawu'), true);
    } finally {
        presetBusy.value = false;
    }
}

function startRename(preset: FleetPresetDTO) {
    deletingId.value = null;
    renamingId.value = preset.id;
    renameText.value = preset.name;
    nextTick(() => renameInput.value?.[0]?.select());
}

async function confirmRename(preset: FleetPresetDTO) {
    const b = battle.value;
    const name = renameText.value.trim();
    if (!b || !name) return;
    if (name === preset.name) {
        renamingId.value = null;
        return;
    }
    presetBusy.value = true;
    try {
        const renamed = await renameFleetPreset(b.profileId, preset.id, name);
        presets.value = presets.value.map(p => (p.id === renamed.id ? renamed : p));
        renamingId.value = null;
        setPresetMsg(`Zmieniono nazwę na „${renamed.name}”.`);
    } catch (e: any) {
        setPresetMsg(presetErrorMessage(e, 'Nie udało się zmienić nazwy'), true);
    } finally {
        presetBusy.value = false;
    }
}

// pierwsze kliknięcie pyta (przycisk pokazuje „usuń?”), drugie usuwa
async function removePreset(preset: FleetPresetDTO) {
    const b = battle.value;
    if (!b) return;
    if (deletingId.value !== preset.id) {
        deletingId.value = preset.id;
        return;
    }
    presetBusy.value = true;
    try {
        await deleteFleetPreset(b.profileId, preset.id);
        presets.value = presets.value.filter(p => p.id !== preset.id);
        setPresetMsg(`Usunięto zestaw „${preset.name}”.`);
    } catch (e: any) {
        setPresetMsg(presetErrorMessage(e, 'Nie udało się usunąć zestawu'), true);
    } finally {
        deletingId.value = null;
        presetBusy.value = false;
    }
}

// --- geometria ---

function shipCells(x: number, y: number, l: number, o: Orientation): [number, number][] {
    return Array.from({ length: l }, (_, i) => [x + (o === 'h' ? i : 0), y + (o === 'v' ? i : 0)] as [number, number]);
}

const occupancy = computed(() => {
    const map = new Map<string, number>();
    for (const s of ships.value) {
        if (!s.placed) continue;
        for (const [x, y] of shipCells(s.placed.x, s.placed.y, s.l, s.placed.o)) map.set(`${x}:${y}`, s.id);
    }
    return map;
});

function canPlace(ship: SetupShip, x: number, y: number, o: Orientation, occ = occupancy.value): boolean {
    for (const [cx, cy] of shipCells(x, y, ship.l, o)) {
        if (cx < 0 || cy < 0 || cx >= width.value || cy >= height.value) return false;
        for (let dx = -1; dx <= 1; dx++) {
            for (let dy = -1; dy <= 1; dy++) {
                const other = occ.get(`${cx + dx}:${cy + dy}`);
                if (other !== undefined && other !== ship.id) return false;
            }
        }
    }
    return true;
}

const selected = computed(() => ships.value.find(s => s.id === selectedId.value) ?? null);

const cells = computed<string[][]>(() => {
    const occ = occupancy.value;
    const grid = Array.from({ length: height.value }, (_, y) =>
        Array.from({ length: width.value }, (_, x) => (occ.has(`${x}:${y}`) ? 'ship' : 'water')));

    const ship = selected.value;
    const h = hover.value;
    if (ship && !ship.placed && h) {
        const ok = canPlace(ship, h.x, h.y, orientation.value);
        for (const [x, y] of shipCells(h.x, h.y, ship.l, orientation.value)) {
            if (grid[y]?.[x] !== undefined) grid[y][x] += ok ? ' preview-ok' : ' preview-bad';
        }
    }
    return grid;
});

const placedCount = computed(() => ships.value.filter(s => s.placed).length);
const allPlaced = computed(() => ships.value.length > 0 && placedCount.value === ships.value.length);

function setHint(text: string, bad = false) {
    hint.value = text;
    hintBad.value = bad;
}

function selectNext() {
    const next = ships.value.find(s => !s.placed) ?? null;
    selectedId.value = next?.id ?? null;
    if (next) {
        setHint(`Ustaw: ${shipName(next.l, next.w)} (${fieldsLabel(next.l)}) — ${orientation.value === 'h' ? 'poziomo' : 'pionowo'}.`);
    } else {
        setHint('Flota rozstawiona. Kliknij statek na planszy, żeby go podnieść, albo ruszaj do bitwy.');
    }
}

// --- interakcje ---

function rotate() {
    orientation.value = orientation.value === 'h' ? 'v' : 'h';
    if (selected.value && !selected.value.placed) {
        setHint(`${shipName(selected.value.l, selected.value.w)} — ${orientation.value === 'h' ? 'poziomo' : 'pionowo'}.`);
    }
}

function onCardClick(shipId: number) {
    const ship = ships.value.find(s => s.id === shipId);
    if (!ship) return;
    if (ship.placed) {
        pickUp(ship);
        return;
    }
    if (selectedId.value === shipId) {
        rotate();
        return;
    }
    selectedId.value = shipId;
    setHint(`Ustaw: ${shipName(ship.l, ship.w)} (${fieldsLabel(ship.l)}).`);
}

function pickUp(ship: SetupShip) {
    if (!ship.placed) return;
    orientation.value = ship.placed.o;
    ship.placed = null;
    selectedId.value = ship.id;
    setHint(`Podniesiono: ${shipName(ship.l, ship.w)}. Wskaż nowe miejsce.`);
}

function tryPlace(x: number, y: number) {
    const ship = selected.value;
    if (!ship || ship.placed) return;
    if (!canPlace(ship, x, y, orientation.value)) {
        setHint(`${cellLabel(x, y)}: tu się nie zmieści — poza planszą albo styka się z innym statkiem.`, true);
        return;
    }
    ship.placed = { x, y, o: orientation.value };
    selectNext();
}

function onCellClick(x: number, y: number) {
    error.value = '';
    const owner = occupancy.value.get(`${x}:${y}`);
    if (owner !== undefined) {
        const ship = ships.value.find(s => s.id === owner);
        if (ship) pickUp(ship);
        return;
    }
    tryPlace(x, y);
}

function onHover(x: number, y: number) {
    hover.value = { x, y };
}

function onDragStart(shipId: number, e: DragEvent) {
    selectedId.value = shipId;
    e.dataTransfer?.setData('text/plain', String(shipId));
    if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move';
}

function onDrop(x: number, y: number) {
    tryPlace(x, y);
}

function clearAll() {
    for (const s of ships.value) s.placed = null;
    selectNext();
}

// --- losowanie z seeda (deterministyczne dla gry i numeru losowania) ---

function hashString(s: string): number {
    let h = 2166136261;
    for (let i = 0; i < s.length; i++) {
        h ^= s.charCodeAt(i);
        h = Math.imul(h, 16777619);
    }
    return h >>> 0;
}

function rng(seed: number): () => number {
    let state = seed || 1;
    return () => {
        // mulberry32
        state = (state + 0x6d2b79f5) | 0;
        let t = state;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

function shuffle() {
    shuffleCount++;
    const rand = rng(hashString(`${id.value}#${shuffleCount}`));
    const order = [...ships.value].sort((a, b) => b.l - a.l);

    for (let attempt = 0; attempt < 200; attempt++) {
        const occ = new Map<string, number>();
        const result = new Map<number, { x: number; y: number; o: Orientation }>();
        let ok = true;
        for (const ship of order) {
            let placed = false;
            for (let tries = 0; tries < 300 && !placed; tries++) {
                const o: Orientation = rand() < 0.5 ? 'h' : 'v';
                const x = Math.floor(rand() * width.value);
                const y = Math.floor(rand() * height.value);
                if (canPlace(ship, x, y, o, occ)) {
                    for (const [cx, cy] of shipCells(x, y, ship.l, o)) occ.set(`${cx}:${cy}`, ship.id);
                    result.set(ship.id, { x, y, o });
                    placed = true;
                }
            }
            if (!placed) { ok = false; break; }
        }
        if (ok) {
            for (const s of ships.value) s.placed = result.get(s.id) ?? null;
            selectNext();
            return;
        }
    }
    setHint('Nie udało się wylosować rozstawienia — za mało miejsca na planszy.', true);
}

// --- zmiana ustawień treningu: nowa gra (rozmiar/zasady są częścią gry) ---

async function switchTraining(change: { size?: number; ruleset?: RulesetName; siege?: boolean }) {
    const prefs = getTrainingPrefs();
    const next = {
        // wyjście z oblężenia wraca do zapamiętanego rozmiaru (fort narzuca 10×10)
        size: change.size ?? (siege.value ? prefs.size : width.value),
        ruleset: change.ruleset ?? (siege.value ? prefs.ruleset : ruleset.value),
        siege: change.siege ?? siege.value,
    };
    if (next.siege === siege.value && (next.siege
        || (next.size === width.value && next.size === height.value && next.ruleset === ruleset.value))) return;
    busy.value = true;
    error.value = '';
    try {
        const g = await createGame(next.ruleset, next.size, next.siege);
        setTrainingPrefs({ ...prefs, ...next });
        shuffleCount = 0;
        await router.replace({ name: 'place-fleet', params: { id: g.id } });
        await load();
    } catch (e: any) {
        error.value = e?.message ?? 'Nie udało się zmienić ustawień bitwy';
    } finally {
        busy.value = false;
    }
}

async function submitFleet() {
    if (!allPlaced.value) return;
    busy.value = true;
    error.value = '';
    try {
        const payload: PlayerFleetItem[] = ships.value.map(s => ({ x: s.placed!.x, y: s.placed!.y, o: s.placed!.o, l: s.l, ...(s.w ? { w: s.w } : {}) }));
        await placeFleet(id.value, payload);
        await router.push({ name: 'game', params: { id: id.value } });
    } catch (e: any) {
        error.value = e?.message ?? 'Backend odrzucił rozstawienie floty';
    } finally {
        busy.value = false;
    }
}

function onKey(e: KeyboardEvent) {
    const target = e.target as HTMLElement | null;
    if (target && ['INPUT', 'TEXTAREA'].includes(target.tagName)) return;
    if (e.key === 'r' || e.key === 'R') {
        e.preventDefault();
        rotate();
    } else if (e.key === 'Enter' && allPlaced.value && !busy.value) {
        e.preventDefault();
        submitFleet();
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKey);
    load();
});
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<style scoped>
.frame {
    min-height: 812px;
    border-radius: 18px;
    border: 1px solid var(--line-card);
    background: linear-gradient(160deg, var(--bg-frame-a), var(--bg-frame-b));
    padding: 36px;
    display: grid;
    grid-template-columns: 300px 1fr 300px;
    gap: 28px;
}

.tray { display: flex; flex-direction: column; gap: 16px; }
h2 { font: 700 22px var(--font-head); margin: 0; }
.intro { font-size: 13px; margin: 4px 0 0; }
kbd { font: 600 10px var(--font-mono); padding: 1px 5px; border-radius: 4px; border: 1px solid var(--line-btn); color: var(--text-2); }
.ships { display: flex; flex-direction: column; gap: 11px; overflow-y: auto; max-height: 640px; padding-right: 4px; }
.ship-card {
    padding: 14px;
    border-radius: 12px;
    background: var(--bg-card);
    border: 1px solid var(--line-btn);
    cursor: grab;
    transition: border-color .15s, background .15s, opacity .15s;
}
.ship-card:hover { border-color: var(--info); }
.ship-card.placed { opacity: .5; cursor: pointer; }
.ship-card.active { background: var(--bg-card-active); border-color: var(--accent); opacity: 1; }
.ship-head { display: flex; justify-content: space-between; align-items: center; }
.ship-name { font: 600 14px var(--font-head); }
.state { font: 600 10px var(--font-mono); color: var(--text-muted); display: inline-flex; align-items: center; gap: 4px; }
.placed-state { color: var(--info); }
.active-state { color: var(--accent); }
.segments { display: flex; gap: 4px; margin-top: 9px; }
.segments span { width: 22px; height: 12px; border-radius: 3px; background: #33596a; }
.ship-card.active .segments span { background: #4b7a89; }
.carrier { margin-top: 7px; font: 500 10px var(--font-mono); color: var(--text-muted); }

.board-col { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 18px; }
.board-head { align-self: stretch; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
.board-head .kicker { font-size: 12px; letter-spacing: .18em; }
.board-head .island { color: var(--accent); }
.pills { display: flex; gap: 8px; align-items: center; }
.pill {
    padding: 6px 12px;
    border-radius: 8px;
    background: var(--bg-card);
    border: 1px solid var(--line-card);
    font: 600 11px var(--font-mono);
    color: var(--text-muted);
    cursor: pointer;
}
.pill:hover:not(:disabled) { color: var(--text); }
.pill.on { background: var(--bg-card-active); border-color: var(--info); color: var(--info); }
.pill:disabled { cursor: wait; }
.pill-gap { width: 6px; }
.board-box { padding: 16px; border-radius: 14px; background: var(--bg-panel); border: 1px solid var(--line); }
.hint { margin: 0; min-height: 20px; font: 500 12px var(--font-mono); color: var(--text-muted); text-align: center; }
.hint.bad { color: var(--danger); }

.side { display: flex; flex-direction: column; gap: 16px; }
.rules .kicker { margin-bottom: 10px; letter-spacing: .18em; }
.rules ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; font: 400 13px var(--font-body); color: var(--text-2); }
.rules li { display: flex; gap: 9px; align-items: flex-start; }
.rules li svg { flex: none; margin-top: 3px; color: var(--info); }
.presets { display: flex; flex-direction: column; gap: 10px; }
.presets .kicker { letter-spacing: .18em; }
.presets ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.presets li { display: flex; align-items: center; gap: 6px; font: 500 13px var(--font-body); }
.preset-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--text); }
.preset-size { font: 500 11px var(--font-mono); color: var(--text-muted); }
.preset-size.other { color: var(--warn); }
.icon-btn {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 6px; border-radius: 6px;
    background: var(--bg-card); border: 1px solid var(--line-btn); color: var(--text-2);
    font: 600 10px var(--font-mono); cursor: pointer;
}
.icon-btn:hover:not(:disabled) { border-color: var(--info); color: var(--text); }
.icon-btn:disabled { opacity: .5; cursor: not-allowed; }
.icon-btn.danger { border-color: var(--danger); color: var(--danger); }
.name {
    flex: 1; min-width: 0;
    padding: 6px 8px; border-radius: 6px;
    background: var(--bg-card); border: 1px solid var(--line-btn); color: var(--text);
    font: 500 12px var(--font-body);
}
.save-row { display: flex; gap: 6px; align-items: center; }
.empty { margin: 0; font-size: 12px; }
.preset-msg { margin: 0; font: 500 11px var(--font-mono); color: var(--text-2); }
.preset-msg.bad { color: var(--warn); }
.progress-head { display: flex; justify-content: space-between; font: 500 12px var(--font-mono); color: var(--text-muted); }
.progress-head b { color: var(--text); }
.track { height: 7px; border-radius: 4px; background: var(--bg-card); border: 1px solid var(--line-card); margin-top: 8px; overflow: hidden; }
.fill { height: 100%; background: linear-gradient(90deg, var(--info), var(--info-light)); transition: width .3s ease-out; }
.buttons { display: flex; flex-direction: column; gap: 10px; margin-top: auto; }
.buttons .btn-secondary { justify-content: flex-start; }
.back { font: 500 12px var(--font-mono); color: var(--text-muted); }

@media (max-width: 1200px) {
    .frame { grid-template-columns: 1fr; }
    .ships { flex-direction: row; flex-wrap: wrap; max-height: none; }
    .ship-card { flex: 1 1 180px; }
}
</style>
