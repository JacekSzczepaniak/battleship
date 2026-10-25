<!--
    Drzewko technologii (Epic G, układ z projektu Rejs #111): poziomy z kosztem,
    w każdym węzły gałęzi w stanach zablokowany / dostępny / w toku / ukończony.
    Start badania w uczelni wyspy, przy której stoi kapitan. Reguły (jedno
    badanie naraz, poprzedni poziom, ranga, uczelnia pod banderą, koszt) liczy
    backend — tu tylko opisujemy jego kod blokady. Technologie ze zdobyczy (#121)
    są poza poziomami: osobna sekcja „Tylko z abordażu”, bez przycisku badania.
    Ścieżka questowa (#144): artefakt z ekspedycji odblokowuje technologię
    z uczelni od razu (węzeł dostaje znacznik „artefakt”), a legenda „Czarna
    perła” jest tylko z questu — obie w sekcji „Z questu”.
-->
<script setup lang="ts">
import { computed } from 'vue';
import {
    RANK_LABELS, TECH_BRANCH_LABELS, TECH_LABELS,
    type ResearchDTO, type ResourcesDTO, type TechLevelDTO, type TechnologyDTO, type TechnologyName,
} from '../api/expeditionApi';
import { formatResources, missing } from '../lib/resources';
import { ticksLabel } from '../lib/counterattack';

const props = defineProps<{
    research: ResearchDTO;
    // magazyn wyspy + ładownia — tym płaci się za badanie; null = kapitan na morzu
    available: ResourcesDTO | null;
    // nazwa wyspy floty, gdy stoi na niej czynna uczelnia (#130); null = badania nie ruszą stąd
    academyHere: string | null;
    busy: boolean;
}>();

const emit = defineEmits<{
    research: [technology: TechnologyName];
}>();

// opis efektu = to, co technologia zmienia w CaptainProfile / bitwie (sprawdzone z kodem przy #111)
const EFFECTS: Record<TechnologyName, string> = {
    spichlerze: 'Magazyn złoża mieści 20 tików produkcji zamiast 10.',
    'mapy-sztormowe': 'Połowa szansy sztormu akwenu przy pierwszym wpłynięciu: Morze Rozbitków 10% zamiast 20%, Ocean Sztormów 20% zamiast 40%, Morze Mgieł 5% zamiast 10%.',
    'sonar-dalekiego-zasiegu': 'Sonar w bitwach wyprawy: krzyż o promieniu 4 zamiast 3.',
    'zegluga-dwusektorowa': 'Rejs o 2 sektory (także po skosie) za 1 tik; sektor po drodze też zostaje odkryty.',
    'pojemne-ladownie': 'Ładownia każdego statku +50% (w dół).',
    'dok-remontowy': 'Remont za połowę ceny (w górę).',
    'lepsze-narzedzia': 'Złoże +1 na tik swojego surowca, przed mnożnikiem akwenu (na Oceanie Sztormów +2); tartak i kopalnia podwajają powiększony urobek.',
    'niszczyciel-zwiadowczy': 'Stocznia buduje niszczyciel zwiadowczy: 2 sonary zamiast torpedy.',
    'nawigacja-oceaniczna': 'Otwiera otwarty ocean na wschodniej krawędzi Oceanu Sztormów: przejście na Morze Mgieł i z powrotem za 1 tik, bez bitwy.',
    'kuter-torpedowy': 'Stocznia buduje kuter torpedowy: torpeda zamiast sonaru. Pływa u wroga na Oceanie Sztormów.',
    'ciezki-niszczyciel': 'Stocznia buduje ciężki niszczyciel: 2 torpedy. Pływa u wroga na Oceanie Sztormów.',
    'lotniskowiec-eskortowy': 'Stocznia buduje lotniskowiec eskortowy: nalot i sonar. Pływa u wroga na Morzu Mgieł.',
    'kuter-remontowy': 'Stocznia buduje kuter remontowy: warsztat zamiast sonaru, bez broni i bez eskorty. Na każdym tiku naprawia jeden statek do 3 pól, od najmniejszego, za koszt remontu z ładowni.',
    'czarna-perla': 'Widoczność +1 na wszystkich akwenach: flota odkrywa szersze sąsiedztwo, a na Morzu Mgieł widzi sąsiednie sektory zamiast samego swojego.',
};

const current = computed(() => props.research.current);
const done = computed(() => props.research.technologies.filter(t => t.state === 'researched').length);
const complete = computed(() => props.research.technologies.length > 0 && done.value === props.research.technologies.length);

const levels = computed(() => props.research.levels.map(l => ({
    level: l,
    nodes: props.research.technologies.filter(t => t.unlock === 'academy' && t.level === l.level),
})));

// technologie ze zdobyczy (#121): odblokowuje je „Zbadaj konstrukcję” po abordażu, nie uczelnia
const boarding = computed(() => props.research.technologies.filter(t => t.unlock === 'boarding'));

// ścieżka questowa (#144): legenda tylko z ekspedycji i technologie uczelni zdobyte jako artefakt
const legends = computed(() => props.research.technologies.filter(t => t.unlock === 'quest'));
const artifacts = computed(() => props.research.technologies.filter(t => t.unlock === 'academy' && fromArtifact(t)));

function fromArtifact(t: TechnologyDTO): boolean {
    return (props.research.artifacts ?? []).includes(t.id);
}

function levelLabel(l: TechLevelDTO): string {
    const rank = l.requiredRank ? ` · od rangi ${RANK_LABELS[l.requiredRank]}` : '';
    return `${formatResources(l.cost)} · ${ticksLabel(l.ticks)}${rank}`;
}

function blockLabel(t: TechnologyDTO): string | null {
    const level = props.research.levels.find(l => l.level === t.level);
    switch (t.blocker) {
        case null: return null;
        case 'researched': return 'zbadana';
        case 'boarding-only': return 'zdobądź abordażem i zbadaj konstrukcję';
        case 'quest-only': return 'tylko z ekspedycji: skarb Wyspy Czaszki (Wody Czarnej Perły)';
        case 'busy': return 'trwa inne badanie';
        case 'prerequisite': return 'najpierw poziom 1 tej gałęzi';
        case 'rank': return `wymaga rangi ${level?.requiredRank ? RANK_LABELS[level.requiredRank] : ''}`;
        case 'no-academy': return 'na tej wyspie nie ma czynnej uczelni';
        case 'not-present': return 'dopłyń na wyspę z uczelnią';
        case 'resources': return props.available && level ? missing(props.available, level.cost) : 'brakuje surowców';
    }
}

/** Blokada wynikająca z miejsca albo surowców — przycisk zostaje, tylko wyłączony. */
function showButton(t: TechnologyDTO): boolean {
    return t.state === 'available' || ['no-academy', 'not-present', 'resources'].includes(t.blocker ?? '');
}

function percent(progress: number, ticks: number): number {
    return ticks > 0 ? Math.round((progress / ticks) * 100) : 0;
}
</script>

<template>
    <div class="tech">
        <div>
            <h3 v-if="complete" class="title">Drzewko ukończone</h3>
            <template v-else-if="current">
                <h3 class="title">{{ current.paused ? 'Badanie wstrzymane' : 'Badanie w toku' }}: {{ TECH_LABELS[current.technology] }}</h3>
                <p class="status" :class="{ paused: current.paused }">
                    <template v-if="current.paused">{{ current.islandName }} bez Twojej bandery — odbij wyspę, by wznowić ({{ current.progress }}/{{ current.ticks }} tików).</template>
                    <template v-else>{{ current.progress }}/{{ current.ticks }} tików, koniec za {{ ticksLabel(current.ticksLeft) }} · uczelnia: {{ current.islandName }}</template>
                </p>
                <span class="bar" role="progressbar" :aria-valuenow="current.progress" :aria-valuemax="current.ticks">
                    <span class="fill" :class="{ paused: current.paused }" :style="{ width: percent(current.progress, current.ticks) + '%' }"></span>
                </span>
            </template>
            <template v-else>
                <h3 class="title">Żadne badanie nie trwa</h3>
                <p v-if="academyHere" class="status here">🎓 Uczelnia na wyspie {{ academyHere }} — zacznij badanie tutaj: wybierz technologię poniżej.</p>
                <p v-else class="status">Zaczniesz je w uczelni na wyspie pod Twoją banderą, stojąc tam z flotą.</p>
            </template>
            <p class="rules">Naraz trwa jedno badanie. Koszt pobierany z magazynu wyspy z uczelnią, resztę dopłaca ładownia. Czas liczy się w tikach pod banderą wyspy.</p>
        </div>

        <section v-for="l in levels" :key="l.level.level" class="level">
            <div class="level-head">
                <span class="level-name">Poziom {{ l.level.level }}</span>
                <span class="level-cost">{{ levelLabel(l.level) }}</span>
            </div>
            <div class="nodes">
                <div v-for="t in l.nodes" :key="t.id" class="node" :class="t.state">
                    <div class="node-head">
                        <div>
                            <div class="branch">{{ TECH_BRANCH_LABELS[t.branch] }}</div>
                            <div class="node-name">{{ TECH_LABELS[t.id] }}</div>
                        </div>
                        <span v-if="t.state === 'researched' && fromArtifact(t)" class="quest-tag" title="z artefaktu — bez uczelni">artefakt</span>
                        <span v-else-if="t.state === 'researched'" class="check" title="zbadana — działa">✓</span>
                    </div>
                    <div class="desc">{{ EFFECTS[t.id] }}</div>
                    <template v-if="t.state === 'in-progress' && current">
                        <span class="bar small" role="progressbar" :aria-valuenow="current.progress" :aria-valuemax="current.ticks">
                            <span class="fill" :class="{ paused: current.paused }" :style="{ width: percent(current.progress, current.ticks) + '%' }"></span>
                        </span>
                        <span class="hint on">{{ current.paused ? 'wstrzymane' : `${current.progress}/${current.ticks} · za ${ticksLabel(t.ticksLeft ?? 0)}` }}</span>
                    </template>
                    <template v-else-if="t.state !== 'researched'">
                        <button
                            v-if="showButton(t)"
                            class="btn btn-small btn-secondary"
                            :disabled="busy || t.state !== 'available'"
                            :title="blockLabel(t) ?? ''"
                            @click="emit('research', t.id)"
                        >
                            Badaj
                        </button>
                        <span v-if="blockLabel(t)" class="hint">{{ blockLabel(t) }}</span>
                    </template>
                </div>
            </div>
        </section>

        <section v-if="boarding.length" class="level">
            <div class="level-head">
                <span class="level-name">Tylko z abordażu</span>
                <span class="level-cost">bez kosztu i tików · zdobyty statek przepada</span>
            </div>
            <div class="nodes">
                <div v-for="t in boarding" :key="t.id" class="node boarding" :class="t.state">
                    <div class="node-head">
                        <div>
                            <div class="branch">Wariant wroga</div>
                            <div class="node-name">{{ TECH_LABELS[t.id] }}</div>
                        </div>
                        <span v-if="t.state === 'researched'" class="check" title="zbadana — wariant w stoczni">✓</span>
                    </div>
                    <div class="desc">{{ EFFECTS[t.id] }}</div>
                    <span v-if="t.state !== 'researched'" class="hint">{{ blockLabel(t) }}</span>
                </div>
            </div>
        </section>

        <section v-if="legends.length" class="level">
            <div class="level-head">
                <span class="level-name">Z questu</span>
                <span class="level-cost">ekspedycje na dzikie wyspy · skarb etapu 3</span>
            </div>
            <p class="rules">Artefakt od razu odblokowuje niezbadaną technologię z poziomów 1–2 uczelni — bez kosztu, tików i uczelni. Legenda jest tylko z questu.</p>
            <div class="nodes">
                <div v-for="t in legends" :key="t.id" class="node quest" :class="t.state">
                    <div class="node-head">
                        <div>
                            <div class="branch">Legenda · {{ TECH_BRANCH_LABELS[t.branch] }}</div>
                            <div class="node-name">{{ TECH_LABELS[t.id] }}</div>
                        </div>
                        <span v-if="t.state === 'researched'" class="check" title="zdobyta — działa">✓</span>
                    </div>
                    <div class="desc">{{ EFFECTS[t.id] }}</div>
                    <span v-if="t.state !== 'researched'" class="hint">{{ blockLabel(t) }}</span>
                </div>
                <div v-for="t in artifacts" :key="t.id" class="node quest researched">
                    <div class="node-head">
                        <div>
                            <div class="branch">Artefakt · poziom {{ t.level }}</div>
                            <div class="node-name">{{ TECH_LABELS[t.id] }}</div>
                        </div>
                        <span class="check" title="z artefaktu — działa">✓</span>
                    </div>
                    <div class="desc">{{ EFFECTS[t.id] }}</div>
                </div>
            </div>
        </section>
    </div>
</template>

<style scoped>
.tech { display: flex; flex-direction: column; gap: 18px; }
.title { margin: 0; font: 700 22px var(--font-head); }
.status { margin: 4px 0 0; font: 400 13px var(--font-body); color: var(--text-2); }
.status.here { color: var(--info); }
.status.paused { color: var(--warn); }
.rules { margin: 6px 0 0; font: 400 13px var(--font-body); color: var(--text-muted); line-height: 1.5; }
.bar { display: block; height: 6px; margin-top: 8px; border-radius: 3px; background: var(--bg-card-off); border: 1px solid var(--line-card); overflow: hidden; }
.bar.small { height: 5px; margin-top: 2px; }
.fill { display: block; height: 100%; background: var(--info); }
.fill.paused { background: var(--warn); }
.level { display: flex; flex-direction: column; gap: 10px; }
.level-head { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; flex-wrap: wrap; }
.level-name { font: 600 14px var(--font-head); }
.level-cost { font: 500 11px var(--font-mono); color: var(--text-label); }
.nodes { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.node { display: flex; flex-direction: column; gap: 5px; padding: 12px 14px; border-radius: 12px; background: var(--bg-card); border: 1px solid var(--line-card); }
.node.available { border-color: var(--line-btn); }
.node.in-progress { border-color: var(--info); }
.node.locked { opacity: .5; }
.node.boarding { border-style: dashed; }
.node.quest { border-style: double; border-width: 3px; }
.quest-tag { flex: none; padding: 1px 6px; border-radius: 8px; border: 1px solid var(--info); color: var(--info); font: 600 9px var(--font-mono); letter-spacing: .08em; text-transform: uppercase; }
.node-head { display: flex; justify-content: space-between; gap: 8px; align-items: start; }
.branch { font: 600 9px var(--font-mono); letter-spacing: .14em; text-transform: uppercase; color: var(--text-label); }
.node-name { font: 600 14px var(--font-head); }
.check { flex: none; width: 18px; height: 18px; border-radius: 50%; background: var(--bg-card-active); border: 1px solid var(--info); color: var(--info); font: 700 11px var(--font-body); display: flex; align-items: center; justify-content: center; }
.desc { font: 400 12px var(--font-body); color: var(--text-desc); line-height: 1.45; }
.node .btn { align-self: flex-start; margin-top: 2px; }
.hint { font: 500 11px var(--font-mono); color: var(--text-muted); }
.hint.on { color: var(--info); }
@media (max-width: 520px) { .nodes { grid-template-columns: minmax(0, 1fr); } }
</style>
