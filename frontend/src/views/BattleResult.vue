<script setup lang="ts">
import { computed, onMounted, ref, type Component } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Anchor, ArrowRight, Castle, Flag, FlagOff, PackageX, Pickaxe, RotateCcw, ShieldCheck, Waves, Wrench } from 'lucide-vue-next';
import { createGame, getGame } from '../api/gameApi';
import {
    RANK_LABELS, SHIP_LABELS, fleetShipLabel, startGuardianBattle, startIslandBattle, startSiege,
    type RankName, type ResourcesDTO, type ShipTypeName,
} from '../api/expeditionApi';
import { setCurrentBattle } from '../lib/expeditionSession';
import { getBattleReport, type BattleReport } from '../lib/battleSession';
import { shipName } from '../lib/ships';
import { RESOURCES, addResources, formatResources, isEmpty } from '../lib/resources';

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

const kicker = computed(() => {
    const r = report.value;
    if (!r) return '';
    const e = r.expedition;
    if (!e) return `${r.fort ? 'OBLĘŻENIE · TRENING' : 'BITWA TRENINGOWA'} · ${r.board.w}×${r.board.h}`;
    const kind = e.kind === 'guardian' ? 'CIEŚNINA'
        : e.settle.encounter === 'merchant' ? 'NAPAD'
        : e.settle.encounter === 'pirate' ? 'PIRACI'
        : e.settle.encounter === 'bounty-hunters' ? 'ŁOWCY NAGRÓD'
        : e.settle.kind === 'siege' ? 'OBLĘŻENIE' : e.settle.defense ? 'OBRONA' : 'BITWA';
    return `${kind} · ${e.islandName.toUpperCase()}${e.seaName ? ` · ${e.seaName.toUpperCase()}` : ''}`;
});

