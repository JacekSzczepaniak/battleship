<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Anchor, Wrench } from 'lucide-vue-next';
import { createGame, getGame } from '../api/gameApi';
import { RANK_LABELS, SHIP_LABELS, startIslandBattle, type RankName } from '../api/expeditionApi';
import { setCurrentBattle } from '../lib/expeditionSession';
import { getBattleReport, type BattleReport } from '../lib/battleSession';
import { shipName } from '../lib/ships';
import { RESOURCES, formatResources, isEmpty } from '../lib/resources';

const route = useRoute();
const router = useRouter();
const id = String(route.params.id ?? '');

const report = ref<BattleReport | null>(null);
const loading = ref(true);
const busy = ref(false);
const error = ref('');
const animate = ref(false);

const won = computed(() => report.value?.outcome === 'won');
const exp = computed(() => report.value?.expedition ?? null);

function rankLabel(rank: RankName): string {
    return RANK_LABELS[rank] ?? rank;
}
const fmt = (n: number) => n.toLocaleString('pl-PL');

const description = computed(() => {
    const r = report.value;
    if (!r) return '';
    if (!r.expedition) {
        return won.value
            ? 'Wrogie wody oczyszczone. Trening bez stawki — flota wraca do portu cała, bez XP i łupów.'
            : 'Tym razem morze było po stronie wroga. Trening bez stawki — nic nie przepada.';
    }
    const damaged = r.expedition.settle.damagedShips.length;
    if (r.expedition.settle.defense) {
        return won.value
            ? `Kontratak odparty — bandera nad wyspą ${r.expedition.islandName} zostaje.`
                + (damaged ? ' Jednostki zatopione w boju wracają do portu na remont.' : ' Cała flota wraca bez szwanku.')
            : `Napastnik przełamał obronę i zdjął Twoją banderę z wyspy ${r.expedition.islandName}. Jednostki zatopione w tej bitwie przepadły — XP zostaje na zawsze.`;
    }
    return won.value
        ? `Flota wroga poszła na dno — ${r.expedition.islandName} uznaje Twoją banderę.`
            + (damaged ? ' Jednostki zatopione w boju wracają do portu na remont.' : ' Cała flota wraca bez szwanku.')
        : `Wróg utrzymał wody wyspy ${r.expedition.islandName}. Jednostki zatopione w tej bitwie przepadły — XP zostaje na zawsze.`;
});

// postęp rangi: stara część + animowany przyrost (względem progu następnej rangi)
const xp = computed(() => {
    const e = exp.value;
    if (!e) return null;
    const after = e.settle.xp;
    const target = e.nextRank?.xpTarget ?? null;
    if (e.settle.rankUp || target === null) {
        return { from: e.rankBefore, to: e.settle.rank, after, target, oldPct: 0, gainPct: 100, rankUp: e.settle.rankUp };
    }
    const oldPct = Math.min(100, (e.xpBefore / target) * 100);
    const gainPct = Math.min(100 - oldPct, (e.settle.awarded / target) * 100);
    return { from: e.settle.rank, to: e.nextRank!.rank, after, target, oldPct, gainPct, rankUp: false };
});

const accuracy = computed(() => {
    const r = report.value;
    if (!r || r.shots === 0) return 0;
    return Math.round((r.hits / r.shots) * 100);
});

const enemyChips = computed(() => (report.value?.enemySunk ?? []).map((l, i) => ({ key: i, label: shipName(l) })));

interface FleetRow { key: string; name: string; state: 'ok' | 'damaged' | 'lost'; note: string }

const fleetRows = computed<FleetRow[]>(() => {
    const r = report.value;
    if (!r) return [];
    const e = r.expedition;
    if (!e) {
        return r.playerSunk.map((l, i) => ({ key: `t${i}`, name: shipName(l), state: 'damaged', note: 'zatopiony w treningu — bez strat' }));
    }
    const rows: FleetRow[] = e.fleet
        .map(s => ({
            key: s.id,
            name: SHIP_LABELS[s.type],
            state: s.damaged ? 'damaged' as const : 'ok' as const,
            note: s.damaged ? `do remontu · ${formatResources(s.repairCost)}` : 'sprawny',
        }))
        .sort((a, b) => (a.state === b.state ? 0 : a.state === 'damaged' ? -1 : 1));
    e.settle.lostShips.forEach((t, i) => rows.unshift({ key: `lost${i}`, name: SHIP_LABELS[t], state: 'lost', note: 'stracony w bitwie' }));
    return rows;
});

const hasDamaged = computed(() => exp.value?.fleet.some(s => s.damaged) ?? false);

