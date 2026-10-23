<!--
    Flota (zakładka Rejsu, #111): jednostki zgrupowane po typie i wariancie —
    liczba sztuk, rola, długość segmentami i stan. Remont uszkodzonego statku
    grupy w stoczni portu; powód blokady liczy widok (zna wyspy i magazyny).
-->
<script setup lang="ts">
import { computed } from 'vue';
import {
    fleetShipLabel,
    type FleetShipDTO, type ShipTypeDTO,
} from '../api/expeditionApi';
import { VARIANTS, carriedWeapon } from '../lib/ships';
import { formatResources } from '../lib/resources';

const props = defineProps<{
    fleet: FleetShipDTO[];
    shipTypes: ShipTypeDTO[];
    busy: boolean;
    unescortedConvoy: boolean;
    // powód blokady remontu; null = można remontować
    repairBlock: (ship: FleetShipDTO) => string | null;
}>();

const emit = defineEmits<{
    repair: [ship: FleetShipDTO];
}>();

// od największego kadłuba, jak w składzie bitewnym
const ORDER = ['lotniskowiec', 'niszczyciel', 'transportowiec', 'kuter', 'tratwa'];

interface Group {
    key: string;
    label: string;
    count: number;
    length: number;
    role: string;
    damaged: FleetShipDTO[];
}

const groups = computed<Group[]>(() => {
    const byKey = new Map<string, Group>();
    for (const ship of props.fleet) {
        const key = `${ship.type}:${ship.variant ?? ''}`;
        let group = byKey.get(key);
        if (!group) {
            group = { key, label: fleetShipLabel(ship), count: 0, length: ship.length, role: role(ship), damaged: [] };
            byKey.set(key, group);
        }
        group.count++;
        if (ship.damaged) group.damaged.push(ship);
    }
    return [...byKey.values()].sort((a, b) => ORDER.indexOf(a.key.split(':')[0]) - ORDER.indexOf(b.key.split(':')[0]) || a.label.localeCompare(b.label));
});

const damagedCount = computed(() => props.fleet.filter(s => s.damaged).length);

function role(ship: FleetShipDTO): string {
    const type = props.shipTypes.find(t => t.type === ship.type);
    const weapon = carriedWeapon(ship.length, ship.variant ? VARIANTS[ship.variant].w : undefined);
    const arms = type?.armed ? `nośnik · ${weapon ?? 'strzał'}` : ship.type === 'transportowiec' ? 'bez broni · wymaga eskorty' : 'bez uzbrojenia';
    return type ? `${arms} · ładownia ${type.cargoCapacity}` : arms;
}

/** „1 jednostka”, „3 jednostki”, „27 jednostek”. */
function unitsLabel(n: number): string {
    if (n === 1) return '1 jednostka';
    const last = n % 10;
    const lastTwo = n % 100;
    return last >= 2 && last <= 4 && (lastTwo < 12 || lastTwo > 14) ? `${n} jednostki` : `${n} jednostek`;
}
</script>

<template>
    <div class="fleet">
        <div class="head">
            <h3>{{ unitsLabel(fleet.length) }}</h3>
            <span v-if="damagedCount === 0" class="tag ok">Wszystkie sprawne</span>
            <span v-else class="tag dmg">Uszkodzone: {{ damagedCount }}</span>
        </div>
        <p v-if="unescortedConvoy" class="warn">⚠️ Konwój bez eskorty — podwójne straty w sztormie. Eskortuje sprawny kuter, niszczyciel albo lotniskowiec.</p>

        <ul class="groups">
            <li v-for="g in groups" :key="g.key" class="group" :class="{ dmg: g.damaged.length > 0 }">
                <div class="count">{{ g.count }}<span>×</span></div>
                <div>
                    <div class="name">{{ g.label }}</div>
                    <div class="role">{{ g.role }}</div>
                </div>
                <div class="len" :title="`${g.length}-masztowiec`">
                    <span v-for="i in g.length" :key="i"></span>
                </div>
                <div v-if="g.damaged.length" class="repair">
                    <span class="state" title="wozi ładunek, ale nie walczy i nie eskortuje — do remontu w stoczni">🔧 uszkodzone: {{ g.damaged.length }} z {{ g.count }}</span>
                    <button
                        class="btn btn-small btn-secondary"
                        :disabled="busy || repairBlock(g.damaged[0]) !== null"
                        :title="repairBlock(g.damaged[0]) ?? ''"
                        @click="emit('repair', g.damaged[0])"
                    >
                        Remont 1 szt. ({{ formatResources(g.damaged[0].repairCost) }})
                    </button>
                    <span v-if="repairBlock(g.damaged[0])" class="block">{{ repairBlock(g.damaged[0]) }}</span>
                </div>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.fleet { display: flex; flex-direction: column; gap: 14px; }
.head { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; }
.head h3 { margin: 0; font: 700 22px var(--font-head); }
.tag { padding: 4px 10px; border-radius: 999px; font: 600 10px var(--font-mono); letter-spacing: .1em; text-transform: uppercase; white-space: nowrap; }
.tag.ok { background: var(--bg-card-active); border: 1px solid var(--line-strong); color: var(--info); }
.tag.dmg { background: #2a2418; border: 1px solid #5a4a28; color: var(--warn); }
.warn { margin: 0; font: 500 12px var(--font-mono); color: var(--warn); }
.groups { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.group { display: grid; grid-template-columns: 48px minmax(0, 1fr) auto; align-items: center; gap: 4px 14px; padding: 12px 14px; border-radius: 12px; background: var(--bg-card); border: 1px solid var(--line-card); }
.group.dmg { border-color: #5a4a28; }
.count { font: 700 20px var(--font-head); }
.count span { font: 500 12px var(--font-mono); color: var(--text-label); }
.name { font: 600 15px var(--font-head); }
.role { margin-top: 2px; font: 500 11px var(--font-mono); color: var(--text-muted); }
.len { display: flex; gap: 3px; }
.len span { width: 12px; height: 8px; border-radius: 2px; background: #4b7a89; }
.group.dmg .len span { background: var(--warn); }
.repair { grid-column: 2 / -1; display: flex; flex-wrap: wrap; gap: 6px 10px; align-items: center; }
.state { font: 500 12px var(--font-mono); color: var(--warn); }
.block { font: 500 11px var(--font-mono); color: var(--text-muted); }
</style>
