<template>
    <main class="expedition">
        <div class="exp-head">
            <h1>Rejs</h1>
            <router-link :to="{ name: 'home' }" class="back">← Port</router-link>
        </div>

        <p v-if="loading" class="muted">Wczytywanie wyprawy…</p>
        <p v-if="error" class="err">{{ error }}</p>

        <!-- Pierwsza wizyta: załóż profil kapitana -->
        <section v-if="!loading && needsProfile" class="card intro">
            <h2>Ocalałeś z katastrofy…</h2>
            <p>
                Twój okręt zatonął, ale Ty żyjesz. Z desek wraku skleciłeś tratwę.
                Czas odbudować flotę — od rozbitka do admirała.
            </p>
            <form class="name-form" @submit.prevent="onCreateProfile">
                <label for="captain-name">Jak się nazywasz, rozbitku?</label>
                <input
                    id="captain-name"
                    v-model.trim="newName"
                    maxlength="40"
                    placeholder="Imię kapitana"
                    autocomplete="off"
                />
                <button class="btn" :disabled="creating || newName.length === 0">
                    {{ creating ? 'Tworzenie…' : 'Wypłyń' }}
                </button>
            </form>
        </section>

        <template v-if="expedition">
            <!-- Pasek profilu: ranga + postęp XP -->
            <section class="card profile">
                <div class="profile-head">
                    <strong>{{ expedition.profile.name }}</strong>
                    <span class="rank">{{ rankLabel(expedition.profile.rank) }}</span>
                </div>
                <div class="xp-row">
                    <span>{{ expedition.profile.xp }} XP</span>
                    <span class="stock" title="Ładownia floty (zajęte / pojemność)">⚓ {{ formatResources(expedition.profile.cargo, 'pusta ładownia') }} ({{ totalResources(expedition.profile.cargo) }}/{{ expedition.profile.cargoCapacity }})</span>
                    <template v-if="expedition.profile.nextRank">
                        <div class="xp-bar" role="progressbar">
                            <div class="xp-fill" :style="{ width: xpProgress + '%' }"></div>
                        </div>
                        <span class="muted">
                            do rangi {{ rankLabel(expedition.profile.nextRank.rank) }}:
                            jeszcze {{ expedition.profile.nextRank.xpNeeded }} XP
                        </span>
                    </template>
                    <span v-else class="muted">najwyższa ranga — morza należą do Ciebie</span>
                </div>
            </section>

            <!-- Mapa morza: mgła, wyspy, pozycja -->
            <section class="card sea">
                <div class="island-head">
                    <h2>🗺️ Morze</h2>
                    <span class="muted">tik {{ expedition.world.tick }} · nieodwiedzone sektory: ryzyko sztormu {{ expedition.world.stormChance }}%</span>
                </div>
                <div class="sea-grid" :style="{ gridTemplateColumns: `repeat(${expedition.world.width}, 1.7rem)` }">
                    <button
                        v-for="cell in seaCells"
                        :key="cell.key"
                        class="sea-cell"
                        :class="cell.cls"
                        :disabled="busy || !cell.sailable"
                        :title="cell.title"
                        @click="onSail(cell.x, cell.y)"
                    >{{ cell.glyph }}</button>
                </div>
                <p v-if="unescortedConvoy" class="convoy-warn">⚠️ Konwój bez eskorty — podwójne straty w sztormie. Eskortuje sprawny kuter, niszczyciel albo lotniskowiec.</p>
                <p v-if="seaMsg" class="sea-msg">{{ seaMsg }}</p>
            </section>

            <!-- Flota i port (stocznia dostępna tylko w doku) -->
            <section id="stocznia" class="card port">
                <h2>⚓ Flota</h2>
                <ul class="ships">
                    <li v-for="ship in expedition.fleet" :key="ship.id" class="ship" :class="{ dmg: ship.damaged }">
                        <span class="ship-name">{{ SHIP_ICONS[ship.type] }} {{ SHIP_LABELS[ship.type] }}</span>
                        <template v-if="ship.damaged">
                            <span class="status">🔧 uszkodzony</span>
                            <button
                                class="btn small"
                                :disabled="busy || repairBlock(ship) !== null"
                                :title="repairBlock(ship) ?? ''"
                                @click="onRepair(ship)"
                            >
                                Remont ({{ formatResources(ship.repairCost) }})
                            </button>
                            <span v-if="repairBlock(ship)" class="muted">{{ repairBlock(ship) }}</span>
                        </template>
                        <span v-else class="status ok">sprawny</span>
                    </li>
                </ul>

                <template v-if="currentIsland">
                    <h3>📦 Ładownia i magazyn — {{ currentIsland.name }}
                        <span class="muted">(ładownia {{ totalResources(expedition.profile.cargo) }}/{{ expedition.profile.cargoCapacity }}, każde przeniesienie = 1 tik)</span>
                    </h3>
                    <table class="hold">
                        <thead><tr><th></th><th>magazyn wyspy</th><th></th><th>ładownia</th></tr></thead>
                        <tbody>
                            <tr v-for="r in RESOURCES" :key="r.key">
                                <th><span class="res-dot" :style="{ background: r.color }"></span> {{ r.label }}</th>
                                <td>{{ currentIsland.stockpile[r.key] }}</td>
                                <td class="moves">
                                    <button
                                        class="btn small"
                                        :disabled="busy || loadable(r.key) === 0"
                                        :title="loadable(r.key) === 0 ? 'magazyn pusty albo ładownia pełna' : `załaduj ${loadable(r.key)}`"
                                        @click="onTransfer('load', r.key, loadable(r.key))"
                                    >załaduj →</button>
                                    <button
                                        class="btn small"
                                        :disabled="busy || expedition.profile.cargo[r.key] === 0"
                                        @click="onTransfer('unload', r.key, expedition.profile.cargo[r.key])"
                                    >← rozładuj</button>
                                </td>
                                <td>{{ expedition.profile.cargo[r.key] }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <h3>🏗️ Stocznia — {{ currentIsland.name }} <span class="muted">(poziom {{ currentIsland.shipyardLevel }})</span></h3>
                    <ul class="ships">
                        <li v-for="t in expedition.shipTypes" :key="t.type" class="ship">
                            <span class="ship-name">{{ SHIP_ICONS[t.type] }} {{ SHIP_LABELS[t.type] }} ({{ t.length }}-masztowiec)</span>
                            <span class="muted">ładownia {{ t.cargoCapacity }}{{ t.armed ? '' : ' · bez broni' }}</span>
                            <button
                                class="btn small"
                                :disabled="busy || buildBlock(t) !== null"
                                :title="buildBlock(t) ?? ''"
                                @click="onBuild(t)"
                            >
                                Buduj ({{ formatResources(t.buildCost) }})
                            </button>
                            <span v-if="buildBlock(t)" class="muted">{{ buildBlock(t) }}</span>
                        </li>
                    </ul>
                </template>
                <p v-else class="muted">🏗️ Stocznia dostępna po zawinięciu do portu — dopłyń na wyspę.</p>
            </section>

            <!-- Trwająca bitwa -->
            <section v-if="pendingBattle" class="card pending">
                ⚔️ Trwa bitwa o <strong>{{ pendingBattle.islandName }}</strong>.
                <router-link class="btn" :to="{ name: 'game', params: { id: pendingBattle.gameId } }">
                    Wróć do bitwy
                </router-link>
            </section>

            <!-- Odkryte wyspy (nieodkryte kryje mgła) -->
            <ol class="islands">
                <li
                    v-for="island in discoveredIslands"
                    :key="island.id"
                    class="card island"
                    :class="{ locked: !island.unlocked }"
                >
                    <div class="island-head">
                        <h3>{{ island.unlocked ? '🏝️' : '🔒' }} {{ island.name }}</h3>
                        <span class="mode-tag">{{ island.mode === 'fun' ? 'bronie specjalne' : 'klasyczna bitwa' }}</span>
                    </div>
                    <p class="desc">{{ island.description }}</p>
                    <div class="island-meta">
                        <span>🏆 +{{ island.xpWin }} XP, {{ formatResources(island.lootWin, 'bez łupów') }}</span>
                        <span class="muted">porażka: +{{ island.xpLoss }} XP, {{ formatResources(island.lootLoss, 'bez łupów') }}</span>
                        <span class="muted">🏗️ stocznia poz. {{ island.shipyardLevel }} · plansza {{ island.board.w }}×{{ island.board.h }}</span>
                        <span v-if="island.wins || island.losses" class="muted">
                            bilans: {{ island.wins }}W / {{ island.losses }}P
                        </span>
                    </div>
                    <div v-if="island.unlocked && island.present" class="squad">
                        <span :class="{ over: activeCells > island.squadLimit }">
                            skład: {{ squadCells(squadFor(island)) }} / {{ island.squadLimit }} pól
                            <template v-if="activeCells > island.squadLimit"> · flota ma {{ activeCells }} pól — część zostanie w porcie</template>
                            <template v-else-if="!squadPick && leavesShipsInPort(island)"> · transportowiec płynie tylko wybrany ręcznie</template>
                        </span>
                        <button v-if="activeCells > island.squadLimit || leavesShipsInPort(island)" class="btn small" @click="togglePicker(island)">
                            {{ squadPick ? 'skład domyślny' : 'wybierz skład' }}
                        </button>
                        <ul v-if="squadPick" class="squad-pick">
                            <li v-for="ship in activeShips" :key="ship.id">
                                <label :class="{ off: !squadPick.has(ship.id) && squadCells(squadFor(island)) + ship.length > island.squadLimit }">
                                    <input
                                        type="checkbox"
                                        :checked="squadPick.has(ship.id)"
                                        :disabled="!squadPick.has(ship.id) && squadCells(squadFor(island)) + ship.length > island.squadLimit"
                                        @change="toggleShip(ship.id)"
                                    />
                                    {{ SHIP_ICONS[ship.type] }} {{ SHIP_LABELS[ship.type] }} · {{ fieldsLabel(ship.length) }}
                                </label>
                            </li>
                        </ul>
                        <span v-if="convoyBlocked(island)" class="over">⚠️ konwój bez eskorty nie zaatakuje wyspy — dobierz kuter, niszczyciel albo lotniskowiec</span>
                    </div>
                    <button
                        v-if="island.unlocked && island.present"
                        class="btn"
                        :disabled="starting !== null || pendingBattle !== null || squadFor(island).length === 0 || convoyBlocked(island)"
                        @click="onStartBattle(island)"
                    >
                        {{ starting === island.id ? 'Stawianie żagli…' : '⚔️ Bitwa' }}
                    </button>
                    <p v-else-if="island.unlocked" class="muted">⛵ Dopłyń na wyspę, aby walczyć i korzystać ze stoczni.</p>
                    <p v-else class="muted">Wymaga rangi: {{ rankLabel(island.requiredRank) }}</p>
                </li>
            </ol>
        </template>
    </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    buildShip, createProfile, getExpedition, repairShip, sail, settleBattle, startIslandBattle, transferCargo,
    RANK_LABELS, RANK_ORDER, SHIP_ICONS, SHIP_LABELS,
    type ExpeditionDTO, type FleetShipDTO, type IslandDTO, type RankName, type ShipTypeDTO,
} from '../api/expeditionApi';
import { ApiError } from '../api/http';
import {
    clearCurrentBattle, clearProfileId, getCurrentBattle, getProfileId,
    setCurrentBattle, setProfileId, type CurrentBattle,
} from '../lib/expeditionSession';
import { defaultSquad, isUnescortedConvoy, squadCells } from '../lib/squad';
import { fieldsLabel } from '../lib/ships';
import {
    RESOURCES, addResources, covers, formatResources, isEmpty, missing, totalResources, type ResourceKey,
} from '../lib/resources';