const shipyardLabel = computed(() => {
    const e = exp.value;
    if (!e) return '';
    if (e.fleet.length === 0) return 'Odbuduj flotę (tratwa za darmo na każdej wyspie)';
    if (hasDamaged.value) return 'Napraw flotę w stoczni';
    return e.settle.lostShips.length ? 'Uzupełnij flotę w stoczni' : 'Flota sprawna — stocznia';
});

async function load() {
    loading.value = true;
    try {
        const stored = getBattleReport(id);
        if (stored) {
            report.value = stored;
            return;
        }
        // brak raportu (np. wejście z linka) — tylko to, co da się odczytać z gry
        const g = await getGame(id);
        if (!g.finished && g.status !== 'won' && g.status !== 'lost') {
            await router.replace({ name: 'game', params: { id } });
            return;
        }
        const hits = g.enemyFogGrid.hits.length + g.enemyFogGrid.sunk.reduce((s, sh) => s + sh.cells.length, 0);
        report.value = {
            gameId: id,
            outcome: g.status === 'lost' ? 'lost' : 'won',
            ruleset: g.ruleset,
            board: g.board,
            shots: hits + g.enemyFogGrid.misses.length,
            hits,
            enemySunk: g.enemyFogGrid.sunk.map(s => s.cells.length),
            enemyTotal: Object.values(g.opponentShips ?? g.allowedShips ?? {}).reduce((a, b) => a + b, 0),
            playerSunk: [],
            expedition: null,
        };
    } catch (e: any) {
        error.value = e?.message ?? 'Nie udało się wczytać wyniku bitwy';
    } finally {
        loading.value = false;
        requestAnimationFrame(() => requestAnimationFrame(() => { animate.value = true; }));
    }
}

async function repeat() {
    const r = report.value;
    if (!r) return;
    busy.value = true;
    error.value = '';
    try {
        if (r.expedition) {
            const { profileId, islandId, islandName } = r.expedition;
            const game = await startIslandBattle(profileId, islandId);
            setCurrentBattle({ profileId, gameId: game.id, islandId, islandName });
            await router.push({ name: 'place-fleet', params: { id: game.id } });
        } else {
            const game = await createGame(r.ruleset, r.board.w);
            await router.push({ name: 'place-fleet', params: { id: game.id } });
        }
    } catch (e: any) {
        error.value = e?.message ?? 'Nie udało się rozpocząć kolejnej bitwy';
    } finally {
        busy.value = false;
    }
}

onMounted(load);
</script>

