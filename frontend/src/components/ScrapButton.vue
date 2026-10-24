<!--
    Złomowanie statku (#124): akcja nieodwracalna, więc w dwóch krokach —
    pierwszy klik pokazuje zwrot i prosi o potwierdzenie. Powód blokady
    liczy widok (zna port, stocznię i blokadę domeny).
-->
<script setup lang="ts">
import { ref } from 'vue';
import { fleetShipLabel, type FleetShipDTO } from '../api/expeditionApi';
import { formatResources } from '../lib/resources';

defineProps<{
    ship: FleetShipDTO;
    busy: boolean;
    // powód blokady złomowania; null = można złomować
    block: string | null;
}>();

const emit = defineEmits<{
    scrap: [ship: FleetShipDTO];
}>();

const confirming = ref(false);

function refundLabel(ship: FleetShipDTO): string {
    const refund = formatResources(ship.scrapValue, '');
    return refund ? `+${refund}` : 'bez zwrotu';
}

function confirm(ship: FleetShipDTO) {
    confirming.value = false;
    emit('scrap', ship);
}
</script>

<template>
    <span v-if="confirming" class="confirm" role="group">
        <span class="ask">
            Zezłomować statek: {{ fleetShipLabel(ship).toLowerCase() }}{{ ship.damaged ? ' (uszkodzony)' : '' }}?
            Zwrot do magazynu: {{ formatResources(ship.scrapValue, 'nic') }}. Tego nie da się cofnąć.
        </span>
        <button class="btn btn-small btn-scrap" :disabled="busy" @click="confirm(ship)">Tak, złomuj</button>
        <button class="btn btn-small btn-secondary" :disabled="busy" @click="confirming = false">Anuluj</button>
    </span>
    <template v-else>
        <button
            class="btn btn-small btn-secondary"
            :disabled="busy || block !== null"
            :title="block ?? `zwrot do magazynu wyspy: ${formatResources(ship.scrapValue, 'nic')}`"
            @click="confirming = true"
        >
            Złomuj 1 szt. ({{ refundLabel(ship) }})
        </button>
        <span v-if="block" class="why">{{ block }}</span>
    </template>
</template>

<style scoped>
.confirm { display: flex; flex-wrap: wrap; gap: 6px 8px; align-items: center; flex-basis: 100%; }
.ask { flex-basis: 100%; font: 500 12px var(--font-mono); color: var(--warn); }
.btn-scrap { background: transparent; border: 1px solid var(--danger); color: var(--danger); }
.btn-scrap:hover:not(:disabled) { background: var(--danger); color: var(--text); }
.why { font: 500 11px var(--font-mono); color: var(--text-muted); }
</style>
