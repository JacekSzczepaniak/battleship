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
.settlement { margin: 0.3rem 0 0.8rem; }
.buildings { list-style: none; padding: 0; margin: 0 0 0.4rem; }
.buildings li { display: flex; gap: 0.6rem; align-items: center; padding: 0.3rem 0; flex-wrap: wrap; }
.buildings li.off .name { opacity: 0.6; }
.name { min-width: 12rem; }
.on { color: var(--info); font-size: 0.9rem; }
.block { color: var(--text-muted); font-size: 0.85rem; }
.btn { padding: 5px 10px; font: 700 12px var(--font-head); border-radius: 8px; background: var(--bg-card-active); color: var(--text); border: 1px solid var(--line-btn); cursor: pointer; }
.btn[disabled] { background: var(--bg-disabled); color: var(--text-dim); cursor: not-allowed; }
.muted { color: var(--text-muted); font-size: 0.85rem; }
</style>