<template>
    <section class="frame result" :class="{ lost: report && !won }">
        <p v-if="loading" class="muted pad">Wczytywanie wyniku…</p>
        <p v-else-if="!report" class="err pad">{{ error }}</p>

        <template v-else>
            <div class="left">
                <div class="kicker outcome-kicker">
                    {{ exp ? `${exp.settle.defense ? 'OBRONA' : 'BITWA'} · ${exp.islandName.toUpperCase()} · AKWEN I` : `BITWA TRENINGOWA · ${report.board.w}×${report.board.h}` }}
                </div>
                <h1>{{ won ? 'ZWYCIĘSTWO' : 'PORAŻKA' }}</h1>
                <p class="lead">{{ description }}</p>

                <!-- postęp rangi -->
                <div v-if="xp && exp" class="rank-progress">
                    <div class="rank-head">
                        <span v-if="xp.rankUp" class="kicker up">AWANS · {{ rankLabel(xp.from).toUpperCase() }} → {{ rankLabel(xp.to).toUpperCase() }}</span>
                        <span v-else-if="xp.target === null" class="kicker">NAJWYŻSZA RANGA · {{ rankLabel(xp.to).toUpperCase() }}</span>
                        <span v-else class="kicker">{{ rankLabel(xp.from).toUpperCase() }} → {{ rankLabel(xp.to).toUpperCase() }}</span>
                        <b class="gain">+{{ exp.settle.awarded }} XP</b>
                    </div>
                    <div class="track">
                        <div class="old" :style="{ width: xp.oldPct + '%' }"></div>
                        <div class="new" :style="{ width: (animate ? xp.gainPct : 0) + '%' }"></div>
                    </div>
                    <div class="rank-foot">
                        <template v-if="xp.target !== null && !xp.rankUp">
                            {{ fmt(xp.after) }} / {{ fmt(xp.target) }} · {{ fmt(xp.target - xp.after) }} do awansu
                        </template>
                        <template v-else-if="xp.rankUp && xp.target !== null">
                            {{ fmt(xp.after) }} XP · następna ranga od {{ fmt(xp.target) }}
                        </template>
                        <template v-else>{{ fmt(xp.after) }} XP</template>
                    </div>
                </div>

                <!-- trening: statystyki zamiast rangi -->
                <div v-else class="stats">
                    <div><b>{{ report.shots }}</b><span>OSTRZELANE POLA</span></div>
                    <div><b>{{ report.hits }}</b><span>TRAFIENIA</span></div>
                    <div><b>{{ accuracy }}%</b><span>CELNOŚĆ</span></div>
                </div>

                <div class="chips-block">
                    <div class="kicker">ZATOPIONE · {{ report.enemySunk.length }} / {{ report.enemyTotal }}</div>
                    <div class="chips">
                        <span v-for="chip in enemyChips" :key="chip.key" class="chip">{{ chip.label }}</span>
                        <span v-if="!enemyChips.length" class="chip empty">żadna jednostka wroga</span>
                    </div>
                </div>

                <div class="actions">
                    <router-link class="btn btn-primary" :to="{ name: 'home' }"><Anchor :size="17" /> Do portu</router-link>
                    <!-- wyspa pod banderą nie jest areną (#78): po zdobyciu i po obronie nie ma czego powtarzać -->
                    <button v-if="!exp || (!exp.settle.captured && !exp.settle.defense)" class="btn btn-secondary" :disabled="busy" @click="repeat">{{ busy ? 'Stawianie żagli…' : 'Powtórz bitwę' }}</button>
                </div>
                <p v-if="error" class="err">{{ error }}</p>
            </div>

            <aside class="right">
                <div class="kicker">ŁUPY</div>
                <div v-if="exp" class="loot">
                    <div class="loot-tile">
                        <span class="loot-label"><span class="res-dot" style="background: var(--info)"></span> DOŚWIADCZENIE</span>
                        <b>+{{ exp.settle.awarded }}</b>
                    </div>
                    <div v-for="r in RESOURCES" :key="r.key" class="loot-tile" :class="{ none: exp.settle.loot[r.key] === 0 }">
                        <span class="loot-label"><span class="res-dot" :style="{ background: r.color }"></span> {{ r.label.toUpperCase() }}</span>
                        <b>+{{ exp.settle.loot[r.key] }}</b>
                        <small>w magazynie: {{ exp.settle.stockpile[r.key] }}</small>
                    </div>
                </div>
                <template v-if="exp">
                    <p class="muted loot-note">Łup czeka w magazynie wyspy {{ exp.islandName }} — załadujesz go w porcie (1 tik).</p>
                    <p v-if="exp.settle.captured" class="captured">🚩 {{ exp.islandName }} pod Twoją banderą.</p>
                    <p v-if="exp.settle.defense?.held" class="captured">🛡️ Wyspa obroniona: {{ exp.islandName }} — bandera zostaje, następny kontratak najwcześniej po 8 tikach spokoju.</p>
                    <p v-if="exp.settle.defense && !exp.settle.defense.held" class="island-lost">
                        🏴 Wyspa utracona: {{ exp.islandName }} — bandera zdjęta, z magazynu przepadło {{ formatResources(exp.settle.defense.stockpileLost, 'nic — magazyn był pusty') }}. Wyspę odbijesz zwykłą bitwą.
                    </p>
                    <p v-if="exp.settle.depositStarted" class="deposit-started">
                        ⛏️ Złoże ruszyło: {{ formatResources(exp.settle.depositStarted) }}/tik — urobek zbiera się w magazynie wyspy {{ exp.islandName }}.
                    </p>
                </template>
                <p v-else class="muted no-loot">Trening nie daje łupów ani XP. Bitwy o wyspy rozegrasz z portu, gdy staniesz przy wyspie.</p>

                <div class="kicker fleet-kicker">STAN FLOTY</div>
                <ul class="fleet">
                    <li v-for="row in fleetRows" :key="row.key" :class="row.state">
                        <div class="fleet-top">
                            <span class="fleet-name">{{ row.name }}</span>
                            <span class="fleet-note">{{ row.note }}</span>
                        </div>
                        <div class="bar"><div></div></div>
                    </li>
                    <li v-if="!fleetRows.length" class="ok">
                        <div class="fleet-top"><span class="fleet-name">Bez strat</span><span class="fleet-note">cała flota sprawna</span></div>
                        <div class="bar"><div></div></div>
                    </li>
                </ul>
                <p v-if="exp && !isEmpty(exp.settle.cargoLost)" class="cargo-lost">
                    Za burtą: {{ formatResources(exp.settle.cargoLost) }} — ładunek ponad pojemność floty po stratach.
                </p>
                <router-link
                    v-if="exp"
                    class="btn btn-secondary repair"
                    :class="{ off: !hasDamaged && !exp.settle.lostShips.length }"
                    :to="{ name: 'expedition', hash: '#stocznia' }"
                >
                    <Wrench :size="16" /> {{ shipyardLabel }}
                </router-link>
            </aside>
        </template>
    </section>
</template>

<style scoped>
.frame {
    position: relative;
    min-height: 812px;
    border-radius: 18px;
    border: 1px solid var(--line-card);
    background:
        radial-gradient(circle at 50% 22%, #e8843c22, transparent 45%),
        linear-gradient(160deg, var(--bg-frame-a), var(--bg-frame-b));
    display: grid;
    grid-template-columns: 1fr 460px;
    overflow: hidden;
}
.frame.lost { background: radial-gradient(circle at 50% 22%, #c8624a1a, transparent 45%), linear-gradient(160deg, var(--bg-frame-a), var(--bg-frame-b)); }
.pad { padding: 60px; }

.left { padding: 70px 60px; display: flex; flex-direction: column; gap: 26px; }
.outcome-kicker { color: var(--accent); font-size: 12px; letter-spacing: .26em; }
.lost .outcome-kicker { color: var(--danger); }
h1 { font: 700 84px var(--font-head); line-height: .95; letter-spacing: -.02em; margin: -10px 0 0; }
.lead { font: 400 17px/1.5 var(--font-body); color: var(--text-desc); max-width: 560px; margin: 0; }

.rank-progress { max-width: 620px; }
.rank-head { display: flex; justify-content: space-between; align-items: baseline; }
.rank-head .up { color: var(--info); }
.gain { font: 700 22px var(--font-head); color: var(--info); }
.track { height: 12px; border-radius: 7px; background: var(--bg-card); border: 1px solid var(--line-card); margin-top: 10px; overflow: hidden; display: flex; }
.old { height: 100%; background: #2a5560; }
.new { height: 100%; background: linear-gradient(90deg, var(--info), var(--info-light)); transition: width .8s ease-out; }
.rank-foot { margin-top: 8px; font: 500 12px var(--font-mono); color: var(--text-muted); }

.stats { display: flex; gap: 12px; max-width: 520px; }
.stats div { flex: 1; padding: 14px; border-radius: 12px; background: var(--bg-card); border: 1px solid var(--line-card); }
.stats b { display: block; font: 700 26px var(--font-head); }
.stats span { font: 500 10px var(--font-mono); color: var(--text-muted); }

.chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
.chip { padding: 9px 14px; border-radius: 9px; background: var(--bg-card); border: 1px solid var(--line-card); font: 600 13px var(--font-head); color: var(--text-2); }
.chip.empty { color: var(--text-dim); font-weight: 500; }

.actions { display: flex; gap: 14px; margin-top: auto; }
.actions .btn { padding: 18px 30px; border-radius: 13px; }

.right { border-left: 1px solid var(--line); background: var(--bg-panel-a); padding: 44px 36px; display: flex; flex-direction: column; gap: 14px; }
.loot { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.loot-tile { padding: 16px; border-radius: 12px; background: var(--bg-card); border: 1px solid var(--line-card); display: flex; flex-direction: column; gap: 6px; }
.loot-label { display: inline-flex; align-items: center; gap: 7px; font: 500 10px var(--font-mono); color: var(--text-muted); }
.loot-label .res-dot { width: 10px; height: 10px; }
.loot-tile b { font: 700 24px var(--font-head); color: var(--info); }
.loot-tile small { font: 500 10px var(--font-mono); color: var(--text-dim); }
.loot-tile.none b { color: var(--text-dim); }
.loot-note { margin: 0; font-size: 12px; }
.deposit-started { margin: 0; font: 600 12px var(--font-mono); color: var(--info); }
.captured { margin: 0; font: 600 12px var(--font-mono); color: var(--text); }
.island-lost { margin: 0; font: 600 12px var(--font-mono); color: var(--warn); }
.cargo-lost { margin: 0; font: 500 12px var(--font-body); color: var(--danger); }
.no-loot { margin: 0; font-size: 13px; }
.fleet-kicker { margin-top: 16px; }
.fleet { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 9px; }
.fleet li { padding: 12px 14px; border-radius: 11px; background: var(--bg-card); border: 1px solid var(--line-card); }
.fleet-top { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; }
.fleet-name { font: 600 14px var(--font-head); }
.fleet-note { font: 500 10px var(--font-mono); color: var(--text-muted); text-align: right; }
.bar { height: 6px; border-radius: 3px; background: var(--bg-card-off); margin-top: 9px; overflow: hidden; }
.bar div { height: 100%; width: 100%; background: var(--info); }
.fleet li.damaged .bar div { width: 40%; background: var(--accent); }
.fleet li.damaged .fleet-note { color: var(--accent-light); }
.fleet li.lost { opacity: .7; }
.fleet li.lost .bar div { width: 0; }
.fleet li.lost .fleet-note { color: var(--danger); }
.repair { margin-top: auto; width: 100%; }
.repair.off { color: var(--text-muted); }

@media (max-width: 1100px) {
    .frame { grid-template-columns: 1fr; }
    .right { border-left: none; border-top: 1px solid var(--line); }
    h1 { font-size: 60px; }
}
</style>
