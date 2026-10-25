<!--
    Mapa akwenu na ekranie Rejs (#111, projekt REJS.md): zawsze widoczna,
    z nagłówkiem akwenu i ostrzeżeniem o sztormie przed ruchem (telegrafowanie
    ryzyka), osiami A–… / 1–…, stanami sektorów i legendą. Zasięg rejsu jak
    w CaptainProfile::sail(): większa z różnic x i y ≤ sailRange (#98).
    Przy wąskim oknie przewija się sama mapa, nie strona.
    Znaczniki wysp (#131): twierdza, stocznia z poziomem, złoże w kolorze
    surowca i budynki osady pod banderą — widoczne bez najeżdżania.
    Port handlowy (#140): zielona wyspa z kramem — rynek, bez bitwy i bandery.
    Animacja rejsu (#146): nad komórkami leży warstwa efektów — `fxBoard()` daje
    jej geometrię. W trakcie animacji sektory z `masked` stoją pod mgłą, a znacznik
    floty rysuje overlay (`fleetHidden`); klik w mapę pomija animację.
-->
<script setup lang="ts">
import { computed, ref, type Component } from 'vue';
import { Anchor, Axe, Castle, Coins, GraduationCap, Mountain, TreePalm, Pickaxe, Shield, Store, Trees, Wrench } from 'lucide-vue-next';
import { BUILDING_LABELS, type BuildingTypeName, type ExpeditionDTO, type GateDTO, type IslandDTO } from '../api/expeditionApi';
import { columnLabel } from '../lib/ships';
import { depositLabel, depositState } from '../lib/economy';
import { RESOURCES, formatResources, isEmpty, type ResourceKey } from '../lib/resources';
import { pendingLabel } from '../lib/counterattack';
import { seaKicker } from '../lib/seas';
import { boardFromGrid, type FxBoard } from '../fx/board';
import { FX_SPEEDS, fxSpeed, setFxSpeed } from '../fx/speed';

const props = defineProps<{
    expedition: ExpeditionDTO;
    busy: boolean;
    // wynik ostatniego rejsu poza dziennikiem (kartografia)
    note: string;
    gateStatus: (gate: GateDTO) => string;
    // animacja rejsu (#146): sektory jeszcze pod mgłą, flota w overlayu
    masked?: ReadonlySet<string>;
    fleetHidden?: boolean;
    animating?: boolean;
}>();

const emit = defineEmits<{
    sail: [x: number, y: number];
    skip: [];
}>();

const stage = ref<HTMLElement | null>(null);
const gridEl = ref<HTMLElement | null>(null);
const fxLayer = ref<HTMLElement | null>(null);

function fxBoard(): FxBoard | null {
    if (!stage.value || !gridEl.value || !fxLayer.value) return null;
    return boardFromGrid(gridEl.value, fxLayer.value, stage.value, world.value.width, world.value.height);
}

defineExpose({ fxBoard });

const world = computed(() => props.expedition.world);

const themeText = computed(() => {
    const t = world.value.sea.theme;
    // skuteczna widoczność: motyw akwenu + legenda „Czarna perła” (#144)
    const v = world.value.visibility ?? t.visibility;
    const visibility = v === 0 ? 'tylko sektor floty' : `${v} ${v === 1 ? 'sektor' : 'sektory'} wokół floty`;
    const legend = v > t.visibility ? ' (Czarna perła +1)' : '';
    return `widoczność: ${visibility}${legend} · złoża ×${t.depositMultiplier}`;
});

// ikony lucide zamiast emoji z BUILDING_ICONS — w komórce 42 px muszą mieć kolor i grubość kreski
const BUILDING_MARKS: Record<BuildingTypeName, Component> = {
    tartak: Axe,
    kopalnia: Pickaxe,
    'rozbudowa-stoczni': Wrench,
    fort: Shield,
    uczelnia: GraduationCap,
};
const DEPOSIT_MARKS: Record<ResourceKey, Component> = { wood: Trees, steel: Mountain, gold: Coins };
// więcej znaczników budynków nie mieści się w dolnym pasku komórki; reszta jako „+N”
const MAX_BUILDING_MARKS = 3;

interface Marks {
    stronghold: boolean;
    besieged: boolean;
    shipyard: number;
    // złoże stoi (bez bandery albo pełny magazyn) — znaczek przygaszony
    deposit: { key: ResourceKey; color: string; icon: Component }[];
    depositIdle: boolean;
    buildings: { type: BuildingTypeName; icon: Component }[];
    moreBuildings: number;
}

function islandMarks(island: IslandDTO): Marks {
    const d = island.deposit;
    // budynki bez bandery są nieczynne i nie należą do kapitana — na mapie tylko własne osady
    const buildings = island.owned ? island.buildings : [];
    return {
        stronghold: island.stronghold,
        besieged: island.besieged,
        shipyard: island.shipyardLevel,
        deposit: d ? RESOURCES.filter(r => d.yield[r.key] > 0).map(r => ({ key: r.key, color: r.color, icon: DEPOSIT_MARKS[r.key] })) : [],
        depositIdle: !!d && depositState(island) !== 'working',
        buildings: buildings.slice(0, MAX_BUILDING_MARKS).map(type => ({ type, icon: BUILDING_MARKS[type] })),
        moreBuildings: Math.max(0, buildings.length - MAX_BUILDING_MARKS),
    };
}

interface Cell {
    key: string; x: number; y: number;
    cls: Record<string, boolean>;
    sailable: boolean;
    // nazwa wyspy albo bramy — etykieta po najechaniu
    name: string;
    title: string;
    marks: Marks | null;
}

const cells = computed<Cell[]>(() => {
    const exp = props.expedition;
    const w = world.value;
    const fog = new Set(w.discovered);
    const visited = new Set(w.visited);
    const islandsByPos = new Map(exp.islands.filter(i => i.discovered && i.position).map(i => [`${i.position!.x}:${i.position!.y}`, i]));
    const gatesByPos = new Map(w.gates.filter(g => g.position).map(g => [`${g.position!.x}:${g.position!.y}`, g]));
    const port = w.tradePort?.position ? w.tradePort : null;
    // dzika wyspa (#144): ekspedycja w głąb lądu, bez bitwy i bandery
    const wild = w.wildIsland?.position ? w.wildIsland : null;
    const { x: px, y: py } = w.position;
    const pending = exp.counterattack.pending;
    const masked = props.masked;

    const out: Cell[] = [];
    for (let y = 0; y < w.height; y++) {
        for (let x = 0; x < w.width; x++) {
            const key = `${x}:${y}`;
            // sektor odkryty w trwającym rejsie: do odsłonięcia wygląda jak mgła
            const hidden = !!masked?.has(key);
            const discovered = fog.has(key) && !hidden;
            const island = hidden ? null : islandsByPos.get(key) ?? null;
            const gate = hidden ? null : gatesByPos.get(key) ?? null;
            const atPort = !hidden && !!port && port.position!.x === x && port.position!.y === y;
            const atWild = !hidden && !!wild && wild.position!.x === x && wild.position!.y === y;
            const here = x === px && y === py && !props.fleetHidden;
            // osiągalne w jednym rejsie: większa z różnic x i y (#98), z Żeglugą dwusektorową do 2
            const distance = Math.max(Math.abs(x - px), Math.abs(y - py));
            const sailable = distance >= 1 && distance <= w.sailRange;
            // sztorm grozi przy pierwszym wpłynięciu na sektor docelowy (#68)
            const risk = sailable && !visited.has(key);
            const attacked = !!island && pending?.island === island.id;
            const marks = island ? islandMarks(island) : null;

            out.push({
                key, x, y, sailable,
                cls: {
                    explored: discovered || !!island, fog: !discovered && !island, reach: sailable, risk,
                    island: !!island, locked: !!island && !island.unlocked, owned: !!island?.owned,
                    gate: !!gate, 'gate-open': !!gate?.open, port: atPort, wild: atWild, here, attacked,
                    marked: !!marks && (marks.shipyard > 0 || marks.deposit.length > 0 || marks.buildings.length > 0),
                },
                marks,
                name: island?.name ?? (gate ? `${gate.name} → ${gate.to.name}` : atPort ? port!.name : atWild ? wild!.name : ''),
                title: [
                    island ? island.name : atPort ? `${port!.name} · port handlowy: rynek, bez bitwy i bandery` : atWild ? `${wild!.name} · dzika wyspa: ekspedycja w głąb lądu, bez bitwy i bandery` : gate ? `${gate.name} → ${gate.to.name} (${props.gateStatus(gate)})` : `${columnLabel(x)}${y + 1}${discovered ? '' : ' · niezbadane wody'}`,
                    here ? 'tu jesteś' : '',
                    island?.owned ? 'pod Twoją banderą' : '',
                    island?.stronghold ? (island.besieged && !island.owned ? 'twierdza oblężona' : 'twierdza z fortem') : '',
                    island && !island.unlocked ? 'zamknięta rangą' : '',
                    attacked ? pendingLabel(pending!) : '',
                    island && island.shipyardLevel > 0 ? `stocznia poz. ${island.shipyardLevel}` : '',
                    island?.deposit ? `złoże: ${depositLabel(island)}` : '',
                    island?.owned && island.buildings.length ? `budynki: ${island.buildings.map(b => BUILDING_LABELS[b]).join(', ')}` : '',
                    island && !isEmpty(island.stockpile) ? `magazyn: ${formatResources(island.stockpile)}` : '',
                    atPort && !isEmpty(port!.stockpile) ? `magazyn: ${formatResources(port!.stockpile)}` : '',
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
            <div class="head-side">
            <div class="storm" :title="`Szansa sztormu przy pierwszym wpłynięciu na sektor; odwiedzone sektory są bezpieczne`">
                sztorm w nieodwiedzonych: {{ world.stormChance }}%
            </div>
            <!-- prędkość wspólna z bitwą (#145) -->
            <div class="fx-speed" role="radiogroup" aria-label="prędkość animacji rejsu">
                <span class="kicker">ANIMACJE</span>
                <button
                    v-for="s in FX_SPEEDS"
                    :key="s.value"
                    class="fx-opt"
                    :class="{ on: fxSpeed === s.value }"
                    role="radio"
                    :aria-checked="fxSpeed === s.value"
                    @click="setFxSpeed(s.value)"
                >{{ s.label }}</button>
            </div>
            </div>
        </div>

        <div class="frame" :style="{ '--cols': world.width, '--rows': world.height }">
            <div></div>
            <div class="axis cols"><span v-for="c in cols" :key="c">{{ c }}</span></div>
            <div class="axis rows"><span v-for="r in rows" :key="r">{{ r }}</span></div>
            <div ref="stage" class="stage">
            <div ref="gridEl" class="grid">
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
                    <span v-if="cell.cls.island" class="isle" :class="{ besieged: cell.marks?.besieged && !cell.cls.owned }">
                        <Castle v-if="cell.marks?.stronghold" class="castle" :size="20" :stroke-width="2.25" />
                    </span>
                    <span v-else-if="cell.cls.port" class="isle port"><Store class="stall" :size="18" :stroke-width="2.25" /></span>
                    <span v-else-if="cell.cls.wild" class="isle wild"><TreePalm class="palm" :size="18" :stroke-width="2.25" /></span>
                    <span v-else-if="cell.cls.gate" class="portal"></span>
                    <span v-if="cell.cls.owned" class="flag"></span>
                    <span v-if="cell.marks?.buildings.length" class="blds">
                        <component :is="b.icon" v-for="b in cell.marks.buildings" :key="b.type" :size="13" :stroke-width="2.5" />
                        <em v-if="cell.marks.moreBuildings">+{{ cell.marks.moreBuildings }}</em>
                    </span>
                    <span v-if="cell.marks && (cell.marks.shipyard || cell.marks.deposit.length)" class="badges">
                        <span v-if="cell.marks.shipyard" class="yard"><Anchor :size="12" :stroke-width="2.5" />{{ cell.marks.shipyard }}</span>
                        <span v-if="cell.marks.deposit.length" class="dep" :class="{ idle: cell.marks.depositIdle }">
                            <i v-for="r in cell.marks.deposit" :key="r.key" :style="{ '--res': r.color }"><component :is="r.icon" :size="11" :stroke-width="2.5" /></i>
                        </span>
                    </span>
                    <span v-if="cell.cls.attacked" class="alarm">⚔</span>
                    <span v-if="cell.cls.here" class="fleet"></span>
                    <span v-if="cell.name" class="name">{{ cell.name }}</span>
                </button>
            </div>
            <div
                ref="fxLayer"
                class="fx-layer"
                :class="{ catch: animating }"
                aria-hidden="true"
                :title="animating ? 'Kliknij, aby pominąć animację' : undefined"
                @click="animating && emit('skip')"
            ></div>
            </div>
        </div>

        <div class="legend">
            <span><i class="lg-fleet"></i>Twoja flota</span>
            <span><i class="lg-reach"></i>zasięg rejsu</span>
            <span><i class="lg-risk"></i>w zasięgu, nieodwiedzony — ryzyko sztormu</span>
            <span><i class="lg-isle"></i>wyspa</span>
            <span><Castle :size="12" :stroke-width="2.25" class="lg-icon" />twierdza<i class="lg-siege"></i>oblężona</span>
            <span><Anchor :size="11" :stroke-width="2.5" class="lg-icon" />stocznia i poziom</span>
            <span v-for="r in RESOURCES" :key="r.key" class="dep"><i :style="{ '--res': r.color }"><component :is="DEPOSIT_MARKS[r.key]" :size="9" :stroke-width="2.5" /></i>złoże: {{ r.label.toLowerCase() }}</span>
            <span class="dep idle"><i :style="{ '--res': 'var(--text-muted)' }"></i>złoże stoi (bez bandery albo pełny magazyn)</span>
            <span v-for="(icon, type) in BUILDING_MARKS" :key="type" class="lg-bld"><component :is="icon" :size="11" :stroke-width="2.5" />{{ BUILDING_LABELS[type].toLowerCase() }}</span>
            <span><i class="lg-port"><Store :size="9" :stroke-width="2.5" /></i>port handlowy (rynek)</span>
            <span><i class="lg-wild"><TreePalm :size="9" :stroke-width="2.5" /></i>dzika wyspa (ekspedycja)</span>
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
.head-side { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
.fx-speed { display: flex; align-items: center; gap: 5px; }
.fx-speed .kicker { margin-right: 3px; }
.fx-opt { padding: 4px 9px; border-radius: 7px; border: 1px solid var(--line-btn); background: var(--bg-card-off); color: var(--text-muted); font: 600 11px var(--font-mono); cursor: pointer; }
.fx-opt.on { border-color: var(--info); color: var(--info); background: var(--bg-card-active); }
.storm { padding: 6px 11px; border-radius: 8px; background: #2a2418; border: 1px solid #5a4a28; font: 600 11px var(--font-mono); color: var(--warn); white-space: nowrap; }

.frame { --cell: 42px; display: grid; grid-template-columns: 18px auto; grid-template-rows: 16px auto; gap: 6px; width: max-content; }
.axis { display: grid; gap: 4px; font: 600 10px var(--font-mono); color: var(--text-label); }
.axis.cols { grid-template-columns: repeat(var(--cols), var(--cell)); text-align: center; }
.axis.rows { grid-template-rows: repeat(var(--rows), var(--cell)); }
.axis.rows span { display: flex; align-items: center; justify-content: center; }
.stage { position: relative; }
/* nad wyspami (z-index 1) i nazwami po najechaniu */
.fx-layer { position: absolute; inset: 0; pointer-events: none; z-index: 4; }
.fx-layer.catch { pointer-events: auto; cursor: pointer; }
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
/* wyspy nigdy nie stoją obok siebie, więc wyspa i jej znaczniki mogą wychodzić
   poza swoją komórkę na sąsiednią wodę — komórka wyspy leży nad sąsiadami */
.cell.island { z-index: 1; }
.isle { position: relative; width: 40px; height: 32px; border-radius: 50% 45% 50% 40%; background: #c9a874; box-shadow: inset -4px -4px 0 #a68756; display: flex; align-items: center; justify-content: center; }
.cell.locked .isle { opacity: .45; }
.castle { color: #3a2c18; }
.isle.besieged { box-shadow: inset -4px -4px 0 #a68756, 0 0 0 2px var(--danger); }
.isle.besieged .castle { color: var(--danger); }
/* znaczniki (#131): rogi zostają dla bandery i alarmu kontrataku; stocznia i złoże
   wiszą pod wyspą, budynki nad nią — oba rzędy mogą wyjść poza komórkę */
.badges { position: absolute; left: 50%; bottom: -9px; transform: translateX(-50%); display: flex; align-items: center; justify-content: center; gap: 4px; line-height: 1; white-space: nowrap; pointer-events: none; padding: 2px 4px; border-radius: 8px; background: #0a1b23cc; }
.yard { display: inline-flex; align-items: center; gap: 1px; color: var(--info-light); font: 700 11px var(--font-mono); }
.dep { display: inline-flex; gap: 1px; }
/* złoże: krążek w kolorze surowca — ciemne tokeny drewna i stali giną na morzu jako sama kreska */
.dep i { display: inline-flex; align-items: center; justify-content: center; width: 15px; height: 15px; border-radius: 50%; background: var(--res); color: #10202a; }
.dep.idle i { background: transparent; box-shadow: inset 0 0 0 1.5px var(--res); color: var(--res); opacity: .7; }
/* budynki osady: rząd nad wyspą */
.blds { position: absolute; left: 50%; top: -9px; transform: translateX(-50%); display: flex; align-items: center; justify-content: center; gap: 2px; color: var(--accent-light); line-height: 1; white-space: nowrap; pointer-events: none; padding: 2px 4px; border-radius: 8px; background: #0a1b23cc; }
.blds em { font: 700 10px var(--font-mono); font-style: normal; }
/* port handlowy (#140): neutralna wyspa w kolorze morskiej zieleni, z kramem zamiast twierdzy */
.cell.port { z-index: 1; }
.isle.port { background: #7fc4a4; box-shadow: inset -4px -4px 0 #5a9e80; }
.stall { color: #12352a; }
/* dzika wyspa (#144): dżungla w ciemnej zieleni, z palmą zamiast twierdzy */
.cell.wild { z-index: 1; }
.isle.wild { background: #5f9a4a; box-shadow: inset -4px -4px 0 #44753a; }
.palm { color: #f3e7b8; }
.portal { width: 22px; height: 22px; border-radius: 50%; border: 2px dashed #6f86d6; box-shadow: inset 0 0 6px #4a5ea8; }
.cell.gate-open .portal { border-style: solid; border-color: var(--info); }
.flag { position: absolute; top: 2px; left: 2px; z-index: 1; width: 10px; height: 10px; border-radius: 2px; background: var(--accent); box-shadow: 0 0 0 1.5px #0a1b23; }
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
.legend .lg-port { display: inline-flex; align-items: center; justify-content: center; width: 13px; height: 13px; border-radius: 50%; background: #7fc4a4; color: #12352a; }
.legend .lg-wild { display: inline-flex; align-items: center; justify-content: center; width: 13px; height: 13px; border-radius: 50%; background: #5f9a4a; color: #f3e7b8; }
.lg-gate { width: 12px; height: 12px; border-radius: 50%; border: 2px dashed #6f86d6; }
.lg-flag { width: 8px; height: 8px; border-radius: 2px; background: var(--accent); }
.lg-icon { color: var(--text-2); flex: none; }
.legend .dep { gap: 7px; }
.legend .dep > i { display: inline-flex; width: 13px; height: 13px; }
.lg-bld { color: var(--text-muted); }
.lg-bld svg { color: var(--accent-light); flex: none; }
.lg-siege { width: 10px; height: 10px; border-radius: 50%; box-shadow: 0 0 0 1.5px var(--danger); margin-left: 4px; }
.lg-fog { width: 14px; height: 14px; border-radius: 4px; background: #0a1b23; box-shadow: inset 0 0 0 1px #12303a; }
.hint { margin: 0; font: 400 12px var(--font-body); color: var(--text-muted); max-width: 560px; }
.hint b { color: var(--text-2); }
.note { margin: 0; font: 500 12px var(--font-mono); color: var(--info); max-width: 560px; }
</style>
