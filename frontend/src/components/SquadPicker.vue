<!--
    Skład bitewny w limicie pól (#73): podsumowanie, przełącznik ręcznego
    wyboru, zestawy floty (#125) i lista sprawnych statków. Wspólny dla
    Rejsu i Portu (#166 D3) — stan wyboru trzyma rodzic (v-model:pick,
    v-model:preset-id), bo od składu zależy jego przycisk startu bitwy.
-->
<script setup lang="ts">
import { computed } from 'vue';
import { SHIP_ICONS, fleetShipLabel, type FleetPresetDTO, type FleetShipDTO } from '../api/expeditionApi';
import { activeShips, convoyBlocked, defaultSquad, leavesShipsInPort, squadCells, squadChoiceNeeded, squadOf } from '../lib/squad';
import { fieldsLabel } from '../lib/ships';
import FleetPresetPicker from './FleetPresetPicker.vue';

const props = withDefaults(defineProps<{
    fleet: FleetShipDTO[];
    limit: number;
    pick: Set<string> | null;
    presetId?: string | null;
    presets?: FleetPresetDTO[];
    // co się dzieje ze statkami ponad limit i z konwojem bez eskorty — zależy od celu bitwy
    overflow?: string;
    convoy?: string;
}>(), {
    presetId: null,
    presets: () => [],
    overflow: 'część zostanie w porcie',
    convoy: 'nie zaatakuje wyspy',
});

const emit = defineEmits<{
    'update:pick': [pick: Set<string> | null];
    'update:presetId': [presetId: string | null];
}>();

const ships = computed(() => activeShips(props.fleet));
const fleetCells = computed(() => squadCells(ships.value));
const squad = computed(() => squadOf(props.fleet, props.limit, props.pick));
const cells = computed(() => squadCells(squad.value));

function toggle() {
    emit('update:pick', props.pick ? null : new Set(defaultSquad(props.fleet, props.limit).map(s => s.id)));
}

function toggleShip(shipId: string) {
    if (!props.pick) return;
    const next = new Set(props.pick);
    if (next.has(shipId)) next.delete(shipId); else next.add(shipId);
    emit('update:pick', next);
}

/** Zestaw floty (#125) ustawia skład; bez zestawu skład wraca do domyślnego. */
function onPickPreset(presetId: string | null, shipIds: string[] | null) {
    emit('update:pick', shipIds ? new Set(shipIds) : null);
    emit('update:presetId', presetId);
}

const tooBig = (ship: FleetShipDTO) => !props.pick?.has(ship.id) && cells.value + ship.length > props.limit;
</script>

<template>
    <div class="squad">
        <span :class="{ over: fleetCells > limit }">
            skład: {{ cells }} / {{ limit }} pól
            <template v-if="fleetCells > limit"> · flota ma {{ fleetCells }} pól — {{ overflow }}</template>
            <template v-else-if="!pick && leavesShipsInPort(fleet, limit)"> · transportowiec płynie tylko wybrany ręcznie</template>
        </span>
        <button v-if="squadChoiceNeeded(fleet, limit)" class="btn btn-small btn-secondary" @click="toggle">
            {{ pick ? 'skład domyślny' : 'wybierz skład' }}
        </button>
        <FleetPresetPicker
            v-if="presets.length"
            :presets="presets"
            :fleet="fleet"
            :limit="limit"
            :selected="presetId"
            @pick="onPickPreset"
        />
        <ul v-if="pick" class="squad-pick">
            <li v-for="ship in ships" :key="ship.id">
                <label :class="{ off: tooBig(ship) }">
                    <input type="checkbox" :checked="pick.has(ship.id)" :disabled="tooBig(ship)" @change="toggleShip(ship.id)" />
                    {{ SHIP_ICONS[ship.type] }} {{ fleetShipLabel(ship) }} · {{ fieldsLabel(ship.length) }}
                </label>
            </li>
        </ul>
        <span v-if="convoyBlocked(fleet, limit, pick)" class="over">⚠️ konwój bez eskorty {{ convoy }} — dobierz kuter, niszczyciel albo lotniskowiec</span>
    </div>
</template>

<style scoped>
.squad { display: flex; flex-wrap: wrap; gap: 0.5rem 0.8rem; align-items: center; font: 500 12px var(--font-mono); color: var(--text-muted); }
.over { color: var(--warn); }
.squad-pick { list-style: none; padding: 0; margin: 0; width: 100%; display: flex; flex-wrap: wrap; gap: 6px 14px; }
.squad-pick label { cursor: pointer; color: var(--text-2); }
.squad-pick label.off { opacity: .45; cursor: not-allowed; }
</style>
