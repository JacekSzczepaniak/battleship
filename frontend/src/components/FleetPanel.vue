<!--
    Flota (zakładka Rejsu, #111): jednostki zgrupowane po typie i wariancie —
    liczba sztuk, rola, długość segmentami i stan. Remont i złomowanie (#124)
    statku grupy w stoczni portu; powód blokady liczy widok (zna wyspy i magazyny).
    Przełącznik napraw kutrów remontowych poza stocznią (#152), gdy flota ma taki kuter.
-->
<script setup lang="ts">
import { computed } from 'vue';
import {
    fleetShipLabel,
    type FleetShipDTO, type ShipTypeDTO,
} from '../api/expeditionApi';
import { VARIANTS, carriedWeapon } from '../lib/ships';
import { formatResources } from '../lib/resources';
import ScrapButton from './ScrapButton.vue';

const props = defineProps<{
    fleet: FleetShipDTO[];
    shipTypes: ShipTypeDTO[];
    busy: boolean;
    unescortedConvoy: boolean;
    // naprawy kutrów remontowych poza stocznią (#152)
    fieldRepairs: boolean;
    // powód blokady remontu; null = można remontować
    repairBlock: (ship: FleetShipDTO) => string | null;
    // powód blokady złomowania; null = można złomować
    scrapBlock: (ship: FleetShipDTO) => string | null;
}>();

const emit = defineEmits<{
    repair: [ship: FleetShipDTO];
    scrap: [ship: FleetShipDTO];
    fieldRepairs: [enabled: boolean];
}>();

// od największego kadłuba, jak w składzie bitewnym
const ORDER = ['lotniskowiec', 'niszczyciel', 'transportowiec', 'kuter', 'tratwa'];

interface Group {
    key: string;
    label: string;
    count: number;
    length: number;
    role: string;
    sound: FleetShipDTO[];
    damaged: FleetShipDTO[];
}

const groups = computed<Group[]>(() => {
    const byKey = new Map<string, Group>();
    for (const ship of props.fleet) {
        const key = `${ship.type}:${ship.variant ?? ''}`;
        let group = byKey.get(key);
        if (!group) {
            group = { key, label: fleetShipLabel(ship), count: 0, length: ship.length, role: role(ship), sound: [], damaged: [] };
            byKey.set(key, group);
        }
        group.count++;
        (ship.damaged ? group.damaged : group.sound).push(ship);
    }
    return [...byKey.values()].sort((a, b) => ORDER.indexOf(a.key.split(':')[0]) - ORDER.indexOf(b.key.split(':')[0]) || a.label.localeCompare(b.label));
});

const damagedCount = computed(() => props.fleet.filter(s => s.damaged).length);

// kutry remontowe (#152): naprawia tylko sprawny, jeden statek do 3 pól na tik
const repairers = computed(() => props.fleet.filter(s => s.variant === 'kuter-remontowy'));
const workingRepairers = computed(() => repairers.value.filter(s => !s.damaged).length);

/** Statek do złomowania: pierwszy bez blokady domeny (np. nie z nierozliczonej bitwy). */
function scrapCandidate(ships: FleetShipDTO[]): FleetShipDTO {
    return ships.find(s => s.scrapBlock === null) ?? ships[0];
}

function role(ship: FleetShipDTO): string {
    const type = props.shipTypes.find(t => t.type === ship.type);
    const weapon = carriedWeapon(ship.length, ship.variant ? VARIANTS[ship.variant].w : undefined);
    const arms = ship.variant === 'kuter-remontowy'
        ? 'warsztat · bez broni, nie eskortuje · naprawia poza stocznią'
        : ship.armed ? `nośnik · ${weapon ?? 'strzał'}` : ship.type === 'transportowiec' ? 'bez broni · wymaga eskorty' : 'bez uzbrojenia';
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
        <p v-if="unescortedConvoy" class="warn">⚠️ Konwój bez eskorty — podwójne straty w sztormie. Eskortuje sprawny kuter, niszczyciel albo lotniskowiec (kuter remontowy nie ma broni).</p>

        <label v-if="repairers.length" class="field-repairs" :class="{ off: !fieldRepairs }">
            <input
                type="checkbox"
                :checked="fieldRepairs"
                :disabled="busy"
                @change="emit('fieldRepairs', ($event.target as HTMLInputElement).checked)"
            />
            <span>
                🛠️ Naprawy w rejsie {{ fieldRepairs ? 'włączone' : 'wyłączone' }}
                <span class="hint">
                    sprawne kutry remontowe: {{ workingRepairers }} z {{ repairers.length }} — każdy na tik naprawia jeden statek do 3 pól,
                    od najmniejszego, za koszt remontu z ładowni. Wyłącz, żeby oszczędzać ładunek.
                </span>
            </span>
        </label>

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
                    <ScrapButton :ship="scrapCandidate(g.damaged)" :busy="busy" :block="scrapBlock(scrapCandidate(g.damaged))" @scrap="emit('scrap', $event)" />
                </div>
                <div v-if="g.sound.length" class="repair">
                    <ScrapButton :ship="scrapCandidate(g.sound)" :busy="busy" :block="scrapBlock(scrapCandidate(g.sound))" @scrap="emit('scrap', $event)" />
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
.field-repairs { display: flex; gap: 10px; align-items: flex-start; padding: 10px 14px; border-radius: 12px; background: var(--bg-card); border: 1px solid var(--line-card); font: 600 13px var(--font-head); cursor: pointer; }
.field-repairs.off { opacity: .75; }
.field-repairs input { margin-top: 3px; }
.field-repairs .hint { display: block; margin-top: 2px; font: 500 11px var(--font-mono); color: var(--text-muted); }
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
