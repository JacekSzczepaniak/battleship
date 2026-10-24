<!--
    Wybór zestawu floty przy składzie bitewnym (#125): zestaw ustawia skład
    (sprawne statki w limicie pól, w kolejności zapisu) i mówi, czego zabrakło.
    Rozmieszczenie zestaw ustawi później na ekranie rozstawiania.
-->
<script setup lang="ts">
import { ref, watch } from 'vue';
import type { FleetPresetDTO, FleetShipDTO } from '../api/expeditionApi';
import { missingMessage, presetSquad } from '../lib/fleetPresets';

const props = defineProps<{
    presets: FleetPresetDTO[];
    fleet: FleetShipDTO[];
    limit: number;
    // id zestawu wybranego w tej chwili; null = skład bez zestawu
    selected: string | null;
}>();

const emit = defineEmits<{
    pick: [presetId: string | null, shipIds: string[] | null];
}>();

const message = ref('');

// skład zmieniony ręcznie albo wrócił do domyślnego — komunikat traci sens
watch(() => props.selected, id => { if (id === null) message.value = ''; });

function onChange(e: Event) {
    const id = (e.target as HTMLSelectElement).value;
    const preset = props.presets.find(p => p.id === id);
    if (!preset) {
        message.value = '';
        emit('pick', null, null);
        return;
    }
    const { ships, missing } = presetSquad(preset, props.fleet, props.limit);
    message.value = [
        ships.length ? '' : 'Z tego zestawu żaden statek nie może wypłynąć.',
        missingMessage(missing),
    ].filter(Boolean).join(' ');
    emit('pick', preset.id, ships.map(s => s.id));
}
</script>

<template>
    <div class="preset-pick">
        <label>
            zestaw:
            <select :value="selected ?? ''" @change="onChange">
                <option value="">— bez zestawu —</option>
                <option v-for="p in presets" :key="p.id" :value="p.id">{{ p.name }} · {{ p.board.w }}×{{ p.board.h }}</option>
            </select>
        </label>
        <span v-if="message" class="warn">{{ message }}</span>
    </div>
</template>

<style scoped>
.preset-pick { width: 100%; display: flex; flex-wrap: wrap; gap: 6px 12px; align-items: center; }
select {
    background: var(--bg-card);
    color: var(--text);
    border: 1px solid var(--line-btn);
    border-radius: 6px;
    padding: 3px 6px;
    font: 500 12px var(--font-mono);
}
.warn { color: var(--warn); }
</style>