const description = computed(() => {
    const r = report.value;
    if (!r) return '';
    if (!r.expedition) {
        if (r.fort) {
            return won.value
                ? 'Działa fortu zamilkły. Trening bez stawki — flota wraca do portu cała, bez XP i łupów.'
                : 'Fort się obronił. Trening bez stawki — nic nie przepada.';
        }
        return won.value
            ? 'Wrogie wody oczyszczone. Trening bez stawki — flota wraca do portu cała, bez XP i łupów.'
            : 'Tym razem morze było po stronie wroga. Trening bez stawki — nic nie przepada.';
    }
    const damaged = r.expedition.settle.damagedShips.length;
    if (r.expedition.kind === 'guardian') {
        return won.value
            ? `${r.expedition.islandName} poszedł na dno — cieśnina stoi otworem w obie strony.`
                + (damaged ? ' Jednostki zatopione w boju trzeba wyremontować w stoczni.' : ' Cała flota przetrwała bitwę.')
            : `${r.expedition.islandName} utrzymał cieśninę. Jednostki zatopione w tej bitwie przepadły — XP zostaje na zawsze, a próbę możesz powtórzyć.`;
    }
    // spotkanie na morzu (#141): napad na handlarza albo walka z piratem
    if (r.expedition.settle.encounter) {
        const repairs = damaged ? ' Jednostki zatopione w boju trzeba wyremontować w stoczni.' : ' Cała flota przetrwała bitwę.';
        if (r.expedition.settle.encounter === 'merchant') {
            return won.value
                ? `Eskorta handlarza poszła na dno — jego towary są Twoje. Kupcy zapamiętają ten napad.${repairs}`
                : 'Eskorta obroniła handlarza. Jednostki zatopione w tej bitwie przepadły — XP zostaje na zawsze.';
        }
        // łowcy nagród (#142): wygrana nie zdejmuje embarga
        if (r.expedition.settle.encounter === 'bounty-hunters') {
            return won.value
                ? `Łowcy nagród poszli na dno — ich złoto i stal są Twoje. Embargo trwa: zdejmie je tylko lepsza reputacja.${repairs}`
                : 'Łowcy nagród wygrali bitwę. Jednostki zatopione w tej bitwie przepadły — XP zostaje na zawsze, embargo trwa.';
        }
        return won.value
            ? `Piraci rozbici — ich złoto i stal są Twoje, a kupcy to docenią (reputacja +5).${repairs}`
            : 'Piraci wygrali bitwę. Jednostki zatopione w tej bitwie przepadły — XP zostaje na zawsze.';
    }
    if (r.expedition.settle.kind === 'siege') {
        return won.value
            ? `Działa fortu zamilkły — ${r.expedition.islandName} uznaje Twoją banderę.`
                + (damaged ? ' Jednostki zatopione w boju wracają do portu na remont.' : ' Cała flota wraca bez szwanku.')
            : `Fort się obronił. Jednostki zatopione w tej bitwie przepadły, ale ${r.expedition.islandName} pozostaje oblężona — szturm możesz ponowić bez walki z flotą.`;
    }
    if (r.expedition.settle.besieged) {
        return `Flota strażnicza rozbita — ${r.expedition.islandName} jest oblężona. Banderę i pełny łup da dopiero zdobycie fortu.`
            + (damaged ? ' Jednostki zatopione w boju wracają do portu na remont.' : ' Cała flota wraca bez szwanku.');
    }
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

const repeatLabel = computed(() => {
    const s = exp.value?.settle;
    if (s?.besieged) return 'Rozpocznij oblężenie';
    if (s?.kind === 'siege') return 'Ponów oblężenie';
    return 'Powtórz bitwę';
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

// zatopione wrogie zgrupowane po typie, od największego kadłuba: „4× Kuter · 9× Tratwa”
function groupByLength(lengths: number[]): { key: number; label: string; count: number }[] {
    const counts = new Map<number, number>();
    lengths.forEach(l => counts.set(l, (counts.get(l) ?? 0) + 1));
    return [...counts.entries()].sort((a, b) => b[0] - a[0]).map(([l, count]) => ({ key: l, label: shipName(l), count }));
}

const enemyGroups = computed(() => groupByLength(report.value?.enemySunk ?? []));

// abordaż (#118): ostatni statek wroga zdobyty, nie zatopiony
const prize = computed(() => exp.value?.settle.prize ?? null);
const boardedChip = computed(() => {
    if (!report.value?.boarded) return null;
    return `abordaż${prize.value ? ` · ${fleetShipLabel(prize.value).toLowerCase()}` : ''}`;
});

// skutki bitwy jako lista: ikona, jedno zdanie, opcjonalnie przejście do ekranu, gdzie jest decyzja
interface Effect { key: string; icon: Component; tone: 'ok' | 'info' | 'warn'; text: string; link?: string }

const effects = computed<Effect[]>(() => {
    const e = exp.value;
    if (!e) return [];
    const s = e.settle;
    const out: Effect[] = [];
    if (s.defense?.held) {
        out.push({ key: 'defense', icon: ShieldCheck, tone: 'ok', text: `Wyspa obroniona: ${e.islandName} — bandera zostaje, następny kontratak najwcześniej po 8 tikach spokoju.` });
    }
    if (s.defense && !s.defense.held) {
        out.push({ key: 'defense', icon: FlagOff, tone: 'warn', text: `Wyspa utracona: ${e.islandName} — bandera zdjęta, z magazynu przepadło ${formatResources(s.defense.stockpileLost, 'nic — magazyn był pusty')}. Wyspę odbijesz zwykłą bitwą.` });
    }
    if (s.captured) out.push({ key: 'captured', icon: Flag, tone: 'ok', text: `${e.islandName} pod Twoją banderą.` });
    if (prize.value) {
        out.push({ key: 'prize', icon: Anchor, tone: 'ok', text: `Zdobyty statek: ${fleetShipLabel(prize.value)} — wyremontuj i dołącz albo rozbierz.`, link: 'Rejs → decyzja o zdobyczy' });
    }
    if (s.besieged) out.push({ key: 'besieged', icon: Castle, tone: 'info', text: `${e.islandName} oblężona — potyczka zapamiętana. Rozpocznij oblężenie fortu, gdy flota będzie gotowa.` });
    if (s.kind === 'siege' && !won.value) out.push({ key: 'siege', icon: Castle, tone: 'warn', text: 'Oblężenie trwa — szturm na fort możesz ponowić bez walki z flotą strażniczą.' });
    if (s.gateOpened) out.push({ key: 'gate', icon: Waves, tone: 'info', text: 'Cieśnina otwarta na stałe, w obie strony — przepłyniesz ją z sektora bramy za 1 tik.' });
    if (s.depositStarted) {
        out.push({ key: 'deposit', icon: Pickaxe, tone: 'info', text: `Złoże ruszyło: ${formatResources(s.depositStarted)}/tik — urobek zbiera się w magazynie wyspy ${e.islandName}.` });
    }
    return out;
});

// gdzie trafił łup: magazyn wyspy bitwy albo ładownia (strażnik, brak magazynu)
const lootNote = computed(() => {
    const e = exp.value;
    if (!e) return '';
    if (isEmpty(e.settle.loot)) return 'Bez łupu z tej bitwy.';
    if (e.settle.stockpile) return `Łup trafił do magazynu wyspy ${e.islandName} — załadujesz go w porcie (1 tik).`;
    if (e.settle.encounter) return 'Łup trafił do ładowni — na pełnym morzu nie ma magazynu, więc to, co się nie mieści, idzie za burtę.';
    return e.kind === 'guardian'
        ? 'Łup trafił do ładowni — przy cieśninie nie ma magazynu, więc to, co się nie mieści, idzie za burtę.'
        : 'Łup trafił do ładowni.';
});

// stan floty po typie (jak zakładka Flota, #111): utracone i uszkodzone na górze, sprawne w jednej linii
const ORDER: ShipTypeName[] = ['lotniskowiec', 'niszczyciel', 'transportowiec', 'kuter', 'tratwa'];

interface FleetGroup { key: string; name: string; lost: number; damaged: number; ok: number; repair: ResourcesDTO | null; note?: string }

const fleetGroups = computed<FleetGroup[]>(() => {
    const r = report.value;
    if (!r) return [];
    const e = r.expedition;
    if (!e) {
        return groupByLength(r.playerSunk).map(g => ({ key: `t${g.key}`, name: g.label, lost: 0, damaged: g.count, ok: 0, repair: null, note: 'zatopione w treningu — bez strat' }));
    }
    const byType = new Map<ShipTypeName, FleetGroup>();
    const group = (t: ShipTypeName) => {
        let g = byType.get(t);
        if (!g) {
            g = { key: t, name: SHIP_LABELS[t], lost: 0, damaged: 0, ok: 0, repair: { wood: 0, steel: 0, gold: 0 } };
            byType.set(t, g);
        }
        return g;
    };
    for (const s of e.fleet) {
        const g = group(s.type);
        if (s.damaged) {
            g.damaged++;
            g.repair = addResources(g.repair!, s.repairCost);
        } else {
            g.ok++;
        }
    }
    e.settle.lostShips.forEach(t => group(t).lost++);
    return [...byType.values()].sort((a, b) => ORDER.indexOf(a.key as ShipTypeName) - ORDER.indexOf(b.key as ShipTypeName));
});

const hurtGroups = computed(() => fleetGroups.value.filter(g => g.lost || g.damaged));
const okGroups = computed(() => fleetGroups.value.filter(g => g.ok));
const okCount = computed(() => okGroups.value.reduce((n, g) => n + g.ok, 0));
const repairTotal = computed(() => hurtGroups.value.reduce((sum, g) => (g.repair ? addResources(sum, g.repair) : sum), { wood: 0, steel: 0, gold: 0 }));
const damagedCount = computed(() => exp.value?.fleet.filter(s => s.damaged).length ?? 0);

const hasDamaged = computed(() => damagedCount.value > 0);

const shipyardLabel = computed(() => {
    const e = exp.value;
    if (!e) return '';
    if (e.fleet.length === 0) return 'Odbuduj flotę (tratwa za darmo na każdej wyspie)';
    if (hasDamaged.value) return 'Napraw flotę w stoczni';
    return e.settle.lostShips.length ? 'Uzupełnij flotę w stoczni' : 'Flota sprawna — stocznia';
});

const canRepeat = computed(() => {
    const e = exp.value;
    // wyspa pod banderą nie jest areną (#78): po zdobyciu i po obronie nie ma czego powtarzać; strażnik walczy do pierwszej przegranej
    // spotkanie (#141) jest jednorazowe — po bitwie handlarz i piraci odpływają
    return !e || (!e.settle.captured && !e.settle.defense && !e.settle.gateOpened && !e.settle.encounter);
});

/** „1 jednostka”, „3 jednostki”, „27 jednostek”. */
function unitsLabel(n: number): string {
    if (n === 1) return '1 jednostka';
    const last = n % 10;
    const lastTwo = n % 100;
    return last >= 2 && last <= 4 && (lastTwo < 12 || lastTwo > 14) ? `${n} jednostki` : `${n} jednostek`;
}

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
            fort: g.fort ? { destroyed: g.fort.guns.total - g.fort.guns.left, total: g.fort.guns.total } : null,
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
            const { profileId, islandId, islandName, kind } = r.expedition;
            const game = kind === 'guardian'
                ? await startGuardianBattle(profileId, islandId)
                // po wygranej potyczce kolejnym krokiem jest oblężenie fortu (#120)
                : kind === 'siege' || r.expedition.settle.besieged
                    ? await startSiege(profileId, islandId)
                    : await startIslandBattle(profileId, islandId);
            const next = kind === 'guardian' ? kind : kind === 'siege' || r.expedition.settle.besieged ? 'siege' : undefined;
            setCurrentBattle({ profileId, gameId: game.id, islandId, islandName, kind: next });
            await router.push({ name: 'place-fleet', params: { id: game.id } });
        } else {
            const game = await createGame(r.ruleset, r.board.w, !!r.fort);
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
            <header class="head">
                <div class="head-main">
                    <div class="kicker outcome-kicker">{{ kicker }}</div>
                    <h1>{{ won ? 'ZWYCIĘSTWO' : 'PORAŻKA' }}</h1>
                    <p class="lead">{{ description }}</p>
                </div>

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
            </header>

            <div class="actions">
                <router-link class="btn btn-primary" :to="{ name: 'home' }"><Anchor :size="17" /> Do portu</router-link>
                <router-link
                    v-if="exp"
                    class="btn btn-secondary"
                    :class="{ off: !hasDamaged && !exp.settle.lostShips.length }"
                    :to="{ name: 'expedition', hash: '#stocznia' }"
                >
                    <Wrench :size="16" /> {{ shipyardLabel }}
                </router-link>
                <button v-if="canRepeat" class="btn btn-secondary" :disabled="busy" @click="repeat">
                    <component :is="exp?.settle.besieged || exp?.settle.kind === 'siege' ? Castle : RotateCcw" :size="16" />
                    {{ busy ? 'Stawianie żagli…' : repeatLabel }}
                </button>
                <p v-if="error" class="err">{{ error }}</p>
            </div>

            <div class="body">
                <div class="col">
                    <div v-if="report.fort" class="block">
                        <div class="kicker">DZIAŁA FORTU · {{ report.fort.destroyed }} / {{ report.fort.total }}</div>
                        <div class="chips">
                            <span class="chip">{{ report.fort.destroyed === report.fort.total ? 'fort zamilkł' : 'fort się obronił' }}</span>
                        </div>
                    </div>
                    <div v-else class="block">
                        <div class="kicker">ZATOPIONE · {{ report.enemySunk.length }} / {{ report.enemyTotal }}</div>
                        <div class="chips">
                            <span v-for="g in enemyGroups" :key="g.key" class="chip"><b>{{ g.count }}×</b> {{ g.label }}</span>
                            <span v-if="boardedChip" class="chip boarded"><Anchor :size="14" /> {{ boardedChip }}</span>
                            <span v-if="!enemyGroups.length && !boardedChip" class="chip empty">żadna jednostka wroga</span>
                        </div>
                    </div>

                    <div v-if="effects.length" class="block">
                        <div class="kicker">SKUTKI</div>
                        <ul class="effects">
                            <li v-for="fx in effects" :key="fx.key" :class="fx.tone">
                                <component :is="fx.icon" :size="18" class="fx-icon" />
                                <span>
                                    {{ fx.text }}
                                    <router-link v-if="fx.link" class="fx-link" :to="{ name: 'expedition' }">
                                        {{ fx.link }} <ArrowRight :size="13" />
                                    </router-link>
                                </span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="col side">
                    <div class="block">
                        <div class="kicker">ŁUPY</div>
                        <template v-if="exp">
                            <div class="loot">
                                <div class="loot-tile">
                                    <span class="loot-label"><span class="res-dot" style="background: var(--info)"></span> DOŚWIADCZENIE</span>
                                    <b>+{{ exp.settle.awarded }}</b>
                                </div>
                                <div v-for="r in RESOURCES" :key="r.key" class="loot-tile" :class="{ none: exp.settle.loot[r.key] === 0 }">
                                    <span class="loot-label"><span class="res-dot" :style="{ background: r.color }"></span> {{ r.label.toUpperCase() }}</span>
                                    <b>+{{ exp.settle.loot[r.key] }}</b>
                                    <small v-if="exp.settle.stockpile">w magazynie: {{ exp.settle.stockpile[r.key] }}</small>
                                    <small v-else>w ładowni: {{ exp.settle.cargo[r.key] }}</small>
                                    <small v-if="exp.settle.lootBonus?.[r.key]" class="perk">w tym +{{ exp.settle.lootBonus[r.key] }} z perku</small>
                                </div>
                            </div>
                            <p class="loot-note">{{ lootNote }}</p>
                            <p v-if="!isEmpty(exp.settle.cargoLost)" class="cargo-lost">
                                <PackageX :size="15" />
                                Za burtą: {{ formatResources(exp.settle.cargoLost) }} — {{ exp.kind === 'guardian' || exp.settle.encounter ? 'łup i ładunek ponad pojemność floty' : 'ładunek ponad pojemność floty po stratach' }}.
                            </p>
                        </template>
                        <p v-else class="muted loot-note">Trening nie daje łupów ani XP. Bitwy o wyspy rozegrasz z portu, gdy staniesz przy wyspie.</p>
                    </div>

                    <div class="block">
                        <div class="fleet-head">
                            <span class="kicker">STAN FLOTY</span>
                            <span v-if="exp && hasDamaged" class="fleet-total">do remontu: {{ damagedCount }} · {{ formatResources(repairTotal) }}</span>
                        </div>
                        <ul class="fleet">
                            <li v-for="g in hurtGroups" :key="g.key" :class="g.lost && !g.damaged ? 'lost' : 'damaged'">
                                <span class="fleet-name">{{ g.name }}</span>
                                <span class="fleet-state">
                                    <span v-if="g.lost" class="st lost">{{ g.lost }}× utracone w bitwie</span>
                                    <span v-if="g.damaged" class="st damaged">{{ g.damaged }}× {{ g.note ?? 'do remontu' }}<template v-if="g.repair"> · {{ formatResources(g.repair) }}</template></span>
                                </span>
                            </li>
                            <li v-if="exp && okCount" class="ok">
                                <span class="fleet-name">Sprawne · {{ unitsLabel(okCount) }}</span>
                                <span class="fleet-state ok-list">{{ okGroups.map(g => `${g.ok}× ${g.name}`).join(' · ') }}</span>
                            </li>
                            <li v-if="!hurtGroups.length && !okCount" class="ok">
                                <span class="fleet-name">Bez strat</span>
                                <span class="fleet-state">cała flota sprawna</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </template>
    </section>
</template>

<style scoped>
.frame {
    position: relative;
    border-radius: 18px;
    border: 1px solid var(--line-card);
    background:
        radial-gradient(circle at 30% 0%, #e8843c22, transparent 45%),
        linear-gradient(160deg, var(--bg-frame-a), var(--bg-frame-b));
    overflow: hidden;
}
.frame.lost { background: radial-gradient(circle at 30% 0%, #c8624a1a, transparent 45%), linear-gradient(160deg, var(--bg-frame-a), var(--bg-frame-b)); }
.pad { padding: 60px; }

.head { display: grid; grid-template-columns: minmax(0, 1fr) 400px; gap: 20px 48px; align-items: end; padding: 30px 44px 20px; }
.head-main { display: flex; flex-direction: column; gap: 10px; }
.outcome-kicker { color: var(--accent); font-size: 12px; letter-spacing: .26em; }
.lost .outcome-kicker { color: var(--danger); }
h1 { font: 700 60px var(--font-head); line-height: .95; letter-spacing: -.02em; margin: 0; }
.lead { font: 400 16px/1.5 var(--font-body); color: var(--text-desc); max-width: 620px; margin: 0; }

.rank-head { display: flex; justify-content: space-between; align-items: baseline; }
.rank-head .up { color: var(--info); }
.gain { font: 700 22px var(--font-head); color: var(--info); }
.track { height: 12px; border-radius: 7px; background: var(--bg-card); border: 1px solid var(--line-card); margin-top: 10px; overflow: hidden; display: flex; }
.old { height: 100%; background: #2a5560; }
.new { height: 100%; background: linear-gradient(90deg, var(--info), var(--info-light)); transition: width .8s ease-out; }
.rank-foot { margin-top: 8px; font: 500 12px var(--font-mono); color: var(--text-muted); }

.stats { display: flex; gap: 12px; }
.stats div { flex: 1; padding: 12px 14px; border-radius: 12px; background: var(--bg-card); border: 1px solid var(--line-card); }
.stats b { display: block; font: 700 24px var(--font-head); }
.stats span { font: 500 10px var(--font-mono); color: var(--text-muted); }

.actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 14px 44px; border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); background: var(--bg-panel-a); }
.actions .btn { padding: 12px 22px; border-radius: 12px; }
.actions .btn.off { color: var(--text-muted); }
.actions .err { margin: 0; }

.body { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
.col { padding: 22px 44px 26px; display: flex; flex-direction: column; gap: 20px; }
.col.side { border-left: 1px solid var(--line); background: var(--bg-panel-a); }
.block { display: flex; flex-direction: column; gap: 10px; }

.chips { display: flex; flex-wrap: wrap; gap: 8px; }
.chip { display: inline-flex; align-items: center; gap: 6px; padding: 8px 13px; border-radius: 9px; background: var(--bg-card); border: 1px solid var(--line-card); font: 600 13px var(--font-head); color: var(--text-2); }
.chip b { color: var(--text); }
.chip.boarded { border-color: var(--accent); color: var(--accent); }
.chip.empty { color: var(--text-dim); font-weight: 500; }

.effects { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.effects li { display: flex; gap: 12px; align-items: flex-start; padding: 10px 14px; border-radius: 10px; background: var(--bg-card); border: 1px solid var(--line-card); font: 400 14px/1.45 var(--font-body); color: var(--text-2); }
.fx-icon { flex: none; margin-top: 1px; }
.effects li.ok .fx-icon { color: var(--info); }
.effects li.info .fx-icon { color: var(--info-light); }
.effects li.warn { border-color: #5a4a28; }
.effects li.warn .fx-icon { color: var(--warn); }
.fx-link { display: inline-flex; align-items: center; gap: 4px; margin-left: 6px; font-weight: 600; white-space: nowrap; }

.loot { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
.loot-tile { padding: 12px; border-radius: 12px; background: var(--bg-card); border: 1px solid var(--line-card); display: flex; flex-direction: column; gap: 4px; }
.loot-label { display: inline-flex; align-items: center; gap: 6px; font: 500 10px var(--font-mono); color: var(--text-muted); white-space: nowrap; }
.loot-label .res-dot { width: 10px; height: 10px; }
.loot-tile b { font: 700 22px var(--font-head); color: var(--info); }
.loot-tile small { font: 500 10px var(--font-mono); color: var(--text-dim); }
.loot-tile.none b { color: var(--text-dim); }
.loot-tile small.perk { color: var(--accent); }
.loot-note { margin: 0; font-size: 13px; color: var(--text-muted); }
.cargo-lost { margin: 0; display: flex; gap: 8px; align-items: flex-start; font: 500 13px var(--font-body); color: var(--danger); }
.cargo-lost svg { flex: none; margin-top: 2px; }

.fleet-head { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; }
.fleet-total { font: 500 11px var(--font-mono); color: var(--accent-light); }
.fleet { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.fleet li { display: flex; justify-content: space-between; align-items: baseline; gap: 6px 14px; flex-wrap: wrap; padding: 9px 14px; border-radius: 11px; background: var(--bg-card); border: 1px solid var(--line-card); border-left-width: 3px; }
.fleet li.damaged { border-left-color: var(--accent); }
.fleet li.lost { border-left-color: var(--danger); }
.fleet li.ok { border-left-color: var(--info); }
.fleet-name { font: 600 14px var(--font-head); }
.fleet-state { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 4px 12px; font: 500 11px var(--font-mono); color: var(--text-muted); }
.st.damaged { color: var(--accent-light); }
.st.lost { color: var(--danger); }
.ok-list { color: var(--text-2); }

@media (max-width: 1100px) {
    .head { grid-template-columns: minmax(0, 1fr) 260px; gap: 16px 28px; padding: 28px 28px 18px; }
    .actions, .col { padding-left: 28px; padding-right: 28px; }
    h1 { font-size: 52px; }
    .stats { flex-direction: column; gap: 6px; }
    .stats div { display: flex; justify-content: space-between; align-items: baseline; padding: 6px 12px; }
    .stats b { font-size: 18px; }
    /* kafelki łupów 2×2, wartość obok etykiety, żeby nie rosła wysokość */
    .loot { grid-template-columns: 1fr 1fr; }
    .loot-tile { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: baseline; gap: 2px 8px; padding: 10px 12px; }
    .loot-tile b { font-size: 20px; }
    .loot-tile small { grid-column: 1 / -1; }
    .col { padding-top: 20px; padding-bottom: 24px; gap: 18px; }
}
@media (max-width: 760px) {
    .head { grid-template-columns: 1fr; }
    .body { grid-template-columns: 1fr; }
    .col.side { border-left: none; border-top: 1px solid var(--line); }
}
/* wąski ekran: nagłówek „ZWYCIĘSTWO” nie może rozpychać kolumny ponad ramkę */
@media (max-width: 560px) {
    .head { grid-template-columns: minmax(0, 1fr); padding: 24px 16px 16px; }
    .actions, .col { padding-left: 16px; padding-right: 16px; }
    h1 { font-size: 40px; }
    .rank-head { gap: 12px; }
    .gain { white-space: nowrap; }
}
</style>