const router = useRouter();
const route = useRoute();

const loading = ref(true);
const creating = ref(false);
const starting = ref<string | null>(null);
const error = ref('');
const newName = ref('');
const needsProfile = ref(false);
const expedition = ref<ExpeditionDTO | null>(null);
const pendingBattle = ref<CurrentBattle | null>(null);

const xpProgress = computed(() => {
    const profile = expedition.value?.profile;
    if (!profile?.nextRank) return 100;
    const target = profile.xp + profile.nextRank.xpNeeded;
    return target > 0 ? Math.min(100, Math.round((profile.xp / target) * 100)) : 0;
});

function rankLabel(rank: RankName): string {
    return RANK_LABELS[rank] ?? rank;
}

// --- Wolne morze: mapa, żegluga, port w doku ---

const busy = ref(false);
const seaMsg = ref('');


function rankAtLeast(rank: RankName, required: RankName): boolean {
    return RANK_ORDER.indexOf(rank) >= RANK_ORDER.indexOf(required);
}

/** Wyspa, w której porcie stoi kapitan (stocznia i bitwa tylko tutaj). */
const currentIsland = computed<IslandDTO | null>(
    () => expedition.value?.islands.find(i => i.present) ?? null
);

const discoveredIslands = computed<IslandDTO[]>(
    () => expedition.value?.islands.filter(i => i.discovered) ?? []
);

