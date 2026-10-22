<!--
    Drzewko technologii (Epic G): 4 gałęzie × 2 poziomy, badanie w toku
    i start badania w uczelni wyspy, przy której stoi kapitan. Reguły
    (jedno badanie naraz, poprzedni poziom, ranga, uczelnia pod banderą,
    koszt) liczy backend — tu tylko opisujemy jego kod blokady.
-->
<script setup lang="ts">
import { computed } from 'vue';
import {
    RANK_LABELS, TECH_BRANCH_LABELS, TECH_LABELS,
    type ResearchDTO, type ResourcesDTO, type TechBranchName, type TechLevelDTO, type TechnologyDTO, type TechnologyName,
} from '../api/expeditionApi';
import { formatResources, missing } from '../lib/resources';

const props = defineProps<{
    research: ResearchDTO;
    // magazyn wyspy + ładownia — tym płaci się za badanie; null = kapitan na morzu
    available: ResourcesDTO | null;
    busy: boolean;
}>();

const emit = defineEmits<{
    research: [technology: TechnologyName];
}>();

const EFFECTS: Record<TechnologyName, string> = {
    spichlerze: 'magazyn złoża mieści 20 tików produkcji zamiast 10',
    'mapy-sztormowe': 'sztorm przy pierwszym wpłynięciu: 10% zamiast 20%',
    'pojemne-ladownie': 'ładownia każdego statku +50% (w dół)',
    'dok-remontowy': 'remont za połowę ceny (w górę)',
    'lepsze-narzedzia': 'złoże +1 na tik; tartak i kopalnia podwajają powiększony urobek',
};

const current = computed(() => props.research.current);

function cell(branch: TechBranchName, level: number): TechnologyDTO | null {
    return props.research.technologies.find(t => t.branch === branch && t.level === level) ?? null;
}

function levelOf(t: TechnologyDTO): TechLevelDTO {
    return props.research.levels.find(l => l.level === t.level)!;
}

function levelLabel(l: TechLevelDTO): string {
    const rank = l.requiredRank ? ` · od rangi ${RANK_LABELS[l.requiredRank]}` : '';
    return `${formatResources(l.cost)} · ${ticksLabel(l.ticks)}${rank}`;
}

function ticksLabel(n: number): string {
    return n === 1 ? '1 tik' : n >= 2 && n <= 4 ? `${n} tiki` : `${n} tików`;
}

function blockLabel(t: TechnologyDTO): string | null {
    const level = levelOf(t);
    switch (t.blocker) {
        case null: return null;
        case 'researched': return 'zbadana';
        case 'busy': return 'trwa inne badanie';
        case 'prerequisite': return 'najpierw poziom 1 tej gałęzi';
        case 'rank': return `wymaga rangi ${level.requiredRank ? RANK_LABELS[level.requiredRank] : ''}`;
        case 'no-academy': return 'na tej wyspie nie ma czynnej uczelni';
        case 'not-present': return 'dopłyń na wyspę z uczelnią';
        case 'resources': return props.available ? missing(props.available, level.cost) : 'brakuje surowców';
    }
}

/** Blokada wynikająca z miejsca albo surowców — przycisk zostaje, tylko wyłączony. */
function showButton(t: TechnologyDTO): boolean {
    return t.state === 'available' || ['no-academy', 'not-present', 'resources'].includes(t.blocker ?? '');
}
</script>

