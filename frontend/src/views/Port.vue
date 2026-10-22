<template>
    <section class="frame port">
        <div class="chart-grid" aria-hidden="true"></div>
        <div class="radar" aria-hidden="true"><i></i><i></i><b></b></div>

        <div class="layout">
            <!-- lewa: tytuł + akcje -->
            <div class="hero">
                <div class="kicker hero-kicker">AKWEN I · MORZE ROZBITKÓW</div>
                <h1>STATKI<span>.</span></h1>
                <p class="lead">
                    Silnik gry w statki rozrośnięty w podróż: bitwy na morzu, wyspy, stocznie
                    i flota, którą trzeba utrzymać przy życiu.
                </p>

                <div class="actions">
                    <button class="btn btn-primary main-cta" :disabled="starting || loading" @click="onPrimary">
                        <span class="cta-text">
                            {{ primary.label }}
                            <small>{{ primary.hint }}</small>
                        </span>
                        <span class="key">ENTER</span>
                    </button>
                    <button v-if="primary.kind !== 'training'" class="link-training" :disabled="starting" @click="startTraining">
                        albo bitwa treningowa — bez stawki →
                    </button>
                    <div class="secondary">
                        <router-link class="btn btn-secondary" :to="{ name: 'expedition' }">
                            <Compass :size="16" /> Kontynuuj rejs
                        </router-link>
                        <router-link class="btn btn-secondary" :to="{ name: 'expedition', hash: '#stocznia' }">
                            <Wrench :size="16" /> Stocznia
                        </router-link>
                        <button class="btn btn-secondary" disabled title="Jeden akwen — graf akwenów jest w planach (GAME_DESIGN, epik I)">
                            <MapIcon :size="16" /> Mapa akwenów <em>wkrótce</em>
                        </button>
                        <button class="btn btn-secondary" disabled title="Ekran ustawień poza zakresem projektu">
                            <Settings :size="16" /> Ustawienia <em>wkrótce</em>
                        </button>
                    </div>
                    <p v-if="error" class="err">{{ error }}</p>
                </div>
            </div>

            <!-- prawa: kapitan, flota -->
            <aside class="captain">
                <p v-if="loading" class="muted">Wczytywanie profilu…</p>

                <template v-else-if="expedition">
                    <div class="who">
                        <div class="avatar">{{ initial }}</div>
                        <div>
                            <div class="name">{{ expedition.profile.name }}</div>
                            <div class="rank">RANGA · {{ rankLabel(expedition.profile.rank).toUpperCase() }}</div>
                        </div>
                    </div>
                    <div class="xp">
                        <div class="xp-head">
                            <span v-if="expedition.profile.nextRank">XP do {{ RANK_LABELS_GENITIVE[expedition.profile.nextRank.rank].toUpperCase() }}</span>
                            <span v-else>NAJWYŻSZA RANGA</span>
                            <span>{{ fmt(expedition.profile.xp) }}<template v-if="xpTarget"> / {{ fmt(xpTarget) }}</template></span>
                        </div>
                        <div class="track"><div class="fill" :style="{ width: xpPercent + '%' }"></div></div>
                    </div>

                    <div class="stats">
                        <div class="stat"><b>{{ totals.wins }}</b><span>BITWY WYGRANE</span></div>
                        <div class="stat" :title="`bitwy przegrane: ${totals.losses}`"><b>{{ expedition.profile.enemyShipsSunk }}</b><span>ZATOPIONE</span></div>
                    </div>

                    <div class="hold-head">
                        <span class="kicker">ŁADOWNIA</span>
                        <span class="hold-cap">{{ cargoUsed }} / {{ expedition.profile.cargoCapacity }}</span>
                    </div>
                    <div class="resources">
                        <div v-for="r in RESOURCES" :key="r.key" class="res">
                            <span class="res-dot" :style="{ background: r.color }"></span>
                            <div><b>{{ expedition.profile.cargo[r.key] }}</b><span>{{ r.label.toUpperCase() }}</span></div>
                        </div>
                    </div>
                    <div v-if="stores.length" class="stores">
                        <div class="hold-head">
                            <span class="kicker">MAGAZYNY I ZŁOŻA</span>
                            <span v-if="deposits.total" class="hold-cap">złoża {{ deposits.working }} / {{ deposits.total }} pracuje</span>
                        </div>
                        <ul>
                            <li v-for="island in stores" :key="island.id" :class="{ here: island.present }">
                                <span class="st-name">{{ island.present ? '📍 ' : '' }}{{ island.name }}</span>
                                <span class="st-stock">{{ formatResources(island.stockpile, 'pusto') }}</span>
                                <span v-if="island.deposit" class="st-dep" :class="`dep-${depositState(island)}`" :title="depositLabel(island)">
                                    ⛏ {{ depositShort(island) }}
                                </span>
                            </li>
                        </ul>
                    </div>
                    <div class="charted">wyspy {{ discoveredIslands }} / {{ expedition.islands.length }} · zbadano {{ chartedPercent }}% mapy</div>
                    <div class="flags">🚩 Wyspy pod banderą: <b>{{ expedition.profile.ownedIslands }}/{{ expedition.islands.length }}</b></div>
                    <!-- kontratak (#78): zapowiedź z terminem i ostatnie rozstrzygnięcie -->
                    <div v-if="expedition.counterattack.pending" class="counterattack" :class="{ defending: expedition.counterattack.pending.defenseStarted }">
                        {{ expedition.counterattack.pending.defenseStarted ? '🛡️' : '⚔️' }} {{ pendingLabel(expedition.counterattack.pending) }}
                        <div v-if="expedition.counterattack.pending.fullFlag" class="full-flag">🚩 {{ FULL_FLAG_BONUS }}</div>
                    </div>
                    <div v-else-if="expedition.counterattack.last" class="flags" :class="{ lost: !expedition.counterattack.last.held }">
                        {{ expedition.counterattack.last.held ? '🛡️' : '🏴' }} {{ lastLabel(expedition.counterattack.last) }}
                    </div>

                    <div class="where">
                        <Anchor :size="14" />
                        <span v-if="currentIsland">W porcie: <b>{{ currentIsland.name }}</b></span>
                        <span v-else>Na pełnym morzu · sektor {{ expedition.world.position.x }}:{{ expedition.world.position.y }}</span>
                        <span class="tick" title="Tik — każdy rejs i każda bitwa to jedna akcja morska">TIK {{ expedition.world.tick }}</span>
                    </div>

                    <div class="fleet">
                        <div class="kicker">FLOTA · {{ expedition.fleet.length }} {{ unitsWord(expedition.fleet.length) }}</div>
                        <ul>
                            <li v-for="ship in sortedFleet" :key="ship.id">
                                <component :is="shipIcon(ship.length)" :size="15" class="ship-ico" />
                                <div class="ship-txt">
                                    <div class="ship-name">{{ SHIP_LABELS[ship.type] }}</div>
                                    <div class="ship-role">{{ shipRole(ship.length) }}</div>
                                </div>
                                <div class="cond" :title="ship.damaged ? 'uszkodzony — do remontu' : 'sprawny'">
                                    <div :class="{ dmg: ship.damaged }"></div>
                                </div>
                            </li>
                        </ul>
                        <p v-if="!expedition.fleet.length" class="muted">Brak jednostek — tratwę zbudujesz za darmo na każdej wyspie.</p>
                    </div>
                </template>

                <!-- pierwsza wizyta: profil kapitana -->
                <div v-else class="onboarding">
                    <div class="kicker">NOWY KAPITAN</div>
                    <h2>Ocalałeś z katastrofy…</h2>
                    <p class="muted">
                        Twój okręt zatonął, ale Ty żyjesz. Z desek wraku skleciłeś tratwę.
                        Czas odbudować flotę — od rozbitka do admirała.
                    </p>
                    <form @submit.prevent="onCreateProfile">
                        <label for="captain-name" class="kicker">IMIĘ KAPITANA</label>
                        <input id="captain-name" v-model.trim="newName" maxlength="40" placeholder="np. Vane" autocomplete="off" />
                        <button class="btn btn-secondary" :disabled="creating || newName.length === 0">
                            {{ creating ? 'Tworzenie…' : 'Wypłyń na wyprawę' }}
                        </button>
                    </form>
                    <p class="muted small">Bez profilu możesz grać bitwy treningowe — bez XP i łupów.</p>
                </div>
            </aside>
        </div>
    </section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, type Component } from 'vue';
