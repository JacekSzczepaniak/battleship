<!--
    Osada na posiadanej wyspie (#80): stojące budynki i te, które można
    postawić, z kosztem i powodem blokady. Reguły (bandera, obecność, złoże,
    jeden budynek typu, koszt) liczy backend — tu tylko opisujemy jego kod.
-->
<script setup lang="ts">
import { computed } from 'vue';
import {
    BUILDING_ICONS, BUILDING_LABELS,
    type BuildingBlockCode, type BuildingTypeDTO, type BuildingTypeName, type IslandDTO, type ResourcesDTO,
} from '../api/expeditionApi';
import { depositLabel } from '../lib/economy';
import { formatResources, missing } from '../lib/resources';

const props = defineProps<{
    island: IslandDTO;
    buildingTypes: BuildingTypeDTO[];
    // magazyn wyspy + ładownia — tym płaci się za budowę
    available: ResourcesDTO;
    busy: boolean;
}>();

const emit = defineEmits<{
    build: [type: BuildingTypeName];
}>();

const EFFECTS: Record<BuildingTypeName, string> = {
    tartak: 'podwaja urobek złoża drewna',
    kopalnia: 'podwaja urobek złoża stali i złota',
    'rozbudowa-stoczni': 'podnosi poziom stoczni o 1',
    fort: 'kontratak bez obrony plądruje połowę magazynu zamiast odebrać wyspę',
    uczelnia: 'prowadzi badania drzewka technologii — naraz jedno',
};

const SITES: Record<BuildingTypeName, string> = {
    tartak: 'stawia się tylko przy złożu drewna',
    kopalnia: 'stawia się tylko przy złożu stali albo złota',
    'rozbudowa-stoczni': 'tylko przy istniejącej stoczni poziomu 1–2',
    fort: 'nie w bezpiecznej przystani — kontrataki jej nie dotyczą',
    uczelnia: 'stoi na każdej posiadanej wyspie',
};

const standing = computed(() => props.buildingTypes.filter(t => props.island.buildings.includes(t.type)));
const options = computed(() => props.buildingTypes.filter(t => !props.island.buildings.includes(t.type)));

/** Stan stojącego budynku w liczbach (#130): urobek złoża już z podwojeniem, poziom stoczni. */
function standingState(t: BuildingTypeDTO): string {
    switch (t.type) {
        case 'tartak':
        case 'kopalnia': return props.island.deposit ? `urobek złoża: ${depositLabel(props.island)}` : '';
        case 'rozbudowa-stoczni': return `stocznia poziomu ${props.island.shipyardLevel}`;
        case 'fort': return 'chroni wyspę przed utratą pod Twoją nieobecność';
        case 'uczelnia': return 'badanie zaczniesz tutaj — zakładka Badania';
    }
}

function blockLabel(t: BuildingTypeDTO): string | null {
    const code: BuildingBlockCode | null = props.island.buildBlockers[t.type];
    switch (code) {
        case null: return null;
        case 'not-owned': return 'wyspa bez Twojej bandery';
        case 'already-built': return 'już stoi';
        case 'site': return SITES[t.type];
        case 'not-present': return 'dopłyń na wyspę';
        case 'resources': return missing(props.available, t.buildCost);
    }
}
</script>

<template>
    <div class="settlement">
        <ul v-if="standing.length" class="buildings">
            <li v-for="t in standing" :key="t.type">
                <span class="name">{{ BUILDING_ICONS[t.type] }} {{ BUILDING_LABELS[t.type] }}</span>
                <span :class="island.owned ? 'on' : 'muted'">
                    {{ EFFECTS[t.type] }}{{ island.owned ? '' : ' — nieczynny bez bandery' }}
                </span>
                <span v-if="island.owned && standingState(t)" class="state">{{ standingState(t) }}</span>
            </li>
        </ul>
        <p v-else class="muted">Na wyspie nie stoi jeszcze żaden budynek.</p>

        <ul v-if="options.length" class="buildings">
            <li v-for="t in options" :key="t.type" :class="{ off: blockLabel(t) !== null }">
                <span class="name">{{ BUILDING_ICONS[t.type] }} {{ BUILDING_LABELS[t.type] }}</span>
                <span class="muted">{{ EFFECTS[t.type] }}</span>
                <button
                    class="btn small"
                    :disabled="busy || blockLabel(t) !== null"
                    :title="blockLabel(t) ?? ''"
                    @click="emit('build', t.type)"
                >
                    Buduj ({{ formatResources(t.buildCost) }})
                </button>
                <span v-if="blockLabel(t)" class="block">{{ blockLabel(t) }}</span>
            </li>
        </ul>
        <p class="muted">Budowa jest natychmiastowa: płaci magazyn wyspy, brakującą resztę ładownia. Każdy budynek raz na wyspę.</p>
    </div>
</template>

<style scoped>
.settlement { display: flex; flex-direction: column; gap: 8px; }
.buildings { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; }
.buildings li { display: flex; gap: 4px 10px; align-items: center; padding: 8px 0; flex-wrap: wrap; border-bottom: 1px solid var(--line); }
.buildings li.off .name { opacity: 0.6; }
.name { flex: 0 0 11rem; font: 600 14px var(--font-head); }
.on { flex: 1 1 12rem; color: var(--info); font-size: 12px; }
.muted { color: var(--text-muted); font-size: 12px; margin: 0; }
.buildings .muted { flex: 1 1 12rem; }
.state { flex-basis: 100%; font: 500 11px var(--font-mono); color: var(--text-2); }
.block { flex-basis: 100%; font: 500 11px var(--font-mono); color: var(--text-muted); }
.btn { padding: 6px 10px; font: 600 11px var(--font-mono); border-radius: 8px; background: var(--bg-card); color: var(--text); border: 1px solid var(--line-btn); cursor: pointer; }
.btn:hover:not([disabled]) { border-color: var(--info); }
.btn[disabled] { background: var(--bg-disabled); color: var(--text-dim); border-color: transparent; cursor: not-allowed; }
</style>
