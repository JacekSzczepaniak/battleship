<!--
    Przeładunek w porcie (#49, układ z projektu Rejs #111): magazyn wyspy ⇄
    ładownia floty w tabeli. ‹ i › przenoszą od razu ilość z pola danego
    surowca (puste pole = 1), przycięte do zapasu i wolnego miejsca; każde
    przeniesienie to jedno dokowanie, czyli 1 tik. Akcje zbiorcze też po 1 tiku.
    Limity sprawdza też backend.
-->
<script setup lang="ts">
import { computed, reactive, watch } from 'vue';
import type { IslandDTO, ResourcesDTO } from '../api/expeditionApi';
import { RESOURCES, isEmpty, totalResources, type ResourceKey } from '../lib/resources';

const props = defineProps<{
    // wyspa albo port handlowy (#140) — panel potrzebuje tylko magazynu
    island: Pick<IslandDTO, 'stockpile'>;
    cargo: ResourcesDTO;
    capacity: number;
    busy: boolean;
}>();

const emit = defineEmits<{
    transfer: [direction: 'load' | 'unload', amount: ResourcesDTO];
}>();

// wpisane ilości; 0 = pole puste (przeniesie 1)
const typed = reactive<ResourcesDTO>({ wood: 0, steel: 0, gold: 0 });

// po przeładunku (nowy stan ładowni lub magazynu) pola wracają do pustych
watch(() => [props.cargo, props.island.stockpile], () => RESOURCES.forEach(r => { typed[r.key] = 0; }));

const free = computed(() => Math.max(0, props.capacity - totalResources(props.cargo)));

function set(key: ResourceKey, raw: string) {
    const n = Math.floor(Number(raw));
    typed[key] = Number.isFinite(n) && n > 0 ? n : 0;
}

/** Ile naprawdę popłynie: wpisana ilość (puste = 1) przycięta do zapasu i wolnego miejsca. */
function movable(key: ResourceKey, direction: 'load' | 'unload'): number {
    const wanted = typed[key] || 1;
    return direction === 'unload'
        ? Math.min(wanted, props.cargo[key])
        : Math.min(wanted, props.island.stockpile[key], free.value);
}

function moveTitle(key: ResourceKey, direction: 'load' | 'unload'): string {
    const n = movable(key, direction);
    if (direction === 'unload') return n > 0 ? `na wyspę: ${n} (1 tik)` : 'ładownia nie ma tego surowca';
    if (n > 0) return `na statki: ${n} (1 tik)`;
    return props.island.stockpile[key] === 0 ? 'magazyn wyspy nie ma tego surowca' : 'ładownia pełna';
}

function move(key: ResourceKey, direction: 'load' | 'unload') {
    const amount: ResourcesDTO = { wood: 0, steel: 0, gold: 0 };
    amount[key] = movable(key, direction);
    if (amount[key] > 0) emit('transfer', direction, amount);
}

/** Ile zmieści się z magazynu: najcenniejsze najpierw (złoto, stal, drewno) — jak backend przy przepełnieniu. */
const fillLoad = computed<ResourcesDTO>(() => {
    const amount: ResourcesDTO = { wood: 0, steel: 0, gold: 0 };
    let room = free.value;
    for (const key of ['gold', 'steel', 'wood'] as ResourceKey[]) {
        amount[key] = Math.min(props.island.stockpile[key], room);
        room -= amount[key];
    }
    return amount;
});
</script>

<template>
    <div class="cargo-panel">
        <div class="head">
            <span class="kicker">Przeładunek</span>
            <span class="free">wolne w ładowni: <b>{{ free }}</b></span>
        </div>

        <div class="hold" role="table" aria-label="Przeładunek: magazyn wyspy i ładownia">
            <div class="th" role="columnheader">Surowiec</div>
            <div class="th num" role="columnheader">Wyspa</div>
            <div class="th mid" role="columnheader">← Przenieś →</div>
            <div class="th num" role="columnheader">Statki</div>
            <template v-for="r in RESOURCES" :key="r.key">
                <div class="td name"><span class="res-dot" :style="{ background: r.color }"></span>{{ r.label }}</div>
                <div class="td num island">{{ island.stockpile[r.key] }}</div>
                <div class="td mid">
                    <button
                        class="step"
                        :aria-label="`${r.label}: na wyspę`"
                        :title="moveTitle(r.key, 'unload')"
                        :disabled="busy || movable(r.key, 'unload') === 0"
                        @click="move(r.key, 'unload')"
                    >‹</button>
                    <input
                        type="number"
                        min="0"
                        inputmode="numeric"
                        :aria-label="`ilość: ${r.label}`"
                        :value="typed[r.key] || ''"
                        placeholder="1"
                        :disabled="busy"
                        @input="set(r.key, ($event.target as HTMLInputElement).value)"
                    />
                    <button
                        class="step"
                        :aria-label="`${r.label}: na statki`"
                        :title="moveTitle(r.key, 'load')"
                        :disabled="busy || movable(r.key, 'load') === 0"
                        @click="move(r.key, 'load')"
                    >›</button>
                </div>
                <div class="td num">{{ cargo[r.key] }}</div>
            </template>
        </div>

        <div class="bulk">
            <button class="btn btn-primary" :disabled="busy || isEmpty(cargo)" @click="emit('transfer', 'unload', { ...cargo })">
                Rozładuj wszystko na wyspę
            </button>
            <button class="btn btn-secondary" :disabled="busy || isEmpty(fillLoad)" :title="isEmpty(fillLoad) ? (free === 0 ? 'ładownia pełna' : 'magazyn wyspy jest pusty') : ''" @click="emit('transfer', 'load', fillLoad)">
                Załaduj ile się zmieści
            </button>
        </div>
        <p class="note">Każde przeniesienie to jedno dokowanie — 1 tik. Puste pole przenosi 1 sztukę.</p>
    </div>
</template>

<style scoped>
.head { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; margin-bottom: 10px; }
.free { font: 500 11px var(--font-mono); color: var(--text-muted); }
.free b { color: var(--text); }
.hold { display: grid; grid-template-columns: minmax(0, 1fr) auto auto auto; border: 1px solid var(--line-card); border-radius: 12px; overflow: hidden; }
.th { padding: 9px 6px; background: var(--bg-panel); font: 600 10px var(--font-mono); letter-spacing: .12em; text-transform: uppercase; color: var(--text-label); }
.th:first-child { padding-left: 14px; }
.th:last-child { padding-right: 14px; }
.td { padding: 12px 6px; border-top: 1px solid var(--line); font: 600 14px var(--font-mono); display: flex; align-items: center; }
.td.name { padding-left: 14px; gap: 9px; font-family: var(--font-head); }
.td:nth-child(4n) { padding-right: 14px; }
.num { justify-content: flex-end; text-align: right; }
.island { color: var(--text-muted); }
.mid { justify-content: center; text-align: center; gap: 4px; padding: 8px 6px; }
.res-dot { width: 12px; height: 12px; }
.step { width: 30px; height: 30px; border-radius: 8px; border: 1px solid var(--line-btn); background: var(--bg-card); color: var(--text-2); cursor: pointer; font: 600 16px var(--font-body); }
.step:hover:not(:disabled) { border-color: var(--info); color: var(--text); }
.step:disabled { opacity: .4; cursor: not-allowed; }
.mid input { width: 48px; height: 30px; border-radius: 8px; border: 1px solid var(--line-btn); background: var(--bg-panel); color: var(--text); text-align: center; font: 600 13px var(--font-mono); -moz-appearance: textfield; appearance: textfield; }
.mid input::-webkit-outer-spin-button, .mid input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.bulk { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
.bulk .btn { padding: 10px 16px; border-radius: 10px; font-size: 13px; }
.note { margin: 8px 0 0; font: 400 12px var(--font-body); color: var(--text-muted); }
</style>