import { useRouter } from 'vue-router';
import { Anchor, Compass, Container, Map as MapIcon, Plane, Radar, Settings, Ship, Sailboat, Wrench, Crosshair } from 'lucide-vue-next';
import {
    createProfile, getExpedition, settleBattle, startIslandBattle,
    RANK_LABELS, RANK_LABELS_GENITIVE, SHIP_LABELS,
    type ExpeditionDTO, type IslandDTO, type RankName,
} from '../api/expeditionApi';
import { createGame } from '../api/gameApi';
import { ApiError } from '../api/http';
import {
    clearCurrentBattle, clearProfileId, getCurrentBattle, getProfileId,
    setCurrentBattle, setProfileId, type CurrentBattle,
} from '../lib/expeditionSession';
import { getTrainingPrefs } from '../lib/battleSession';
import { carriedWeapon, shipTypeForLength } from '../lib/ships';
import { RESOURCES, formatResources, isEmpty, totalResources } from '../lib/resources';
import { depositLabel, depositState, economyIslands } from '../lib/economy';
import { canDefend, FULL_FLAG_BONUS, lastLabel, pendingLabel, ticksLabel } from '../lib/counterattack';

const router = useRouter();

const loading = ref(true);
const starting = ref(false);
const creating = ref(false);
const error = ref('');
const newName = ref('');
const expedition = ref<ExpeditionDTO | null>(null);
const pendingBattle = ref<CurrentBattle | null>(null);