interface SeaCell {
    key: string; x: number; y: number;
    cls: Record<string, boolean>; glyph: string; title: string; sailable: boolean;
}

const seaCells = computed<SeaCell[]>(() => {
    const exp = expedition.value;
    if (!exp) return [];
    const world = exp.world;
    const fog = new Set(world.discovered);
    const visited = new Set(world.visited);
    const islandsByPos = new Map(
        exp.islands.filter(i => i.discovered && i.position).map(i => [`${i.position!.x}:${i.position!.y}`, i])
    );
    const { x: px, y: py } = world.position;

    const cells: SeaCell[] = [];
    for (let y = 0; y < world.height; y++) {
        for (let x = 0; x < world.width; x++) {
            const key = `${x}:${y}`;
            const discovered = fog.has(key);
            const island = islandsByPos.get(key) ?? null;
            const isPlayer = x === px && y === py;
            const sailable = Math.max(Math.abs(x - px), Math.abs(y - py)) === 1;
            // sztorm grozi przy pierwszym wpłynięciu na sektor (#68)
            const stormRisk = sailable && !visited.has(key);

            cells.push({
                key, x, y, sailable,
                cls: { fog: !discovered, water: discovered && !island, island: !!island, player: isPlayer, sailable, risk: stormRisk },
                glyph: isPlayer ? '⛵' : island ? (island.unlocked ? '🏝️' : '🔒') : '',
                title: [
                    island ? island.name : discovered ? `${x}:${y}` : 'niezbadane wody',
                    stormRisk ? `pierwsze wpłynięcie — ryzyko sztormu ${world.stormChance}%` : '',
                ].filter(Boolean).join(' · '),
            });
        }
    }
    return cells;
});

