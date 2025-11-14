<!-- GameBoard.vue -->
<script setup lang="ts">
import type { CellState } from '../stores/game.ts';

const props = defineProps<{
    grid: CellState[][];
    onCellClick?: (x: number, y: number) => void;
}>();

function handleClick(x: number, y: number) {
    if (props.onCellClick) {
        props.onCellClick(x, y);
    }
}
</script>

<template>
    <div class="grid">
        <div
            v-for="(row, y) in grid"
            :key="y"
            class="row"
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
.grid {
    display: grid;
    grid-template-rows: repeat(10, 30px);
}
.row {
    display: grid;
    grid-template-columns: repeat(10, 30px);
}
.cell {
    border: 1px solid #ccc;
    cursor: pointer;
}
.cell.ship { background: gray; }
.cell.hit { background: red; }
.cell.miss { background: lightblue; }
</style>