function rankLabel(rank: RankName): string {
    return RANK_LABELS[rank] ?? rank;
}

const fmt = (n: number) => n.toLocaleString('pl-PL');

const initial = computed(() => (expedition.value?.profile.name.trim()[0] ?? '?').toUpperCase() + '.');

const xpTarget = computed(() => {
    const p = expedition.value?.profile;
    return p?.nextRank ? p.xp + p.nextRank.xpNeeded : null;
});

const xpPercent = computed(() => {
    const p = expedition.value?.profile;
    if (!p || !xpTarget.value) return 100;
    return Math.min(100, Math.round((p.xp / xpTarget.value) * 100));
});

const totals = computed(() => {
    const islands = expedition.value?.islands ?? [];
    return {
        wins: islands.reduce((s, i) => s + i.wins, 0),
        losses: islands.reduce((s, i) => s + i.losses, 0),
    };
});

const discoveredIslands = computed(() => expedition.value?.islands.filter(i => i.discovered).length ?? 0);

const chartedPercent = computed(() => {
    const w = expedition.value?.world;
    if (!w) return 0;
    return Math.round((w.discovered.length / (w.width * w.height)) * 100);
});

const cargoUsed = computed(() => (expedition.value ? totalResources(expedition.value.profile.cargo) : 0));

const currentIsland = computed<IslandDTO | null>(() => expedition.value?.islands.find(i => i.present) ?? null);

// magazyny i złoża (#49): wyspy z zapasami albo złożem — stocznie bez zapasów pomija, port ma mało miejsca
const stores = computed(() => economyIslands(expedition.value?.islands ?? []).filter(i => !isEmpty(i.stockpile) || i.deposit));

const deposits = computed(() => {
    const withDeposit = stores.value.filter(i => i.deposit);
    return { total: withDeposit.length, working: withDeposit.filter(i => depositState(i) === 'working').length };
});

function depositShort(island: IslandDTO): string {
    switch (depositState(island)) {
        case 'working': return 'pracuje';
        case 'full': return 'pełny — odbierz';
        default: return 'czeka na banderę';
    }
}

const sortedFleet = computed(() => [...(expedition.value?.fleet ?? [])].sort((a, b) => b.length - a.length));

function unitsWord(n: number): string {
    if (n === 1) return 'JEDNOSTKA';
    const last = n % 10;
    const lastTwo = n % 100;
    return last >= 2 && last <= 4 && (lastTwo < 12 || lastTwo > 14) ? 'JEDNOSTKI' : 'JEDNOSTEK';
}

function shipIcon(length: number): Component {
    switch (length) {
        case 5: return Container;
        case 4: return Plane;
        case 3: return Crosshair;
        case 2: return Radar;
        case 1: return Sailboat;
        default: return Ship;
    }
}