async function onSail(x: number, y: number) {
    const profileId = getProfileId();
    if (!profileId) return;
    busy.value = true;
    error.value = '';
    try {
        const result = await sail(profileId, x, y);
        const parts: string[] = [];
        if (result.cartography > 0) parts.push(`+${result.cartography - result.cartographyLost} drewna za nowe mapy`);
        if (result.cartographyLost > 0) parts.push(`ładownia pełna — ${result.cartographyLost} drewna przepadło`);
        if (result.event?.effect === 'cargo-lost') {
            const convoy = result.event.unescorted ? ' (konwój bez eskorty — podwójne straty)' : '';
            parts.push(`🌩️ Sztorm! Fale zabrały ${formatResources(result.event.cargoLost!, 'nic — ładownia była pusta')}${convoy}`);
        }
        if (result.event?.effect === 'ship-damaged') {
            parts.push(`🌩️ Sztorm! ${SHIP_LABELS[result.event.ship!]} uszkodzony`);
            if (result.event.cargoLost && !isEmpty(result.event.cargoLost)) parts.push(`za burtą: ${formatResources(result.event.cargoLost)}`);
        }
        seaMsg.value = parts.join(' · ');
        expedition.value = await getExpedition(profileId);
    } catch (e: any) {
        error.value = e?.message ?? 'Żegluga nie powiodła się';
    } finally {
        busy.value = false;
    }
}

/** Powód blokady budowy typu; null = można budować. */
function buildBlock(t: ShipTypeDTO): string | null {
    const profile = expedition.value?.profile;
    const island = currentIsland.value;
    if (!profile || !island) return 'dopłyń do portu';
    if (!rankAtLeast(profile.rank, t.requiredRank)) return `wymaga rangi: ${rankLabel(t.requiredRank)}`;
    if (island.shipyardLevel < t.requiredShipyardLevel) return `wymaga stoczni poziomu ${t.requiredShipyardLevel}`;
    const available = addResources(island.stockpile, profile.cargo);
    if (!covers(available, t.buildCost)) return missing(available, t.buildCost);
    return null;
}

