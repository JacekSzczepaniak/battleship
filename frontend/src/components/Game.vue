<script setup lang="ts">
import GameGameBoard from './GameBoard.vue';
import { useGame } from '../composables/useGame';

const g = useGame();

function handleShot(x: number, y: number) {
    g.shot(x, y);
}
</script>

<template>
    <div class="boards">
        <div>
            <h3>Twoja plansza</h3>
            <GameGameBoard :grid="g.playerGrid" />
        </div>

        <div>
            <h3>Przeciwnik</h3>
            <GameGameBoard :grid="g.enemyFogGrid" :onCellClick="handleShot" />
            <div v-if="g.loading">Ładowanie…</div>
            <div v-if="g.error" style="color:crimson">{{ g.error }}</div>
            <div v-if="g.status !== 'ongoing'">Koniec gry: {{ g.status }}</div>
            <div>Ruch: {{ g.turn }}</div>
        </div>
    </div>
</template>

<style scoped>
.boards { display: flex; gap: 20px; align-items: start; }
</style>
