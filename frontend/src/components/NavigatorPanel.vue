<!--
    Nawigator (#153): cel trasy z listy znanych miejsc albo kliknięciem w odkryty
    sektor, przełącznik omijania nieodwiedzonych sektorów i plan przed startem:
    koszt w tikach, sektory z ryzykiem sztormu, zdążenie przed kontratakiem.
    Trasę płynie Expedition.vue zwykłymi rejsami i bramami, krok po kroku;
    tu tylko stan żeglugi (krok, powód zatrzymania) i przyciski.
-->
<script setup lang="ts">
import { computed } from 'vue';
import type { NavigatorDestinationDTO, NavigatorDTO, VoyagePlanDTO } from '../api/expeditionApi';
import { ticksLabel } from '../lib/counterattack';

export interface NavTarget {
    sea: string;
    x: number;
    y: number;
    label: string;
}

export interface NavVoyage {
    done: number;
    total: number;
    // powód zatrzymania (sztorm, spotkanie, kontratak, dokowanie); null = w drodze
    paused: string | null;
}

const props = defineProps<{
    navigator: NavigatorDTO;
    target: NavTarget | null;
    plan: VoyagePlanDTO | null;
    loading: boolean;
    avoid: boolean;
    picking: boolean;
    voyage: NavVoyage | null;
    busy: boolean;
    // co wstrzymuje żeglugę (spotkanie, bitwa, ekspedycja w głąb lądu); null = można płynąć
    blocked: string | null;
    counterattackName: string | null;
}>();

const emit = defineEmits<{
    target: [target: NavTarget | null];
    pick: [];
    avoid: [avoid: boolean];
    start: [];
    continue: [];
    cancel: [];
}>();

const KIND_ICONS: Record<NavigatorDestinationDTO['kind'], string> = { island: '🏝️', port: '⚖️', wild: '🌴', gate: '🌀' };

const groups = computed(() => {
    const bySea = new Map<string, { name: string; items: NavigatorDestinationDTO[] }>();
    for (const d of props.navigator.destinations) {
        const group = bySea.get(d.sea) ?? { name: d.seaName, items: [] };
        group.items.push(d);
        bySea.set(d.sea, group);
    }
    return [...bySea.values()];
});

const keyOf = (t: { sea: string; x: number; y: number }) => `${t.sea}:${t.x}:${t.y}`;
const selected = computed(() => (props.target ? keyOf(props.target) : ''));

function onSelect(e: Event) {
    const key = (e.target as HTMLSelectElement).value;
    const d = props.navigator.destinations.find(item => keyOf(item) === key);
    emit('target', d ? { sea: d.sea, x: d.x, y: d.y, label: `${d.name} (${d.seaName})` } : null);
}

const route = computed(() => props.plan?.route ?? null);
const gates = computed(() => route.value?.steps.filter(s => s.type === 'gate').length ?? 0);

const stormText = computed(() => {
    const r = route.value;
    if (!r) return '';
    if (r.stormSectors === 0) return 'bez ryzyka sztormu — każdy sektor trasy był już odwiedzony';
    const chances = [...new Set(Object.values(r.stormChance))].sort((a, b) => a - b).map(c => `${c}%`).join(' / ');
    return `ryzyko sztormu na ${r.stormSectors} ${r.stormSectors === 1 ? 'sektorze' : 'sektorach'} (${chances} przy pierwszym wpłynięciu)`;
});

const reasonText = computed(() => {
    switch (props.plan?.reason) {
        case 'undiscovered': return 'Cel leży w niezbadanych wodach — Nawigator prowadzi tylko po odkrytych sektorach.';
        case 'no-route': return 'Nie ma trasy po odkrytych sektorach i przez otwarte bramy.';
        case 'no-safe-route': return 'Nie ma trasy bez ryzyka sztormu — cel albo każda droga prowadzi przez nieodwiedzony sektor. Wyłącz omijanie, aby płynąć najkrótszą.';
        default: return '';
    }
});

const counterattackText = computed(() => {
    const c = props.plan?.counterattack;
    if (!c || !route.value) return null;
    const verdict = c.targetsIsland
        ? (c.inTime ? 'na zaatakowaną wyspę zdążysz przed uderzeniem' : 'na zaatakowaną wyspę nie zdążysz przed uderzeniem')
        : (c.inTime ? 'trasa skończy się przed uderzeniem' : 'trasa skończy się po uderzeniu');
    return {
        text: `Kontratak: ${props.counterattackName ?? c.island} — uderzenie za ${ticksLabel(c.ticksLeft)} (tik ${c.strikeAt}); ${verdict}.`,
        inTime: c.inTime,
    };
});

/** „1 krok”, „3 kroki”, „5 kroków” — odmiana jak ticksLabel. */
function stepsLabel(n: number): string {
    return ticksLabel(n).replace(/tik(i|ów)?$/, m => ({ tik: 'krok', tiki: 'kroki', 'tików': 'kroków' })[m] ?? m);
}

const canStart = computed(() => !!route.value && route.value.ticks > 0 && !props.loading && !props.busy && !props.blocked);
</script>

<template>
    <section class="navigator" aria-label="Nawigator">
        <div class="head">
            <strong>🧭 Nawigator</strong>
            <span class="muted">trasa po odkrytych sektorach i przez otwarte bramy · każdy krok to zwykły rejs za 1 tik</span>
        </div>

        <div v-if="!voyage" class="controls">
            <select :value="selected" :disabled="busy" aria-label="cel trasy" @change="onSelect">
                <option value="">— wybierz cel —</option>
                <option v-if="target && !navigator.destinations.some(d => keyOf(d) === selected)" :value="selected">{{ target.label }}</option>
                <optgroup v-for="g in groups" :key="g.name" :label="g.name">
                    <option v-for="d in g.items" :key="keyOf(d)" :value="keyOf(d)">{{ KIND_ICONS[d.kind] }} {{ d.name }}</option>
                </optgroup>
            </select>
            <button class="btn btn-small btn-secondary" :class="{ on: picking }" :disabled="busy" @click="emit('pick')">
                {{ picking ? 'kliknij sektor na mapie…' : '🎯 cel na mapie' }}
            </button>
            <label class="avoid">
                <input type="checkbox" :checked="avoid" :disabled="busy" @change="emit('avoid', ($event.target as HTMLInputElement).checked)" />
                omijaj nieodwiedzone sektory
            </label>
        </div>

        <template v-if="target">
            <p v-if="loading" class="muted">Wyznaczam trasę do: {{ target.label }}…</p>
            <p v-else-if="reasonText" class="warn">⚠️ {{ reasonText }}</p>
            <template v-else-if="route">
                <p v-if="route.ticks === 0" class="muted">Flota już stoi na celu: {{ target.label }}.</p>
                <template v-else>
                    <p class="summary">
                        <b>{{ target.label }}</b>: {{ ticksLabel(route.ticks) }}
                        ({{ stepsLabel(route.ticks) }}<template v-if="gates"> · {{ gates === 1 ? 'przez bramę' : `przez ${gates} bramy` }}</template>)
                        · dopłyniesz na tiku {{ route.arrivalTick }}
                    </p>
                    <p :class="route.stormSectors ? 'warn' : 'muted'">{{ route.stormSectors ? '⛈️' : '☀️' }} {{ stormText }}</p>
                    <p v-if="counterattackText" :class="counterattackText.inTime ? 'muted' : 'danger'">⚔️ {{ counterattackText.text }}</p>
                </template>
            </template>
        </template>

        <div class="actions">
            <template v-if="voyage">
                <span class="progress">krok {{ voyage.done }} / {{ voyage.total }}</span>
                <span v-if="voyage.paused" class="warn">zatrzymano: {{ voyage.paused }}</span>
                <span v-else class="muted">w drodze…</span>
                <span v-if="voyage.paused && blocked" class="muted">{{ blocked }}</span>
                <button
                    v-if="voyage.paused"
                    class="btn btn-small btn-primary"
                    :disabled="!canStart"
                    @click="emit('continue')"
                >⛵ Płyń dalej</button>
                <button class="btn btn-small btn-secondary" @click="emit('cancel')">✕ Anuluj</button>
            </template>
            <template v-else>
                <span v-if="blocked && route" class="muted">{{ blocked }}</span>
                <button v-if="target" class="btn btn-small btn-primary" :disabled="!canStart" @click="emit('start')">⛵ Płyń trasą</button>
                <button v-if="target" class="btn btn-small btn-secondary" :disabled="busy" @click="emit('target', null)">wyczyść</button>
            </template>
        </div>
    </section>
</template>

<style scoped>
.navigator { margin: 0; padding: 11px 18px; border-radius: 12px; border: 1px solid var(--line-card); background: var(--bg-card); display: flex; flex-direction: column; gap: 8px; }
.head { display: flex; flex-wrap: wrap; gap: 6px 12px; align-items: baseline; }
.controls, .actions { display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: center; }
select { min-width: 240px; max-width: 100%; padding: 5px 8px; border-radius: 8px; border: 1px solid var(--line-btn); background: var(--bg-card-off); color: var(--text); font: 500 13px var(--font-body); }
.btn.on { border-color: var(--accent-light); color: var(--accent-light); }
.avoid { display: inline-flex; gap: 6px; align-items: center; font: 500 13px var(--font-body); color: var(--text-2); cursor: pointer; }
p { margin: 0; font: 400 13px var(--font-body); }
.summary b { color: var(--text); }
.muted { color: var(--text-muted); }
.warn { color: var(--warn); }
.danger { color: var(--danger); }
.progress { font: 600 12px var(--font-mono); color: var(--info); }
</style>
