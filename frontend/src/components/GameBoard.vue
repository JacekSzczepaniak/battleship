<!-- GameBoard.vue -->
<script setup lang="ts">
import {computed, isRef, unref} from 'vue'

const props = defineProps<{
    // Dopuszczamy, że przyjdzie Ref<CellState[][]> lub zwykła tablica
    grid: unknown;
    // Dopuszczamy boolean lub Ref<boolean>
    disabled?: unknown;
    onCellClick?: (x: number, y: number) => void;
    onCellHover?: (x: number, y: number) => void;
    onBoardLeave?: () => void;
    onCellRightClick?: (x: number, y: number) => void;
}>();

// Przyjmujemy dowolne klasy komórek (np. 'ship', 'hit', 'miss', 'opp-hit', 'opp-miss')
const normalizedGrid = computed<string[][]>(() => {
    const g = isRef(props.grid) ? props.grid.value as unknown : props.grid as unknown;
    if (Array.isArray(g)) return g as string[][];
    return [] as string[][];
});

const normalizedDisabled = computed<boolean>(() => !!unref(props.disabled as any));

function handleClick(x: number, y: number) {
    if (normalizedDisabled.value) return;
    if (props.onCellClick) {
        props.onCellClick(x, y);
    }
}
</script>

<template>
    <div class="grid" :class="{ disabled: normalizedDisabled }"
         :style="{ gridTemplateRows: `repeat(${normalizedGrid?.length || 0}, 30px)` }"
         @mouseleave="props.onBoardLeave?.()">
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
                :title="typeof cell === 'string' && (cell.includes('opp-hit') || cell.includes('opp-miss')) ? (cell.includes('opp-hit') ? 'Trafienie przeciwnika' : 'Pudło przeciwnika') : ''"
                @click="handleClick(x, y)"
                @mouseenter="props.onCellHover?.(x, y)"
                @contextmenu.prevent="props.onCellRightClick?.(x, y)"
            />
        </div>
    </div>
</template>

<style scoped>
.grid {
    display: grid;
}

.grid.disabled .cell {
    cursor: not-allowed;
    opacity: .7;
}

.row {
    display: grid;
}

.cell {
    position: relative;              /* <--- DODAJ TO */
    border: 1px solid #ccc;
    cursor: pointer;
    width: 30px;
    height: 30px;
    overflow: hidden;                /* ładniejsze efekty */
}

.cell.ship {
    background: gray;
}

.cell.hit {
    background: red;
}

.cell.miss {
    background: lightblue;
}

/* Skan sonaru (tryb fun, plansza przeciwnika) */
.cell.sonar-ship {
    background: #fef3c7;
    box-shadow: inset 0 0 0 2px #d97706;
}

.cell.sonar-water {
    background: #f0f9ff;
    background-image: radial-gradient(circle at 50% 50%, rgba(125, 211, 252, 0.6) 20%, transparent 21%);
}

/* Overlay trafień/pudeł przeciwnika (na planszy gracza) */
.cell.opp-hit {
    position: relative;
    box-shadow: inset 0 0 0 3px #7a0a0a;
    background-image: repeating-linear-gradient(45deg, rgba(239, 68, 68, 0.35) 0 6px, transparent 6px 12px);
}

.cell.opp-miss {
    position: relative;
    box-shadow: inset 0 0 0 3px #0c4a6e;
    background-image: radial-gradient(circle at 50% 50%, rgba(12, 74, 110, 0.35) 30%, transparent 31%);
    background-size: 12px 12px;
}

/* ===================== */
/* ANIMACJE TRAFIENIA    */
/* ===================== */

.cell.hit.anim {
    animation: hitFlash 250ms ease-out, hitShake 300ms ease-out;
}

@keyframes hitFlash {
    0% {
        box-shadow: 0 0 0 0 rgba(255, 0, 0, 0.9);
    }
    100% {
        box-shadow: 0 0 0 10px rgba(255, 0, 0, 0);
    }
}

@keyframes hitShake {
    0% {
        transform: translate(0);
    }
    25% {
        transform: translate(2px, -2px);
    }
    50% {
        transform: translate(-2px, 2px);
    }
    75% {
        transform: translate(2px, 2px);
    }
    100% {
        transform: translate(0);
    }
}

/* ===================== */
/* ANIMACJA PUDŁA        */
/* ===================== */

.cell.miss.anim::after {
    content: "";
    position: absolute;
    inset: 0;
    border-radius: 50%;
    animation: missRipple 500ms ease-out forwards;
    pointer-events: none;
    background: radial-gradient(circle, rgba(0, 0, 0, 0.15), transparent 70%);
}

@keyframes missRipple {
    0% {
        transform: scale(0.3);
        opacity: 1;
    }
    100% {
        transform: scale(1.8);
        opacity: 0;
    }
}

/* ===================== */
/* ZATOPIENIE            */
/* ===================== */

/* Ciemna czerwień + jasny krzyżyk — jednoznacznie różne od trafienia (czerwień)
   i od szarego statku na planszy gracza */
.cell.sink {
    background-color: #7f1d1d;
    background-image:
        linear-gradient(45deg, transparent 42%, #fecaca 42%, #fecaca 58%, transparent 58%),
        linear-gradient(-45deg, transparent 42%, #fecaca 42%, #fecaca 58%, transparent 58%);
}

/* ===================== */
/* PODGLĄD ZASIĘGU BRONI */
/* ===================== */

.cell.preview {
    box-shadow: inset 0 0 0 3px rgba(31, 111, 235, 0.55);
}

/* Wyrzutnia torpedy: własny niezatopiony statek (tryb torpedy) */
.cell.launch {
    box-shadow: inset 0 0 0 3px #16a34a;
    cursor: pointer;
}

/* Notatka gracza a'la saper: „tu nic nie może być" (PPM) */
.cell.note {
    background-image: repeating-linear-gradient(
        45deg,
        rgba(100, 116, 139, 0.45) 0 2px,
        transparent 2px 7px
    );
}

</style>
