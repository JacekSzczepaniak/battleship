<!-- GameBoard.vue -->
<script setup lang="ts">
import { watch, computed, isRef, unref } from 'vue'
import type { CellState } from '../stores/game.ts';

const props = defineProps<{
    // Dopuszczamy, że przyjdzie Ref<CellState[][]> lub zwykła tablica
    grid: unknown;
    // Dopuszczamy boolean lub Ref<boolean>
    disabled?: unknown;
    onCellClick?: (x: number, y: number) => void;
}>();

const normalizedGrid = computed<CellState[][]>(() => {
    const g = isRef(props.grid) ? props.grid.value as unknown : props.grid as unknown;
    if (Array.isArray(g)) return g as CellState[][];
    return [] as CellState[][];
});

const normalizedDisabled = computed<boolean>(() => !!unref(props.disabled as any));

function handleClick(x: number, y: number) {
    if (normalizedDisabled.value) return;
    if (props.onCellClick) {
        props.onCellClick(x, y);
    }
}

// Debug: loguj kształt siatki przy każdej zmianie
watch(() => [normalizedGrid.value, normalizedDisabled.value], ([g, d]) => {
    try {
        // eslint-disable-next-line no-console
        console.debug('[GameBoard] grid rows:', g?.length ?? 0, 'row lens:', Array.isArray(g) ? g.map(r => Array.isArray(r) ? r.length : -1) : [], 'disabled:', d);
    } catch (_) { /* ignore */ }
}, { deep: true, immediate: true })
</script>

<template>
    <div class="grid" :class="{ disabled: normalizedDisabled }" :style="{ gridTemplateRows: `repeat(${normalizedGrid?.length || 0}, 30px)` }">
        <div
            v-for="(row, y) in normalizedGrid"
            :key="y"
            class="row"
            :style="{ gridTemplateColumns: `repeat(${Array.isArray(row) ? row.length : 0}, 30px)` }"
        >
            <div
                v-for="(cell, x) in row"
                :key="x"
                class="cell"
                :class="cell"
                @click="handleClick(x, y)"
            />
        </div>
    </div>
</template>

<style scoped>
.grid { display: grid; }
.grid.disabled .cell { cursor: not-allowed; opacity: .7; }
.row { display: grid; }
.cell { border: 1px solid #ccc; cursor: pointer; width: 30px; height: 30px; }
.cell.ship { background: gray; }
.cell.hit { background: red; }
.cell.miss { background: lightblue; }
</style>