/** Powód blokady remontu statku; null = można remontować. */
function repairBlock(ship: FleetShipDTO): string | null {
    const profile = expedition.value?.profile;
    const island = currentIsland.value;
    const type = expedition.value?.shipTypes.find(t => t.type === ship.type);
    if (!profile || !type) return 'wczytywanie…';
    if (!island) return 'dopłyń do portu';
    if (island.shipyardLevel < type.requiredShipyardLevel) return `wymaga stoczni poziomu ${type.requiredShipyardLevel}`;
    const available = addResources(island.stockpile, profile.cargo);
    if (!covers(available, ship.repairCost)) return missing(available, ship.repairCost);
    return null;
}

async function onBuild(t: ShipTypeDTO) {
    const profileId = getProfileId();
    const island = currentIsland.value;
    if (!profileId || !island) return;
    busy.value = true;
    error.value = '';
    try {
        await buildShip(profileId, island.id, t.type);
        expedition.value = await getExpedition(profileId);
    } catch (e: any) {
        error.value = e?.message ?? 'Budowa nie powiodła się';
    } finally {
        busy.value = false;
    }
}

/** Ile surowca da się załadować: tyle, ile jest w magazynie, ale nie ponad wolne miejsce. */
function loadable(key: ResourceKey): number {
    const exp = expedition.value;
    const island = currentIsland.value;
    if (!exp || !island) return 0;
    const room = exp.profile.cargoCapacity - totalResources(exp.profile.cargo);
    return Math.max(0, Math.min(island.stockpile[key], room));
}

async function onTransfer(direction: 'load' | 'unload', key: ResourceKey, amount: number) {
    const profileId = getProfileId();
    const island = currentIsland.value;
    if (!profileId || !island || amount <= 0) return;
    busy.value = true;
    error.value = '';
    try {
        await transferCargo(profileId, island.id, direction, { [key]: amount });
        expedition.value = await getExpedition(profileId);
    } catch (e: any) {
        error.value = e?.message ?? 'Przeładunek nie powiódł się';
    } finally {
        busy.value = false;
    }
}

async function onRepair(ship: FleetShipDTO) {
    const profileId = getProfileId();
    const island = currentIsland.value;
    if (!profileId || !island) return;
    busy.value = true;
    error.value = '';
    try {
        await repairShip(profileId, island.id, ship.id);
        expedition.value = await getExpedition(profileId);
    } catch (e: any) {
        error.value = e?.message ?? 'Remont nie powiódł się';
    } finally {
        busy.value = false;
    }
}

onMounted(load);

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const profileId = getProfileId();
        if (!profileId) {
            needsProfile.value = true;
            return;
        }
        await settlePendingBattle(profileId);
        expedition.value = await getExpedition(profileId);
        // wejście z portu „Stocznia" (#stocznia) — przewiń po wyrenderowaniu
        if (route.hash) {
            requestAnimationFrame(() => document.querySelector(route.hash)?.scrollIntoView({ behavior: 'smooth' }));
        }
    } catch (e: any) {
        // profil zniknął (np. wyczyszczona baza) — zacznij od nowa
        if (e instanceof ApiError && e.status === 404) {
            clearProfileId();
            clearCurrentBattle();
            needsProfile.value = true;
            return;
        }
        error.value = e?.message ?? 'Nie udało się wczytać wyprawy';
    } finally {
        loading.value = false;
    }
}

/**
 * Siatka bezpieczeństwa: jeśli poprzednia bitwa skończyła się bez rozliczenia
 * (np. zamknięta karta), rozlicz ją przy wejściu na mapę. Rozliczenie jest
 * po stronie backendu idempotentne.
 */
async function settlePendingBattle(profileId: string) {
    const battle = getCurrentBattle();
    pendingBattle.value = null;
    if (!battle || battle.profileId !== profileId) return;
    try {
        await settleBattle(battle.profileId, battle.gameId);
        clearCurrentBattle();
    } catch (e: any) {
        if (e instanceof ApiError && 409 === e.status) {
            // bitwa wciąż trwa — pokaż powrót do gry
            pendingBattle.value = battle;
            return;
        }
        // gra nie istnieje / nie należy do profilu — sprzątamy martwy wpis
        clearCurrentBattle();
    }
}