<template>
    <div class="tech">
        <p v-if="current" class="progress" :class="{ paused: current.paused }">
            <template v-if="current.paused">
                ⏸️ {{ TECH_LABELS[current.technology] }} — badanie wstrzymane: {{ current.islandName }} bez Twojej bandery.
                Odbij wyspę, by wznowić ({{ current.progress }}/{{ current.ticks }} tików).
            </template>
            <template v-else>
                ⏳ {{ TECH_LABELS[current.technology] }} — {{ current.progress }}/{{ current.ticks }} tików,
                koniec za {{ ticksLabel(current.ticksLeft) }} (uczelnia: {{ current.islandName }})
            </template>
            <span class="bar" role="progressbar"><span class="fill" :style="{ width: Math.round(current.progress / current.ticks * 100) + '%' }"></span></span>
        </p>
        <p v-else class="muted">Żadne badanie nie trwa. Zaczniesz je w uczelni na wyspie pod Twoją banderą, stojąc tam z flotą.</p>

        <div class="grid" :style="{ gridTemplateColumns: `8rem repeat(${research.branches.length}, minmax(9rem, 1fr))` }">
            <span></span>
            <strong v-for="b in research.branches" :key="b" class="branch">{{ TECH_BRANCH_LABELS[b] }}</strong>
            <template v-for="l in research.levels" :key="l.level">
                <span class="level">
                    <strong>Poziom {{ l.level }}</strong>
                    <small class="muted">{{ levelLabel(l) }}</small>
                </span>
                <div v-for="b in research.branches" :key="`${b}-${l.level}`" class="node" :class="cell(b, l.level)?.state ?? 'empty'">
                    <template v-if="cell(b, l.level)">
                        <strong>{{ TECH_LABELS[cell(b, l.level)!.id] }}</strong>
                        <small>{{ EFFECTS[cell(b, l.level)!.id] }}</small>
                        <small v-if="cell(b, l.level)!.state === 'researched'" class="on">✅ zbadana — działa</small>
                        <small v-else-if="cell(b, l.level)!.state === 'in-progress'" class="on">
                            {{ current?.paused ? '⏸️ wstrzymane' : `⏳ za ${ticksLabel(cell(b, l.level)!.ticksLeft ?? 0)}` }}
                        </small>
                        <template v-else>
                            <button
                                v-if="showButton(cell(b, l.level)!)"
                                class="btn small"
                                :disabled="busy || cell(b, l.level)!.state !== 'available'"
                                :title="blockLabel(cell(b, l.level)!) ?? ''"
                                @click="emit('research', cell(b, l.level)!.id)"
                            >
                                Badaj
                            </button>
                            <small v-if="blockLabel(cell(b, l.level)!)" class="block">{{ blockLabel(cell(b, l.level)!) }}</small>
                        </template>
                    </template>
                    <small v-else class="muted">—</small>
                </div>
            </template>
        </div>
        <p class="muted">Naraz trwa jedno badanie. Płaci magazyn wyspy z uczelnią, brakującą resztę ładownia; czas liczą tiki pod banderą tej wyspy.</p>
    </div>
</template>

<style scoped>
.tech { margin-top: 0.3rem; }
.progress { margin: 0 0 0.6rem; display: flex; flex-wrap: wrap; gap: 0.3rem 0.6rem; align-items: center; }
.progress.paused { color: var(--warn); }
.bar { display: inline-block; width: 8rem; height: 6px; background: var(--bg-disabled); border-radius: 3px; overflow: hidden; }
.fill { display: block; height: 100%; background: var(--info); }
.grid { display: grid; gap: 0.4rem; overflow-x: auto; }
.branch { font-size: 0.9rem; }
.level { display: flex; flex-direction: column; gap: 0.15rem; }
.node { display: flex; flex-direction: column; gap: 0.2rem; padding: 0.4rem 0.5rem; border: 1px solid var(--line-btn); border-radius: 8px; font-size: 0.85rem; }
.node.empty { border-style: dashed; opacity: 0.5; }
.node.researched { border-color: var(--info); }
.node.locked strong { opacity: 0.6; }
.node .btn { align-self: flex-start; }
.on { color: var(--info); }
.block { color: var(--text-muted); }
.btn { padding: 5px 10px; font: 700 12px var(--font-head); border-radius: 8px; background: var(--bg-card-active); color: var(--text); border: 1px solid var(--line-btn); cursor: pointer; }
.btn[disabled] { background: var(--bg-disabled); color: var(--text-dim); cursor: not-allowed; }
.muted { color: var(--text-muted); font-size: 0.85rem; }
</style>
