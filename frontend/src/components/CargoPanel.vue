<!--
    Przeładunek w porcie (#49): magazyn wyspy ⇄ ładownia floty. Ilości wpisuje
    się dla każdego surowca, a jeden przycisk przenosi je wszystkie w jednym
    dokowaniu — 1 tik niezależnie od liczby surowców. Limity (stan magazynu,
    wolne miejsce, stan ładowni) sprawdza też backend.
-->
<script setup lang="ts">
import { computed, reactive, watch } from 'vue';
import type { IslandDTO, ResourcesDTO } from '../api/expeditionApi';
import { RESOURCES, totalResources, type ResourceKey } from '../lib/resources';

const props = defineProps<{
    island: IslandDTO;
    cargo: ResourcesDTO;
    capacity: number;
    busy: boolean;
}>();

const emit = defineEmits<{
    transfer: [direction: 'load' | 'unload', amount: ResourcesDTO];
}>();

const amount = reactive<ResourcesDTO>({ wood: 0, steel: 0, gold: 0 });

// po przeładunku (nowy stan ładowni lub magazynu) pola wracają do zera
watch(() => [props.cargo, props.island.stockpile], () => clear());

const used = computed(() => totalResources(props.cargo));
const free = computed(() => Math.max(0, props.capacity - used.value));
const picked = computed(() => totalResources(amount));

/** Paski zajętości: udział każdego surowca w pojemności ładowni. */
const segments = computed(() => RESOURCES.map(r => ({
    key: r.key,
    color: r.color,
    width: props.capacity > 0 ? (props.cargo[r.key] / props.capacity) * 100 : 0,
})));

const loadBlock = computed<string | null>(() => {
    if (picked.value === 0) return 'wpisz ilości';
    const short = RESOURCES.find(r => amount[r.key] > props.island.stockpile[r.key]);
    if (short) return `w magazynie jest tylko ${props.island.stockpile[short.key]} (${short.genitive})`;
    if (picked.value > free.value) return `w ładowni zostało ${free.value} miejsca`;
    return null;
});

const unloadBlock = computed<string | null>(() => {
    if (picked.value === 0) return 'wpisz ilości';
    const short = RESOURCES.find(r => amount[r.key] > props.cargo[r.key]);
    return short ? `w ładowni jest tylko ${props.cargo[short.key]} (${short.genitive})` : null;
});

function set(key: ResourceKey, raw: string) {
    const n = Math.floor(Number(raw));
    amount[key] = Number.isFinite(n) && n > 0 ? n : 0;
}

/** Ile zmieści się z magazynu: najcenniejsze najpierw (złoto, stal, drewno) — jak backend przy przepełnieniu. */
function fillLoad() {
    let room = free.value;
    for (const key of ['gold', 'steel', 'wood'] as ResourceKey[]) {
        amount[key] = Math.min(props.island.stockpile[key], room);
        room -= amount[key];
    }
}

function fillUnload() {
    RESOURCES.forEach(r => { amount[r.key] = props.cargo[r.key]; });
}

function clear() {
    RESOURCES.forEach(r => { amount[r.key] = 0; });
}
</script>

<template>
    <div class="cargo-panel">
        <div class="cap">
            <div class="cap-head">
                <span class="kicker">ŁADOWNIA</span>
                <span>{{ used }} / {{ capacity }} <span class="muted">· wolne {{ free }}</span></span>
            </div>
            <div class="cap-bar" role="progressbar" :aria-valuenow="used" :aria-valuemax="capacity">
                <div v-for="s in segments" :key="s.key" :style="{ width: s.width + '%', background: s.color }"></div>
            </div>
        </div>

        <table class="hold">
            <thead><tr><th></th><th>magazyn wyspy</th><th>ilość</th><th>ładownia</th></tr></thead>
            <tbody>
                <tr v-for="r in RESOURCES" :key="r.key">
                    <th><span class="res-dot" :style="{ background: r.color }"></span> {{ r.label }}</th>
                    <td>{{ island.stockpile[r.key] }}</td>
                    <td>
                        <input
                            type="number"
                            min="0"
                            inputmode="numeric"
                            :aria-label="`ilość: ${r.label}`"
                            :value="amount[r.key] || ''"
                            placeholder="0"
                            :disabled="busy"
                            @input="set(r.key, ($event.target as HTMLInputElement).value)"
                        />
                    </td>
                    <td>{{ cargo[r.key] }}</td>
                </tr>
            </tbody>
        </table>

        <div class="fill">
            <button class="btn small" :disabled="busy" @click="fillLoad">ile się zmieści</button>
            <button class="btn small" :disabled="busy" @click="fillUnload">cała ładownia</button>
            <button class="btn small" :disabled="busy || picked === 0" @click="clear">wyczyść</button>
        </div>
        <div class="actions">
            <button class="btn" :disabled="busy || loadBlock !== null" :title="loadBlock ?? ''" @click="emit('transfer', 'load', { ...amount })">
                Załaduj → ładownia
            </button>
            <button class="btn" :disabled="busy || unloadBlock !== null" :title="unloadBlock ?? ''" @click="emit('transfer', 'unload', { ...amount })">
                ← Rozładuj do magazynu
            </button>
            <span class="muted">jedno dokowanie = 1 tik
                <template v-if="picked > 0 && loadBlock && unloadBlock"> · {{ loadBlock }}</template>
            </span>
        </div>
    </div>
</template>

<style scoped>
.cargo-panel { margin: 0.3rem 0 0.8rem; }
.cap { max-width: 26rem; }
.cap-head { display: flex; justify-content: space-between; font: 600 12px var(--font-mono); }
.kicker { font: 600 10px var(--font-mono); letter-spacing: .12em; color: var(--text-label); }
.cap-bar { display: flex; height: 8px; margin-top: 4px; background: var(--bg-panel); border: 1px solid var(--line-card); border-radius: 5px; overflow: hidden; }
.hold { border-collapse: collapse; margin: 0.5rem 0; font-size: 14px; }
.hold th, .hold td { padding: 4px 10px; text-align: left; }
.hold thead th { font: 600 10px var(--font-mono); letter-spacing: .12em; text-transform: uppercase; color: var(--text-label); }
.hold td { font-weight: 600; }
.hold input { width: 5rem; padding: 4px 8px; border: 1px solid var(--line-btn); border-radius: 8px; background: var(--bg-panel); color: var(--text); font: 600 13px var(--font-mono); }
.res-dot { width: 10px; height: 10px; border-radius: 3px; display: inline-block; vertical-align: middle; }
.fill, .actions { display: flex; flex-wrap: wrap; gap: 6px 8px; align-items: center; margin-top: 0.4rem; }
.btn { padding: 9px 16px; background: var(--accent); color: var(--accent-ink); border: 0; border-radius: 10px; cursor: pointer; font: 700 14px var(--font-head); }
.btn.small { padding: 5px 10px; font-size: 12px; border-radius: 8px; background: var(--bg-card-active); color: var(--text); border: 1px solid var(--line-btn); }
.btn[disabled] { background: var(--bg-disabled); color: var(--text-dim); cursor: not-allowed; }
.muted { color: var(--text-muted); font-size: 0.85rem; }
</style>