function shipRole(length: number): string {
    const weapon = carriedWeapon(length);
    if (weapon) return `nośnik · ${weapon}`;
    return shipTypeForLength(length) === 'transportowiec' ? 'ładownia · bez broni' : `${length}-masztowiec`;
}

// --- główna akcja: bitwa o wyspę, powrót do bitwy albo trening ---

type Primary = { kind: 'resume' | 'island' | 'training'; label: string; hint: string };

const primary = computed<Primary>(() => {
    if (pendingBattle.value) {
        return { kind: 'resume', label: 'Wróć do bitwy', hint: `trwa bitwa · ${pendingBattle.value.islandName}` };
    }
    const island = currentIsland.value;
    const pending = expedition.value?.counterattack.pending ?? null;
    // bez floty nie ma czym walczyć o wyspę — tratwę buduje się w stoczni
    if (island && island.owned && canDefend(island, pending) && (expedition.value?.fleet.length ?? 0) > 0) {
        return { kind: 'island', label: 'Broń wyspy', hint: `kontratak · ${island.name} · uderzenie za ${ticksLabel(pending!.ticksLeft)}` };
    }
    // wyspa pod banderą nie jest areną (#78)
    if (island && island.unlocked && !island.owned && (expedition.value?.fleet.length ?? 0) > 0) {
        return {
            kind: 'island',
            label: 'Nowa bitwa',
            hint: `${island.name} · +${island.xpWin} XP, ${formatResources(island.lootWin, 'bez łupów')} za wygraną`,
        };
    }
    const prefs = getTrainingPrefs();
    return {
        kind: 'training',
        label: 'Nowa bitwa',
        hint: `trening ${prefs.size}×${prefs.size} · ${prefs.ruleset === 'fun' ? 'bronie specjalne' : 'klasyczna'} · bez XP`,
    };
});

async function onPrimary() {
    if (primary.value.kind === 'resume' && pendingBattle.value) {
        await router.push({ name: 'game', params: { id: pendingBattle.value.gameId } });
        return;
    }
    if (primary.value.kind === 'island') {
        await startIsland();
        return;
    }
    await startTraining();
}

async function startIsland() {
    const profileId = getProfileId();
    const island = currentIsland.value;
    if (!profileId || !island) return;
    starting.value = true;
    error.value = '';
    try {
        const game = await startIslandBattle(profileId, island.id);
        setCurrentBattle({ profileId, gameId: game.id, islandId: island.id, islandName: island.name });
        await router.push({ name: 'place-fleet', params: { id: game.id } });
    } catch (e: any) {
        error.value = e?.message ?? 'Nie udało się rozpocząć bitwy';
    } finally {
        starting.value = false;
    }
}

async function startTraining() {
    starting.value = true;
    error.value = '';
    try {
        const prefs = getTrainingPrefs();
        const game = await createGame(prefs.ruleset, prefs.size);
        await router.push({ name: 'place-fleet', params: { id: game.id } });
    } catch (e: any) {
        error.value = e?.message ?? 'Nie udało się utworzyć gry';
    } finally {
        starting.value = false;
    }
}

function onKey(e: KeyboardEvent) {
    if (e.key !== 'Enter' || e.defaultPrevented) return;
    const target = e.target as HTMLElement | null;
    if (target && ['INPUT', 'TEXTAREA', 'BUTTON', 'A'].includes(target.tagName)) return;
    e.preventDefault();
    if (!starting.value && !loading.value) onPrimary();
}

// --- profil ---

async function load() {
    loading.value = true;
    try {
        const profileId = getProfileId();
        if (!profileId) return;
        await settlePendingBattle(profileId);
        expedition.value = await getExpedition(profileId);
    } catch (e: any) {
        if (e instanceof ApiError && e.status === 404) {
            clearProfileId();
            clearCurrentBattle();
            return;
        }
        error.value = e?.message ?? 'Nie udało się wczytać profilu';
    } finally {
        loading.value = false;
    }
}