async function onCreateProfile() {
    creating.value = true;
    error.value = '';
    try {
        const profile = await createProfile(newName.value);
        setProfileId(profile.id);
        needsProfile.value = false;
        expedition.value = await getExpedition(profile.id);
    } catch (e: any) {
        error.value = e?.message ?? 'Nie udało się utworzyć profilu';
    } finally {
        creating.value = false;
    }
}

// --- skład bitewny (#73): domyślny dobór albo ręczny wybór, gdy flota przekracza limit ---

const squadPick = ref<Set<string> | null>(null);
const activeShips = computed(() => (expedition.value?.fleet ?? []).filter(s => !s.damaged));
const activeCells = computed(() => squadCells(activeShips.value));

// konwój w rejsie: sprawna flota z transportowcem bez statku z bronią
const unescortedConvoy = computed(() => isUnescortedConvoy(activeShips.value));

/** Dobór domyślny zostawia w porcie statki spoza priorytetu (transportowiec), choć jest miejsce. */
function leavesShipsInPort(island: IslandDTO): boolean {
    return defaultSquad(expedition.value?.fleet ?? [], island.squadLimit).length < activeShips.value.length;
}

/** Konwój bez eskorty nie atakuje — także gdy sprawne są same transportowce (dobór domyślny jest pusty). */
function convoyBlocked(island: IslandDTO): boolean {
    const squad = squadFor(island);
    return isUnescortedConvoy(squad.length ? squad : activeShips.value);
}

function squadFor(island: IslandDTO): FleetShipDTO[] {
    if (squadPick.value) return activeShips.value.filter(s => squadPick.value!.has(s.id));
    return defaultSquad(expedition.value?.fleet ?? [], island.squadLimit);
}

function togglePicker(island: IslandDTO) {
    squadPick.value = squadPick.value ? null : new Set(defaultSquad(expedition.value?.fleet ?? [], island.squadLimit).map(s => s.id));
}

function toggleShip(shipId: string) {
    if (!squadPick.value) return;
    const next = new Set(squadPick.value);
    if (next.has(shipId)) next.delete(shipId); else next.add(shipId);
    squadPick.value = next;
}

async function onStartBattle(island: IslandDTO) {
    const profileId = getProfileId();
    if (!profileId) return;
    starting.value = island.id;
    error.value = '';
    try {
        const shipIds = squadPick.value ? [...squadPick.value] : undefined;
        const game = await startIslandBattle(profileId, island.id, shipIds);
        setCurrentBattle({ profileId, gameId: game.id, islandId: island.id, islandName: island.name });
        await router.push({ name: 'place-fleet', params: { id: game.id } });
    } catch (e: any) {
        error.value = e?.message ?? 'Nie udało się rozpocząć bitwy';
        starting.value = null;
    }
}
</script>

