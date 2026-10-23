<!--
    Mapa akwenu na ekranie Rejs (#111, projekt REJS.md): zawsze widoczna,
    z nagłówkiem akwenu i ostrzeżeniem o sztormie przed ruchem (telegrafowanie
    ryzyka), osiami A–… / 1–…, stanami sektorów i legendą. Zasięg rejsu jak
    w CaptainProfile::sail(): większa z różnic x i y ≤ sailRange (#98).
    Przy wąskim oknie przewija się sama mapa, nie strona.
-->
<script setup lang="ts">
import { computed } from 'vue';
import type { ExpeditionDTO, GateDTO } from '../api/expeditionApi';
import { columnLabel } from '../lib/ships';
import { depositLabel } from '../lib/economy';
import { formatResources, isEmpty } from '../lib/resources';
import { pendingLabel } from '../lib/counterattack';
import { seaKicker } from '../lib/seas';

const props = defineProps<{
    expedition: ExpeditionDTO;
    busy: boolean;
    // wynik ostatniego rejsu poza dziennikiem (kartografia)
    note: string;
    gateStatus: (gate: GateDTO) => string;
}>();

const emit = defineEmits<{
    sail: [x: number, y: number];
}>();

const world = computed(() => props.expedition.world);

const themeText = computed(() => {
    const t = world.value.sea.theme;
    const visibility = t.visibility === 0 ? 'tylko sektor floty' : `${t.visibility} ${t.visibility === 1 ? 'sektor' : 'sektory'} wokół floty`;
    return `widoczność: ${visibility} · złoża ×${t.depositMultiplier}`;
});

interface Cell {
    key: string; x: number; y: number;
    cls: Record<string, boolean>;
    sailable: boolean;
    // nazwa wyspy albo bramy — etykieta po najechaniu
    name: string;
    title: string;
}

const cells = computed<Cell[]>(() => {
    const exp = props.expedition;
    const w = world.value;
    const fog = new Set(w.discovered);
    const visited = new Set(w.visited);
    const islandsByPos = new Map(exp.islands.filter(i => i.discovered && i.position).map(i => [`${i.position!.x}:${i.position!.y}`, i]));
    const gatesByPos = new Map(w.gates.filter(g => g.position).map(g => [`${g.position!.x}:${g.position!.y}`, g]));
    const { x: px, y: py } = w.position;
    const pending = exp.counterattack.pending;

    const out: Cell[] = [];
    for (let y = 0; y < w.height; y++) {
        for (let x = 0; x < w.width; x++) {
            const key = `${x}:${y}`;
            const discovered = fog.has(key);
            const island = islandsByPos.get(key) ?? null;
            const gate = gatesByPos.get(key) ?? null;
            const here = x === px && y === py;
            // osiągalne w jednym rejsie: większa z różnic x i y (#98), z Żeglugą dwusektorową do 2
            const distance = Math.max(Math.abs(x - px), Math.abs(y - py));
            const sailable = distance >= 1 && distance <= w.sailRange;
            // sztorm grozi przy pierwszym wpłynięciu na sektor docelowy (#68)
            const risk = sailable && !visited.has(key);
            const attacked = !!island && pending?.island === island.id;

            out.push({
                key, x, y, sailable,
                cls: {
                    explored: discovered || !!island, fog: !discovered && !island, reach: sailable, risk,
                    island: !!island, locked: !!island && !island.unlocked, owned: !!island?.owned,
                    gate: !!gate, 'gate-open': !!gate?.open, here, attacked,
                },
                name: island?.name ?? (gate ? `${gate.name} → ${gate.to.name}` : ''),
                title: [
                    island ? island.name : gate ? `${gate.name} → ${gate.to.name} (${props.gateStatus(gate)})` : `${columnLabel(x)}${y + 1}${discovered ? '' : ' · niezbadane wody'}`,
                    here ? 'tu jesteś' : '',
                    island?.owned ? 'pod Twoją banderą' : '',
                    island && !island.unlocked ? 'zamknięta rangą' : '',
                    attacked ? pendingLabel(pending!) : '',
                    island && island.shipyardLevel > 0 ? `stocznia poz. ${island.shipyardLevel}` : '',
                    island?.deposit ? `złoże: ${depositLabel(island)}` : '',
                    island && !isEmpty(island.stockpile) ? `magazyn: ${formatResources(island.stockpile)}` : '',
                    sailable ? `wypłyń · 1 tik${distance > 1 ? ' (2 sektory)' : ''}` : '',
                    risk ? `pierwsze wpłynięcie — ryzyko sztormu ${w.stormChance}%` : '',
                ].filter(Boolean).join(' · '),
            });
        }
    }
    return out;
});

const cols = computed(() => Array.from({ length: world.value.width }, (_, x) => columnLabel(x)));
const rows = computed(() => Array.from({ length: world.value.height }, (_, y) => String(y + 1)));
</script>

<template>
    <section class="sea-map" aria-label="Mapa akwenu">
        <div class="map-head">
            <div>
                <div class="kicker">{{ seaKicker(world.sea.id) }}</div>
                <h2>{{ world.sea.name }}</h2>
                <div class="theme">{{ themeText }}</div>
            </div>
            <div class="storm" :title="`Szansa sztormu przy pierwszym wpłynięciu na sektor; odwiedzone sektory są bezpieczne`">
                sztorm w nieodwiedzonych: {{ world.stormChance }}%
            </div>
        </div>

        <div class="frame" :style="{ '--cols': world.width, '--rows': world.height }">
            <div></div>
            <div class="axis cols"><span v-for="c in cols" :key="c">{{ c }}</span></div>
            <div class="axis rows"><span v-for="r in rows" :key="r">{{ r }}</span></div>
            <div class="grid">
                <button
                    v-for="cell in cells"
                    :key="cell.key"
                    class="cell"
                    :class="cell.cls"
                    :disabled="busy || !cell.sailable"
                    :title="cell.title"
                    :aria-label="cell.title"
                    @click="emit('sail', cell.x, cell.y)"
                >
                    <span v-if="cell.cls.island" class="isle"></span>
                    <span v-else-if="cell.cls.gate" class="portal"></span>
                    <span v-if="cell.cls.owned" class="flag"></span>
                    <span v-if="cell.cls.attacked" class="alarm">⚔</span>
                    <span v-if="cell.cls.here" class="fleet"></span>
                    <span v-if="cell.name" class="name">{{ cell.name }}</span>
                </button>
            </div>
        </div>

        <div class="legend">
            <span><i class="lg-fleet"></i>Twoja flota</span>
            <span><i class="lg-reach"></i>zasięg rejsu</span>
            <span><i class="lg-risk"></i>w zasięgu, nieodwiedzony — ryzyko sztormu</span>
            <span><i class="lg-isle"></i>wyspa</span>
            <span><i class="lg-gate"></i>brama akwenu</span>
            <span><i class="lg-flag"></i>Twoja bandera</span>
            <span><i class="lg-fog"></i>niezbadane</span>
        </div>
        <p class="hint">
            Kliknij sektor w zasięgu, aby wypłynąć — <b>1 tik</b> za ruch
            <template v-if="world.sailRange > 1">(żegluga dwusektorowa: do {{ world.sailRange }} sektorów, także po skosie)</template>.
        </p>
        <p v-if="note" class="note">{{ note }}</p>
    </section>
</template>

<style scoped>
.sea-map {
    flex: none;
    max-width: 100%;
    overflow-x: auto;
    padding: 22px;
    border-radius: 18px;
    background: linear-gradient(160deg, var(--bg-frame-a), var(--bg-frame-b));
    border: 1px solid var(--line-card);
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.map-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.kicker { font-size: 10px; letter-spacing: .24em; }
h2 { margin: 2px 0 0; font: 700 24px var(--font-head); }
.theme { margin-top: 2px; font: 500 11px var(--font-mono); color: var(--text-muted); }
.storm { padding: 6px 11px; border-radius: 8px; background: #2a2418; border: 1px solid #5a4a28; font: 600 11px var(--font-mono); color: var(--warn); white-space: nowrap; }

.frame { --cell: 42px; display: grid; grid-template-columns: 18px auto; grid-template-rows: 16px auto; gap: 6px; width: max-content; }
.axis { display: grid; gap: 4px; font: 600 10px var(--font-mono); color: var(--text-label); }
.axis.cols { grid-template-columns: repeat(var(--cols), var(--cell)); text-align: center; }
.axis.rows { grid-template-rows: repeat(var(--rows), var(--cell)); }
.axis.rows span { display: flex; align-items: center; justify-content: center; }
.grid { display: grid; grid-template-columns: repeat(var(--cols), var(--cell)); gap: 4px; }

.cell {
    position: relative;
    width: var(--cell);
    height: var(--cell);
    padding: 0;
    border: 0;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: default;
}
.cell:disabled { color: inherit; }
.cell.explored { background: #0f2a34; box-shadow: inset 0 0 0 1px var(--line-card); }
.cell.fog { background: #0a1b23; box-shadow: inset 0 0 0 1px #12303a; }
.cell.reach:not(:disabled) { background: #0f2f38; box-shadow: none; border: 1.5px dashed var(--info); cursor: pointer; }
.cell.reach.risk:not(:disabled) { border-color: var(--warn); }
.cell.reach:not(:disabled):hover { filter: brightness(1.35); }
.isle { width: 24px; height: 20px; border-radius: 50% 45% 50% 40%; background: #c9a874; box-shadow: inset -3px -3px 0 #a68756; }
.cell.locked .isle { opacity: .45; }
.portal { width: 22px; height: 22px; border-radius: 50%; border: 2px dashed #6f86d6; box-shadow: inset 0 0 6px #4a5ea8; }
.cell.gate-open .portal { border-style: solid; border-color: var(--info); }
.flag { position: absolute; top: 4px; left: 4px; width: 8px; height: 8px; border-radius: 2px; background: var(--accent); }
.alarm { position: absolute; top: 1px; right: 3px; font-size: 11px; color: var(--danger); line-height: 1; }
.cell.attacked { box-shadow: inset 0 0 0 1.5px var(--danger); }
.fleet { position: absolute; inset: -3px; border-radius: 10px; box-shadow: 0 0 0 2px var(--accent), 0 0 16px #e8843c88; pointer-events: none; }
/* nazwa nad sektorem po najechaniu — natywny title pokazuje się z opóźnieniem
   i nie działa na sektorach poza zasięgiem (disabled) */
.name {
    position: absolute;
    left: 50%;
    bottom: calc(100% + 5px);
    transform: translateX(-50%);
    z-index: 3;
    padding: 3px 7px;
    border-radius: 5px;
    border: 1px solid var(--line-card);
    background: var(--bg-panel);
    color: var(--text);
    font: 600 11px var(--font-body);
    white-space: nowrap;
    pointer-events: none;
    opacity: 0;
    transition: opacity .12s;
}
.cell:hover { z-index: 2; }
.cell:hover .name { opacity: 1; }

.legend { display: flex; gap: 8px 16px; flex-wrap: wrap; padding-top: 14px; border-top: 1px solid var(--line); font: 500 11px var(--font-mono); color: var(--text-muted); max-width: calc(18px + 6px + var(--cols, 12) * 46px); }
.legend span { display: inline-flex; align-items: center; gap: 7px; }
.legend i { display: inline-block; flex: none; }
.lg-fleet { width: 14px; height: 14px; border-radius: 50%; box-shadow: 0 0 0 2px var(--accent); }
.lg-reach { width: 14px; height: 14px; border-radius: 4px; border: 1.5px dashed var(--info); }
.lg-risk { width: 14px; height: 14px; border-radius: 4px; border: 1.5px dashed var(--warn); }
.lg-isle { width: 12px; height: 12px; border-radius: 50%; background: #c9a874; }
.lg-gate { width: 12px; height: 12px; border-radius: 50%; border: 2px dashed #6f86d6; }
.lg-flag { width: 8px; height: 8px; border-radius: 2px; background: var(--accent); }
.lg-fog { width: 14px; height: 14px; border-radius: 4px; background: #0a1b23; box-shadow: inset 0 0 0 1px #12303a; }
.hint { margin: 0; font: 400 12px var(--font-body); color: var(--text-muted); max-width: 560px; }
.hint b { color: var(--text-2); }
.note { margin: 0; font: 500 12px var(--font-mono); color: var(--info); max-width: 560px; }
</style>