/** Siatka bezpieczeństwa jak na mapie wyprawy: rozlicz porzuconą, zakończoną bitwę. */
async function settlePendingBattle(profileId: string) {
    const battle = getCurrentBattle();
    pendingBattle.value = null;
    if (!battle || battle.profileId !== profileId) return;
    try {
        await settleBattle(battle.profileId, battle.gameId);
        clearCurrentBattle();
    } catch (e: any) {
        if (e instanceof ApiError && 409 === e.status) {
            pendingBattle.value = battle;
            return;
        }
        clearCurrentBattle();
    }
}

async function onCreateProfile() {
    creating.value = true;
    error.value = '';
    try {
        const profile = await createProfile(newName.value);
        setProfileId(profile.id);
        expedition.value = await getExpedition(profile.id);
    } catch (e: any) {
        error.value = e?.message ?? 'Nie udało się utworzyć profilu';
    } finally {
        creating.value = false;
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKey);
    load();
});
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<style scoped>
.frame {
    position: relative;
    overflow: hidden;
    min-height: 812px;
    border-radius: 18px;
    border: 1px solid var(--line-card);
    background: linear-gradient(160deg, var(--bg-frame-a), var(--bg-frame-b));
}
.chart-grid {
    position: absolute;
    inset: 0;
    background-image: linear-gradient(#0f2c36 1px, transparent 1px), linear-gradient(90deg, #0f2c36 1px, transparent 1px);
    background-size: 48px 48px;
    opacity: .5;
}
.radar {
    position: absolute;
    right: -120px;
    top: -120px;
    width: 520px;
    height: 520px;
    border-radius: 50%;
    border: 1px solid var(--line);
}
.radar i { position: absolute; border-radius: 50%; border: 1px solid var(--line); }
.radar i:nth-child(1) { inset: 70px; }
.radar i:nth-child(2) { inset: 150px; }
.radar b {
    position: absolute;
    inset: 0;
    border-radius: 50%;
    background: conic-gradient(from 0deg, #3fb6c433, transparent 70deg);
    animation: radar 9s linear infinite;
}
@keyframes radar { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .radar b { animation: none; } }

.layout {
    position: relative;
    min-height: 812px;
    display: grid;
    grid-template-columns: 1fr 380px;
}

.hero { padding: 66px 60px; display: flex; flex-direction: column; }
.hero-kicker { font-size: 12px; letter-spacing: .34em; }
h1 {
    font: 700 92px var(--font-head);
    line-height: .92;
    letter-spacing: -.02em;
    margin: 14px 0 0;
}
h1 span { color: var(--info); }
.lead { font: 400 18px/1.5 var(--font-body); color: var(--text-desc); max-width: 420px; margin: 18px 0 0; }

.actions { margin-top: auto; display: flex; flex-direction: column; gap: 14px; max-width: 440px; padding-top: 40px; }
.main-cta { justify-content: space-between; padding: 20px 24px; font-size: 18px; text-align: left; }
.cta-text { display: flex; flex-direction: column; gap: 3px; }
.cta-text small { font: 500 11px var(--font-mono); letter-spacing: .04em; opacity: .75; }
.key { font: 600 12px var(--font-mono); opacity: .7; }
.link-training {
    align-self: flex-start;
    background: none;
    border: none;
    padding: 0;
    color: var(--text-muted);
    font: 500 12px var(--font-mono);
    cursor: pointer;
}
.link-training:hover { color: var(--info); }
.secondary { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.secondary .btn { justify-content: flex-start; text-align: left; }
.secondary em { margin-left: auto; font: 600 9px var(--font-mono); font-style: normal; letter-spacing: .14em; text-transform: uppercase; }

.captain {
    border-left: 1px solid var(--line);
    background: var(--bg-panel-a);
    backdrop-filter: blur(2px);
    padding: 40px 34px;
    display: flex;
    flex-direction: column;
    gap: 22px;
}
.who { display: flex; align-items: center; gap: 14px; }
.avatar {
    width: 56px;
    height: 56px;
    border-radius: 12px;
    background: #12303b;
    border: 1px solid var(--line-strong);
    display: flex;
    align-items: center;
    justify-content: center;
    font: 700 22px var(--font-head);
    color: var(--info);
}
.name { font: 700 20px var(--font-head); }
.rank { font: 600 11px var(--font-mono); color: var(--accent); letter-spacing: .18em; }
.xp-head { display: flex; justify-content: space-between; font: 500 11px var(--font-mono); color: var(--text-muted); }
.track { height: 8px; border-radius: 5px; background: var(--bg-card); border: 1px solid var(--line-card); margin-top: 7px; overflow: hidden; }
.fill { height: 100%; background: linear-gradient(90deg, var(--info), var(--info-light)); transition: width .8s ease-out; }

.stats { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.stat { padding: 12px; border-radius: 10px; background: var(--bg-card); border: 1px solid var(--line-card); }
.stat b { display: block; font: 700 22px var(--font-head); }
.stat span { font: 500 10px var(--font-mono); color: var(--text-muted); }

.resources { display: flex; gap: 10px; margin-top: -12px; }
.hold-head { display: flex; justify-content: space-between; align-items: baseline; }
.stores { margin-top: 12px; }
.stores ul { list-style: none; padding: 0; margin: 6px 0 0; display: flex; flex-direction: column; gap: 4px; }
.stores li { display: flex; gap: 8px; align-items: baseline; font: 500 11px var(--font-mono); color: var(--text-2); }
.stores li.here .st-name { color: var(--text); font-weight: 700; }
.st-name { flex: 0 0 auto; }
.st-stock { flex: 1; color: var(--text-muted); text-align: right; }
.st-dep { flex: 0 0 auto; }
.dep-working { color: var(--info); }
.dep-full { color: var(--warn); }
.dep-idle { color: var(--text-dim); }
.hold-cap { font: 600 11px var(--font-mono); color: var(--text-muted); }
.res { flex: 1; display: flex; align-items: center; gap: 8px; padding: 11px; border-radius: 10px; background: var(--bg-card); border: 1px solid var(--line-card); }
.res b { display: block; font: 700 15px var(--font-head); }
.res span { font: 500 9px var(--font-mono); color: var(--text-muted); }
.charted { margin-top: -12px; font: 500 11px var(--font-mono); color: var(--text-dim); }
.flags { font: 500 12px var(--font-mono); color: var(--text-muted); }
.flags b { color: var(--text); }
.flags.lost { color: var(--warn); }
.counterattack { font: 600 12px var(--font-mono); color: var(--danger); border: 1px solid var(--danger); border-radius: 8px; padding: 6px 9px; margin-top: 6px; }
.counterattack.defending { color: var(--info); border-color: var(--info); }
.counterattack .full-flag { color: var(--info); margin-top: 3px; }

.where { display: flex; align-items: center; gap: 8px; font: 500 12px var(--font-mono); color: var(--text-muted); }
.where b { color: var(--text); font-weight: 600; }
.where .tick { margin-left: auto; padding: 3px 8px; border-radius: 6px; border: 1px solid var(--line-card); color: var(--text-2); letter-spacing: .12em; }

.fleet { flex: 1; }
.fleet .kicker { margin-bottom: 10px; }
.fleet ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 9px; }
.fleet li { display: flex; align-items: center; gap: 12px; padding: 11px 13px; border-radius: 10px; background: var(--bg-card); border: 1px solid var(--line-card); }
.ship-ico { color: var(--info); flex: none; }
.ship-txt { flex: 1; }
.ship-name { font: 600 14px var(--font-head); }
.ship-role { font: 500 10px var(--font-mono); color: var(--text-muted); }
.cond { width: 44px; height: 5px; border-radius: 3px; background: var(--bg-card-off); overflow: hidden; }
.cond div { width: 100%; height: 100%; background: var(--info); }
.cond div.dmg { width: 45%; background: var(--warn); }

.onboarding { display: flex; flex-direction: column; gap: 14px; }
.onboarding h2 { font: 700 24px var(--font-head); margin: 0; }
.onboarding p { margin: 0; font-size: 14px; }
.onboarding form { display: flex; flex-direction: column; gap: 10px; }
.onboarding input {
    padding: 13px 14px;
    border-radius: 10px;
    border: 1px solid var(--line-btn);
    background: var(--bg-card);
    color: var(--text);
    font: 500 15px var(--font-body);
}
.onboarding input:focus { outline: none; border-color: var(--info); }
.small { font-size: 12px !important; }

@media (max-width: 1100px) {
    .layout { grid-template-columns: 1fr; }
    .captain { border-left: none; border-top: 1px solid var(--line); }
    h1 { font-size: 64px; }
}
</style>