<style scoped>
.expedition { max-width: 820px; margin: 0 auto; }
.exp-head { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 14px; }
h1 { font: 700 34px var(--font-head); margin: 0; }
h2, h3 { font-family: var(--font-head); }
.back { font: 500 12px var(--font-mono); color: var(--text-muted); }
.card { border: 1px solid var(--line-card); border-radius: 14px; padding: 18px 20px; margin-bottom: 14px; background: var(--bg-card); }
.intro h2 { margin-top: 0; }
.name-form { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
.name-form input { padding: 10px 12px; border: 1px solid var(--line-btn); border-radius: 10px; background: var(--bg-panel); color: var(--text); }
.profile-head { display: flex; gap: 0.6rem; align-items: baseline; font-family: var(--font-head); }
.rank { color: var(--accent); font: 600 11px var(--font-mono); letter-spacing: .18em; text-transform: uppercase; }
.xp-row { display: flex; gap: 0.6rem; align-items: center; margin-top: 0.4rem; flex-wrap: wrap; font-size: 14px; }
.xp-bar { flex: 1 1 140px; min-width: 120px; height: 8px; background: var(--bg-panel); border: 1px solid var(--line-card); border-radius: 5px; overflow: hidden; }
.xp-fill { height: 100%; background: linear-gradient(90deg, var(--info), var(--info-light)); transition: width 0.4s ease; }
.pending { display: flex; gap: 0.75rem; align-items: center; border-color: var(--accent); }
.stock { font-weight: 600; color: var(--warn); }
.squad { display: flex; flex-wrap: wrap; gap: 0.5rem 0.8rem; align-items: center; margin-bottom: 0.6rem; font: 500 12px var(--font-mono); color: var(--text-muted); }
.squad .over { color: var(--warn); }
.convoy-warn { margin: 0.5rem 0 0; font: 500 12px var(--font-mono); color: var(--warn); }
.squad-pick { list-style: none; padding: 0; margin: 0; width: 100%; display: flex; flex-wrap: wrap; gap: 6px 14px; }
.squad-pick label { cursor: pointer; color: var(--text-2); }
.squad-pick label.off { opacity: .45; cursor: not-allowed; }
.hold { border-collapse: collapse; margin: 0.3rem 0 0.6rem; font-size: 14px; }
.hold th, .hold td { padding: 4px 10px; text-align: left; }
.hold thead th { font: 600 10px var(--font-mono); letter-spacing: .12em; text-transform: uppercase; color: var(--text-label); }
.hold td { font-weight: 600; }
.hold .moves { display: flex; gap: 6px; }
.res-dot { width: 10px; height: 10px; border-radius: 3px; display: inline-block; vertical-align: middle; }
.sea-grid { display: grid; gap: 3px; margin-top: 0.75rem; }
.sea-cell { width: 1.7rem; height: 1.7rem; border: 0; border-radius: 4px; padding: 0; font-size: 0.95rem; line-height: 1; cursor: default; }
.sea-cell.fog { background: #0a1b23; box-shadow: inset 0 0 0 1px #12303a; }
.sea-cell.water { background: #0c2029; box-shadow: inset 0 0 0 1px #163540; }
.sea-cell.island { background: #3b3220; box-shadow: inset 0 0 0 1px #6b5a35; }
.sea-cell.player { background: #13404a; box-shadow: inset 0 0 0 2px var(--info); }
.sea-cell.sailable:not(:disabled) { cursor: pointer; outline: 1px dashed var(--info); outline-offset: -3px; }
.sea-cell.sailable.risk:not(:disabled) { outline-color: var(--warn); }
.sea-cell.sailable:not(:disabled):hover { filter: brightness(1.3); }
.sea-msg { margin: 0.5rem 0 0; color: var(--warn); }
.port h2, .port h3 { margin: 0 0 0.4rem; font-size: 1.05rem; }
.port h3 { margin-top: 0.8rem; }
.ships { list-style: none; padding: 0; margin: 0; }
.ship { display: flex; gap: 0.6rem; align-items: center; padding: 0.3rem 0; flex-wrap: wrap; }
.ship.dmg .ship-name { opacity: 0.7; }
.ship-name { min-width: 12rem; }
.status { font-size: 0.85rem; color: var(--accent-light); }
.status.ok { color: var(--info); }
.islands { list-style: none; padding: 0; margin: 0; }
.island.locked { opacity: 0.55; }
.island-head { display: flex; justify-content: space-between; align-items: baseline; gap: 0.6rem; }
.island-head h3 { margin: 0; }
.mode-tag { font: 600 10px var(--font-mono); letter-spacing: .1em; text-transform: uppercase; color: var(--text-muted); border: 1px solid var(--line-btn); border-radius: 999px; padding: 0.15rem 0.55rem; white-space: nowrap; }
.desc { color: var(--text-desc); margin: 0.4rem 0 0.5rem; }
.island-meta { display: flex; gap: 0.9rem; margin-bottom: 0.6rem; flex-wrap: wrap; font-size: 14px; }
.btn { padding: 9px 16px; background: var(--accent); color: var(--accent-ink); border: 0; border-radius: 10px; cursor: pointer; text-decoration: none; display: inline-block; font: 700 14px var(--font-head); }
.btn.small { padding: 5px 10px; font-size: 12px; border-radius: 8px; background: var(--bg-card-active); color: var(--text); border: 1px solid var(--line-btn); }
.btn[disabled] { background: var(--bg-disabled); color: var(--text-dim); cursor: not-allowed; }
.muted { color: var(--text-muted); font-size: 0.9rem; }
.err { color: var(--danger); }
</style>
