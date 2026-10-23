<!--
    Plansza wg projektu UI: osie A–… / 1–…, komórka 34px (mniejsza na dużych
    planszach). Komórka dostaje listę klas stanu:
      podłoże:  water | fog
      treść:    ship | hit | sunk | miss
      nakładki: preview | preview-ok | preview-bad | launch | sonar-ship | sonar-water | note | anim
      fort (#119, odsłonięte konstrukcje): fort-gun | fort-wall | fort-magazine; nakładka: blast
-->
<script setup lang="ts">
import { computed } from 'vue';
import { columnLabel } from '../lib/ships';

const props = withDefaults(defineProps<{
    cells: string[][];
    interactive?: boolean;
    cellSize?: number;
}>(), {
    interactive: true,
    cellSize: 0,
});

const emit = defineEmits<{
    cellClick: [x: number, y: number];
    cellHover: [x: number, y: number];
    cellContext: [x: number, y: number];
    cellDrop: [x: number, y: number, event: DragEvent];
    leave: [];
}>();

const height = computed(() => props.cells.length);
const width = computed(() => props.cells[0]?.length ?? 0);

const size = computed(() => {
    if (props.cellSize > 0) return props.cellSize;
    const longest = Math.max(width.value, height.value);
    if (longest <= 12) return 34;
    if (longest <= 14) return 30;
    return 27;
});

const gridStyle = computed(() => ({
    '--cell': `${size.value}px`,
    gridTemplateColumns: `repeat(${width.value}, var(--cell))`,
}));
</script>

<template>
    <div class="sea" :style="{ '--cell': `${size}px` }" @mouseleave="emit('leave')">
        <div></div>
        <div class="axis cols" :style="gridStyle">
            <div v-for="x in width" :key="x">{{ columnLabel(x - 1) }}</div>
        </div>
        <div class="axis rows" :style="{ gridTemplateRows: `repeat(${height}, var(--cell))` }">
            <div v-for="y in height" :key="y">{{ y }}</div>
        </div>
        <div class="cells" :class="{ interactive }" :style="gridStyle">
            <template v-for="(row, y) in cells" :key="y">
                <div
                    v-for="(cls, x) in row"
                    :key="`${x}:${y}`"
                    class="cell"
                    :class="cls"
                    @click="interactive && emit('cellClick', x, y)"
                    @mouseenter="emit('cellHover', x, y)"
                    @contextmenu.prevent="emit('cellContext', x, y)"
                    @dragover.prevent="emit('cellHover', x, y)"
                    @drop.prevent="emit('cellDrop', x, y, $event)"
                ></div>
            </template>
        </div>
    </div>
</template>

<style scoped>
.sea {
    display: grid;
    grid-template-columns: 20px auto;
    grid-template-rows: 18px auto;
    gap: 6px;
    user-select: none;
}
.axis { font: 600 10px var(--font-mono); color: var(--text-axis); letter-spacing: .04em; }
.axis.cols { display: grid; gap: 3px; }
.axis.cols div { text-align: center; }
.axis.rows { display: grid; gap: 3px; }
.axis.rows div { display: flex; align-items: center; justify-content: center; }
.cells { display: grid; gap: 3px; }

.cell {
    position: relative;
    width: var(--cell);
    height: var(--cell);
    border-radius: 4px;
    background: #0c2029;
    box-shadow: inset 0 0 0 1px #163540;
    transition: background .15s, box-shadow .15s;
}
.cells.interactive .cell { cursor: crosshair; }
.cell.fog { background: #0a1b23; box-shadow: inset 0 0 0 1px #12303a; }
.cell.ship { background: #33596a; box-shadow: inset 0 0 0 1px #517f8f; }

/* kropka trafienia / pierścień pudła */
.cell::before,
.cell::after {
    content: '';
    position: absolute;
    left: 50%;
    top: 50%;
    border-radius: 50%;
    transform: translate(-50%, -50%);
}
/*
    Trafiony (statek jeszcze pływa) ma być najgłośniejszy na planszy — tu trzeba
    dobijać: jasna obwódka, poświata i powolny puls. Zatopiony jest „wygaszony”:
    ciemne pole i przygaszony krzyżyk zamiast kropki, żeby nie mylił się z trafieniem.
*/
.cell.hit {
    background: #4d2a14;
    box-shadow: inset 0 0 0 2px var(--accent), 0 0 10px #e8843c55;
}
.cell.hit::after {
    width: calc(var(--cell) * .44);
    height: calc(var(--cell) * .44);
    background: var(--accent);
    box-shadow: 0 0 12px #e8843c99;
}
.cell.hit::before {
    width: calc(var(--cell) * .44);
    height: calc(var(--cell) * .44);
    box-shadow: 0 0 0 1.5px var(--accent);
    animation: hitPulse 1.8s ease-out infinite;
}
.cell.sunk { background: #24130e; box-shadow: inset 0 0 0 1px #4e2a1d; }
.cell.sunk::before,
.cell.sunk::after {
    width: calc(var(--cell) * .56);
    height: 2.5px;
    border-radius: 2px;
    background: #a4532c;
}
.cell.sunk::before { transform: translate(-50%, -50%) rotate(45deg); }
.cell.sunk::after { transform: translate(-50%, -50%) rotate(-45deg); }
@keyframes hitPulse {
    0% { transform: translate(-50%, -50%) scale(1); opacity: .9; }
    100% { transform: translate(-50%, -50%) scale(2); opacity: 0; }
}
@media (prefers-reduced-motion: reduce) {
    .cell.hit::before { animation: none; opacity: 0; }
}
.cell.miss::after {
    width: 7px;
    height: 7px;
    box-shadow: inset 0 0 0 1.6px #5f8089;
}

/*
    Fort (#119): odsłonięta konstrukcja zamiast trafienia. Działo (jedno pole,
    trafienie = zniszczenie) — wylot lufy przekreślony; mur — kamień, bez pulsu,
    bo nie ma czego dobijać; magazyn — złoty romb. Eksplozja: przerywana obwódka.
*/
.cell.fort-gun { background: #24130e; box-shadow: inset 0 0 0 1.5px var(--danger); }
.cell.fort-gun::after {
    width: calc(var(--cell) * .42);
    height: calc(var(--cell) * .42);
    background: #3a1d12;
    box-shadow: 0 0 0 2px var(--danger);
}
.cell.fort-gun::before {
    width: calc(var(--cell) * .7);
    height: 2.5px;
    border-radius: 2px;
    background: var(--danger);
    transform: translate(-50%, -50%) rotate(-45deg);
    z-index: 1;
}
.cell.fort-wall {
    background: repeating-linear-gradient(0deg, #4a5257 0 calc(var(--cell) / 4 - 1px), #2e3438 calc(var(--cell) / 4 - 1px) calc(var(--cell) / 4));
    box-shadow: inset 0 0 0 1px #6b767c;
}
.cell.fort-magazine { background: #3b2d0e; box-shadow: inset 0 0 0 1.5px var(--warn), 0 0 12px #d8ae4c55; }
.cell.fort-magazine::after {
    width: calc(var(--cell) * .38);
    height: calc(var(--cell) * .38);
    border-radius: 2px;
    background: var(--warn);
    transform: translate(-50%, -50%) rotate(45deg);
}
.cell.blast { outline: 1.5px dashed #e8843caa; outline-offset: -4px; }
.cell.blast.miss { background: #23160f; }

/* zwiad i notatki */
.cell.sonar-ship { box-shadow: inset 0 0 0 2px var(--info); background: #0f3640; }
.cell.sonar-ship::after { width: 6px; height: 6px; background: var(--info); }
.cell.sonar-water { box-shadow: inset 0 0 0 1px #1f4b57; }
.cell.sonar-water::after { width: 4px; height: 4px; background: #1f4b57; }
.cell.note {
    background-image: repeating-linear-gradient(45deg, #1c3b46 0 2px, transparent 2px 7px);
}

/* podglądy */
.cell.preview { box-shadow: inset 0 0 0 2px var(--accent); }
.cell.preview-ok { background: #13404a; box-shadow: inset 0 0 0 2px var(--info); }
.cell.preview-bad { background: #3a2220; box-shadow: inset 0 0 0 2px var(--danger); }
.cell.launch { box-shadow: inset 0 0 0 2px var(--info), 0 0 10px #3fb6c455; cursor: pointer; }

/* animacja ostatniego strzału */
.cell.anim.hit::after { animation: hitPop 320ms ease-out; }
.cell.anim.miss::after { animation: missRipple 420ms ease-out; }
@keyframes hitPop {
    0% { transform: translate(-50%, -50%) scale(.2); box-shadow: 0 0 0 0 #e8843c; }
    60% { transform: translate(-50%, -50%) scale(1.25); }
    100% { transform: translate(-50%, -50%) scale(1); box-shadow: 0 0 12px #e8843c99; }
}
@keyframes missRipple {
    0% { transform: translate(-50%, -50%) scale(3); opacity: 0; }
    100% { transform: translate(-50%, -50%) scale(1); opacity: 1; }
}
</style>
