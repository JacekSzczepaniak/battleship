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
                    <a v-if="expedition.research.current" href="#badania" class="research-tag" :class="{ paused: expedition.research.current.paused }">
                        🎓 {{ TECH_LABELS[expedition.research.current.technology] }}:
                        {{ expedition.research.current.paused ? 'wstrzymane' : `${expedition.research.current.progress}/${expedition.research.current.ticks} tików` }}
                    </a>
                </div>
            </section>

            <!-- Kontratak (#78): zapowiedź z terminem i ostatnie rozstrzygnięcie -->
            <section v-if="counterattack.pending" class="card counterattack" :class="{ defending: counterattack.pending.defenseStarted }">
                <strong>{{ counterattack.pending.defenseStarted ? '🛡️' : '⚔️' }} {{ pendingLabel(counterattack.pending) }}</strong>
                <span v-if="counterattack.pending.fullFlag" class="full-flag">🚩 {{ FULL_FLAG_BONUS }}</span>
                <span v-if="!counterattack.pending.defenseStarted" class="muted">{{ defenseHint(counterattack.pending) }}</span>
            </section>
            <p v-if="counterattack.last" class="last-counterattack" :class="{ lost: !counterattack.last.held }">
                {{ lastIcon(counterattack.last) }} {{ lastLabel(counterattack.last) }}
            </p>

            <!-- Mapa morza: mgła, wyspy, pozycja -->
            <section class="card sea">
                <div class="island-head">
                    <h2>🗺️ {{ expedition.world.sea.name }}</h2>
                    <span class="muted">tik {{ expedition.world.tick }} · nieodwiedzone sektory: ryzyko sztormu {{ expedition.world.stormChance }}%</span>
                </div>
                <p class="muted sea-theme">{{ seaThemeText }}</p>
                <div class="sea-grid" :style="{ gridTemplateColumns: `repeat(${expedition.world.width}, 1.7rem)` }">
                    <button
                        v-for="cell in seaCells"
                        :key="cell.key"
                        class="sea-cell"
                        :class="cell.cls"
                        :disabled="busy || !cell.sailable"
                        :title="cell.title"
                        @click="onSail(cell.x, cell.y)"
                    >{{ cell.glyph }}<small v-if="cell.flag" class="flag">🚩</small><small v-if="cell.attacked" class="attacked">⚔️</small><small v-if="cell.badge" class="badge">{{ cell.badge }}</small><span v-if="cell.name" class="name">{{ cell.name }}</span></button>
                </div>
                <p v-if="unescortedConvoy" class="convoy-warn">⚠️ Konwój bez eskorty — podwójne straty w sztormie. Eskortuje sprawny kuter, niszczyciel albo lotniskowiec.</p>
                <p v-if="seaMsg" class="sea-msg">{{ seaMsg }}</p>
            </section>

            <!-- Magazyny i złoża: podgląd odkrytych wysp; przeładunek tylko w porcie -->
            <section v-if="economy.length" class="card economy">
                <h2>🏝️ Magazyny i złoża</h2>
                <table class="eco">
                    <thead><tr><th>wyspa</th><th>stocznia</th><th>złoże</th><th>magazyn</th></tr></thead>
                    <tbody>
                        <tr v-for="island in economy" :key="island.id" :class="{ here: island.present }">
                            <th>{{ island.present ? '📍' : '' }} {{ island.name }}<span v-if="island.owned" title="pod Twoją banderą"> 🚩</span><span v-if="counterattack.pending?.island === island.id" :title="pendingLabel(counterattack.pending!)"> ⚔️</span></th>
                            <td>{{ island.shipyardLevel > 0 ? `🏗️ poz. ${island.shipyardLevel}` : '—' }}</td>
                            <td :class="`dep-${depositState(island)}`">{{ island.deposit ? `⛏️ ${depositLabel(island)}` : '—' }}</td>
                            <td>{{ formatResources(island.stockpile, 'pusto') }}</td>
                        </tr>
                    </tbody>
                </table>
                <p class="muted">Urobek złoża czeka w magazynie wyspy — dopłyń, załaduj i zawieź do stoczni.</p>
            </section>

            <!-- Badania (Epic G): drzewko, badanie w toku, start w uczelni wyspy -->
            <section id="badania" class="card research">
                <h2>🎓 Badania</h2>
                <TechTreePanel
                    :research="expedition.research"
                    :available="currentIsland ? addResources(currentIsland.stockpile, expedition.profile.cargo) : null"
                    :busy="busy"
                    @research="onStartResearch"
                />
            </section>

            <!-- Flota i port (stocznia dostępna tylko w doku) -->
            <section id="stocznia" class="card port">
                <h2>⚓ Flota</h2>
                <ul class="ships">
                    <li v-for="ship in expedition.fleet" :key="ship.id" class="ship" :class="{ dmg: ship.damaged }">
                        <span class="ship-name">{{ SHIP_ICONS[ship.type] }} {{ fleetShipLabel(ship) }}</span>
                        <template v-if="ship.damaged">
                            <span class="status" title="wozi ładunek, ale nie walczy i nie eskortuje — do remontu w stoczni">🔧 uszkodzony</span>
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
                    <h3>📦 Przeładunek — {{ currentIsland.name }}</h3>
                    <CargoPanel
                        :island="currentIsland"
                        :cargo="expedition.profile.cargo"
                        :capacity="expedition.profile.cargoCapacity"
                        :busy="busy"
                        @transfer="onTransfer"
                    />

                    <h3 v-if="currentIsland.shipyardLevel > 0">🏗️ Stocznia — {{ currentIsland.name }} <span class="muted">(poziom {{ currentIsland.shipyardLevel }}{{ currentIsland.owned && currentIsland.buildings.includes('rozbudowa-stoczni') ? ', z rozbudową' : '' }})</span></h3>
                    <h3 v-else>🪵 {{ currentIsland.name }} — bez stoczni <span class="muted">(tratwę skleisz z desek na każdej wyspie)</span></h3>
                    <ul class="ships">
                        <li v-for="t in expedition.shipTypes" :key="t.type" class="ship">
                            <span class="ship-name">{{ SHIP_ICONS[t.type] }} {{ SHIP_LABELS[t.type] }} ({{ t.length }}-masztowiec)</span>
                            <span class="muted">ładownia {{ t.cargoCapacity }}{{ t.armed ? '' : ' · bez broni' }}</span>
                            <!-- wariant wybiera się przy budowie i zostaje na stałe (#99) -->
                            <template v-if="t.variants.length">
                                <button
                                    v-for="v in t.variants"
                                    :key="v.variant"
                                    class="btn small"
                                    :disabled="busy || buildBlock(t, v) !== null"
                                    :title="buildBlock(t, v) ?? `nośnik: ${carriedWeapon(t.length, VARIANTS[v.variant].w)} · wariant stały`"
                                    @click="onBuild(t, v.variant)"
                                >
                                    Buduj {{ SHIP_VARIANT_LABELS[v.variant] }} · {{ carriedWeapon(t.length, VARIANTS[v.variant].w) }} ({{ formatResources(t.buildCost) }})
                                </button>
                            </template>
                            <button
                                v-else
                                class="btn small"
                                :disabled="busy || buildBlock(t) !== null"
                                :title="buildBlock(t) ?? ''"
                                @click="onBuild(t)"
                            >
                                Buduj ({{ formatResources(t.buildCost) }})
                            </button>
                            <span v-if="buildBlock(t)" class="muted">{{ buildBlock(t) }}</span>
                            <span v-for="v in lockedVariants(t)" :key="v.variant" class="muted">
                                {{ SHIP_VARIANT_LABELS[v.variant] }}: po zbadaniu „{{ TECH_LABELS[v.requiredTechnology!] }}”
                            </span>
                        </li>
                    </ul>

                    <template v-if="currentIsland.owned">
                        <h3>🏘️ Osada — {{ currentIsland.name }}</h3>
                        <SettlementPanel
                            :island="currentIsland"
                            :building-types="expedition.buildingTypes"
                            :available="addResources(currentIsland.stockpile, expedition.profile.cargo)"
                            :busy="busy"
                            @build="onBuildBuilding"
                        />
                    </template>
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
                        <span v-if="island.owned" class="flag-tag">🚩 pod Twoją banderą</span>
                        <span class="mode-tag">{{ island.mode === 'fun' ? 'bronie specjalne' : 'klasyczna bitwa' }}</span>
                    </div>
                    <p class="desc">{{ island.description }}</p>
                    <div class="island-meta">
                        <span>🏆 +{{ island.xpWin }} XP, {{ formatResources(island.lootWin, 'bez łupów') }}</span>
                        <span class="muted">porażka: +{{ island.xpLoss }} XP, {{ formatResources(island.lootLoss, 'bez łupów') }}</span>
                        <span class="muted">🏗️ {{ island.shipyardLevel > 0 ? `stocznia poz. ${island.shipyardLevel}` : 'bez stoczni' }} · plansza {{ island.board.w }}×{{ island.board.h }}</span>
                        <span v-if="island.deposit" :class="island.deposit.active ? 'deposit' : 'muted'">
                            ⛏️ złoże: {{ formatResources(island.deposit.yield) }}/tik
                            <template v-if="island.deposit.active"> · pracuje, urobek czeka w magazynie (do {{ formatResources(island.deposit.cap) }})</template>
                            <template v-else> · ruszy po pierwszej wygranej bitwie</template>
                        </span>
                        <span v-if="island.buildings.length" :class="island.owned ? 'deposit' : 'muted'">
                            🏘️ {{ island.buildings.map(b => BUILDING_LABELS[b]).join(', ') }}<template v-if="!island.owned"> · nieczynne bez bandery</template>
                        </span>
                        <span v-if="island.wins || island.losses" class="muted">
                            bilans: {{ island.wins }}W / {{ island.losses }}P
                        </span>
                    </div>
                    <div v-if="island.unlocked && island.present && (!island.owned || canDefend(island, counterattack.pending))" class="squad">
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
                                    {{ SHIP_ICONS[ship.type] }} {{ fleetShipLabel(ship) }} · {{ fieldsLabel(ship.length) }}
                                </label>
                            </li>
                        </ul>
                        <span v-if="convoyBlocked(island)" class="over">⚠️ konwój bez eskorty nie zaatakuje wyspy — dobierz kuter, niszczyciel albo lotniskowiec</span>
                    </div>
                    <!-- wyspa pod banderą nie jest areną: bitwa tylko w obronie przed kontratakiem (#78) -->
                    <template v-if="island.owned">
                        <button
                            v-if="island.present && canDefend(island, counterattack.pending)"
                            class="btn defend"
                            :disabled="starting !== null || pendingBattle !== null || squadFor(island).length === 0 || convoyBlocked(island)"
                            @click="onStartBattle(island)"
                        >
                            {{ starting === island.id ? 'Stawianie żagli…' : `🛡️ Broń wyspy — uderzenie za ${ticksLabel(counterattack.pending!.ticksLeft)}` }}
                        </button>
                        <p v-else-if="canDefend(island, counterattack.pending)" class="muted warn">
                            ⚔️ Kontratak za {{ ticksLabel(counterattack.pending!.ticksLeft) }} — dopłyń z flotą, by bronić wyspy.
                        </p>
                        <p v-else-if="counterattack.pending?.island === island.id" class="muted">🛡️ Obrona trwa — wróć do bitwy, by ją rozstrzygnąć.</p>
                        <p v-else class="muted">🚩 Wyspa pod Twoją banderą — walczysz tu tylko w obronie przed kontratakiem.</p>
                    </template>
                    <button
                        v-else-if="island.unlocked && island.present"
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
    buildBuilding, buildShip, createProfile, getExpedition, repairShip, sail, settleBattle, startIslandBattle, startResearch, transferCargo,
    BUILDING_LABELS, RANK_LABELS, RANK_ORDER, SHIP_ICONS, SHIP_LABELS, SHIP_VARIANT_LABELS, TECH_LABELS, fleetShipLabel,
    type BuildingTypeName, type TechnologyName, type ExpeditionDTO, type FleetShipDTO, type IslandDTO, type RankName, type ResourcesDTO, type ShipTypeDTO,
    type ShipVariantDTO, type ShipVariantName,
} from '../api/expeditionApi';
import { ApiError } from '../api/http';
import {
    clearCurrentBattle, clearProfileId, getCurrentBattle, getProfileId,
    setCurrentBattle, setProfileId, type CurrentBattle,
} from '../lib/expeditionSession';
import { defaultSquad, isUnescortedConvoy, squadCells } from '../lib/squad';
import { VARIANTS, carriedWeapon, fieldsLabel } from '../lib/ships';
import { depositLabel, depositState, economyIslands, whereToFind } from '../lib/economy';
import CargoPanel from '../components/CargoPanel.vue';
import SettlementPanel from '../components/SettlementPanel.vue';
import TechTreePanel from '../components/TechTreePanel.vue';
import { canDefend, defenseHint, FULL_FLAG_BONUS, lastIcon, lastLabel, pendingLabel, ticksLabel } from '../lib/counterattack';
import {
    addResources, covers, formatResources, isEmpty, missing, totalResources,
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

const economy = computed<IslandDTO[]>(() => economyIslands(expedition.value?.islands ?? []));

const counterattack = computed(() => expedition.value?.counterattack ?? { pending: null, last: null });

// motyw akwenu (Epic I): bazowy sztorm akwenu, promień odkrywania, mnożnik złóż
const seaThemeText = computed(() => {
    const theme = expedition.value?.world.sea.theme;
    if (!theme) return '';
    const visibility = theme.visibility === 0 ? 'tylko sektor floty' : `${theme.visibility} ${theme.visibility === 1 ? 'sektor' : 'sektory'} wokół floty`;
    return `Motyw akwenu: sztorm ${theme.stormChance}% · widoczność: ${visibility} · złoża ×${theme.depositMultiplier}`;
});

const discoveredIslands = computed<IslandDTO[]>(
    () => expedition.value?.islands.filter(i => i.discovered) ?? []
);

interface SeaCell {
    key: string; x: number; y: number;
    cls: Record<string, boolean>; glyph: string; badge: string; flag: boolean; attacked: boolean; title: string; sailable: boolean;
    /** nazwa wyspy w sektorze — etykieta po najechaniu (pusta na wodzie) */
    name: string;
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
    const pending = exp.counterattack.pending;

    const cells: SeaCell[] = [];
    for (let y = 0; y < world.height; y++) {
        for (let x = 0; x < world.width; x++) {
            const key = `${x}:${y}`;
            const discovered = fog.has(key);
            const island = islandsByPos.get(key) ?? null;
            const isPlayer = x === px && y === py;
            // osiągalne w jednym rejsie: sąsiednie, z Żeglugą dwusektorową także odległe o 2
            const distance = Math.max(Math.abs(x - px), Math.abs(y - py));
            const sailable = distance >= 1 && distance <= world.sailRange;
            // sztorm grozi przy pierwszym wpłynięciu na sektor docelowy (#68)
            const stormRisk = sailable && !visited.has(key);

            cells.push({
                key, x, y, sailable,
                cls: { fog: !discovered, water: discovered && !island, island: !!island, player: isPlayer, sailable, risk: stormRisk },
                glyph: isPlayer ? '⛵' : island ? (island.unlocked ? '🏝️' : '🔒') : '',
                badge: island ? (island.deposit ? '⛏' : island.shipyardLevel > 0 ? '🏗' : '') : '',
                flag: island?.owned ?? false,
                name: island?.name ?? '',
                attacked: !!island && pending?.island === island.id,
                title: [
                    island ? island.name : discovered ? `${x}:${y}` : 'niezbadane wody',
                    island?.owned ? 'pod Twoją banderą' : '',
                    island && pending?.island === island.id ? pendingLabel(pending) : '',
                    island && island.shipyardLevel > 0 ? `stocznia poz. ${island.shipyardLevel}` : '',
                    island?.deposit ? `złoże: ${depositLabel(island)}` : '',
                    island && !isEmpty(island.stockpile) ? `magazyn: ${formatResources(island.stockpile)}` : '',
                    sailable && distance > 1 ? 'rejs o 2 sektory za 1 tik' : '',
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
            parts.push(`🌩️ Sztorm! ${SHIP_LABELS[result.event.ship!]} uszkodzony — ładunek cały`);
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
function buildBlock(t: ShipTypeDTO, variant?: ShipVariantDTO): string | null {
    const profile = expedition.value?.profile;
    const island = currentIsland.value;
    if (!profile || !island) return 'dopłyń do portu';
    if (!rankAtLeast(profile.rank, t.requiredRank)) return `wymaga rangi: ${rankLabel(t.requiredRank)}`;
    if (island.shipyardLevel < t.requiredShipyardLevel) return shipyardMissing(island, t.requiredShipyardLevel);
    if (variant && !variant.unlocked) return `wymaga technologii: ${TECH_LABELS[variant.requiredTechnology!]}`;
    const available = addResources(island.stockpile, profile.cargo);
    if (!covers(available, t.buildCost)) return withSources(available, t.buildCost);
    return null;
}

/** „brakuje: 10 stali · leży w: Archipelag Mgieł: 14 stali” — gdzie szukać surowców do budowy/remontu. */
function withSources(available: ResourcesDTO, cost: ResourcesDTO): string {
    const short = missing(available, cost);
    const sources = whereToFind(expedition.value?.islands ?? [], cost, currentIsland.value?.id ?? '');
    return sources ? `${short} · leży w: ${sources}` : short;
}

/** Stocznie są tylko na dwóch wyspach (#48) — podpowiedź, dokąd płynąć. */
function shipyardMissing(island: IslandDTO, level: number): string {
    const yards = (expedition.value?.islands ?? [])
        .filter(i => i.shipyardLevel >= level && i.discovered)
        .map(i => i.name);
    const where = yards.length ? ` — płyń do: ${yards.join(', ')}` : '';
    return island.shipyardLevel === 0
        ? `brak stoczni${where}`
        : `wymaga stoczni poziomu ${level}${where}`;
}

/** Powód blokady remontu statku; null = można remontować. */
function repairBlock(ship: FleetShipDTO): string | null {
    const profile = expedition.value?.profile;
    const island = currentIsland.value;
    const type = expedition.value?.shipTypes.find(t => t.type === ship.type);
    if (!profile || !type) return 'wczytywanie…';
    if (!island) return 'dopłyń do portu';
    if (island.shipyardLevel < type.requiredShipyardLevel) return shipyardMissing(island, type.requiredShipyardLevel);
    const available = addResources(island.stockpile, profile.cargo);
    if (!covers(available, ship.repairCost)) return withSources(available, ship.repairCost);
    return null;
}

/** Warianty czekające na technologię — podpowiedź, gdy sam typ da się już budować. */
function lockedVariants(t: ShipTypeDTO): ShipVariantDTO[] {
    return buildBlock(t) === null ? t.variants.filter(v => !v.unlocked) : [];
}

async function onBuild(t: ShipTypeDTO, variant?: ShipVariantName) {
    const profileId = getProfileId();
    const island = currentIsland.value;
    if (!profileId || !island) return;
    busy.value = true;
    error.value = '';
    try {
        await buildShip(profileId, island.id, t.type, variant);
        expedition.value = await getExpedition(profileId);
    } catch (e: any) {
        error.value = e?.message ?? 'Budowa nie powiodła się';
    } finally {
        busy.value = false;
    }
}

/** Budynek osady na wyspie, przy której stoi kapitan (#80). */
async function onBuildBuilding(type: BuildingTypeName) {
    const profileId = getProfileId();
    const island = currentIsland.value;
    if (!profileId || !island) return;
    busy.value = true;
    error.value = '';
    try {
        await buildBuilding(profileId, island.id, type);
        expedition.value = await getExpedition(profileId);
    } catch (e: any) {
        error.value = e?.message ?? 'Budowa nie powiodła się';
    } finally {
        busy.value = false;
    }
}

/** Badanie w uczelni wyspy, przy której stoi kapitan (Epic G). */
async function onStartResearch(technology: TechnologyName) {
    const profileId = getProfileId();
    const island = currentIsland.value;
    if (!profileId || !island) return;
    busy.value = true;
    error.value = '';
    try {
        await startResearch(profileId, island.id, technology);
        expedition.value = await getExpedition(profileId);
    } catch (e: any) {
        error.value = e?.message ?? 'Badanie nie ruszyło';
    } finally {
        busy.value = false;
    }
}

/** Jedno dokowanie przenosi wszystkie wpisane surowce — 1 tik (#49). */
async function onTransfer(direction: 'load' | 'unload', amount: ResourcesDTO) {
    const profileId = getProfileId();
    const island = currentIsland.value;
    if (!profileId || !island || isEmpty(amount)) return;
    busy.value = true;
    error.value = '';
    try {
        await transferCargo(profileId, island.id, direction, amount);
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

// konwój w rejsie: flota z transportowcem (także uszkodzonym) bez sprawnego statku z bronią
const unescortedConvoy = computed(() => isUnescortedConvoy(expedition.value?.fleet ?? []));

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
.deposit { color: var(--text-2); }
.convoy-warn { margin: 0.5rem 0 0; font: 500 12px var(--font-mono); color: var(--warn); }
.squad-pick { list-style: none; padding: 0; margin: 0; width: 100%; display: flex; flex-wrap: wrap; gap: 6px 14px; }
.squad-pick label { cursor: pointer; color: var(--text-2); }
.squad-pick label.off { opacity: .45; cursor: not-allowed; }
.economy h2 { margin: 0 0 0.4rem; font-size: 1.05rem; }
.research h2 { margin: 0 0 0.4rem; font-size: 1.05rem; }
.research-tag { color: var(--info); text-decoration: none; }
.research-tag.paused { color: var(--warn); }
.eco { border-collapse: collapse; width: 100%; font-size: 13px; }
.eco th, .eco td { padding: 5px 8px; text-align: left; border-bottom: 1px solid var(--line-card); }
.eco thead th { font: 600 10px var(--font-mono); letter-spacing: .12em; text-transform: uppercase; color: var(--text-label); }
.eco tbody th { font-weight: 600; white-space: nowrap; }
.eco tr.here { background: var(--bg-card-active); }
.eco .dep-working { color: var(--info); }
.eco .dep-full { color: var(--warn); }
.eco .dep-idle { color: var(--text-muted); }
.res-dot { width: 10px; height: 10px; border-radius: 3px; display: inline-block; vertical-align: middle; }
.sea-grid { display: grid; gap: 3px; margin-top: 0.75rem; }
.sea-cell { position: relative; width: 1.7rem; height: 1.7rem; border: 0; border-radius: 4px; padding: 0; font-size: 0.95rem; line-height: 1; cursor: default; }
.sea-cell .badge { position: absolute; right: -2px; bottom: -3px; font-size: 0.6rem; }
.sea-cell .flag { position: absolute; left: -2px; top: -3px; font-size: 0.6rem; }
.sea-cell .attacked { position: absolute; right: -3px; top: -4px; font-size: 0.6rem; }
/* nazwa wyspy nad sektorem po najechaniu — natywny title pokazuje się z opóźnieniem
   i nie działa na nieaktywnych (niesąsiednich) sektorach */
.sea-cell .name {
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
.sea-cell.island:hover { z-index: 2; box-shadow: inset 0 0 0 2px var(--accent); }
.sea-cell.island:hover .name { opacity: 1; }
.counterattack { display: flex; flex-direction: column; gap: 0.3rem; border-color: var(--danger); }
.counterattack.defending { border-color: var(--info); }
.counterattack .full-flag { font: 600 12px var(--font-mono); color: var(--info); }
.last-counterattack { margin: -6px 0 14px; font: 500 12px var(--font-mono); color: var(--text-muted); }
.last-counterattack.lost { color: var(--warn); }
.warn { color: var(--warn); }
.sea-cell.fog { background: #0a1b23; box-shadow: inset 0 0 0 1px #12303a; }
.sea-cell.water { background: #0c2029; box-shadow: inset 0 0 0 1px #163540; }
.sea-cell.island { background: #3b3220; box-shadow: inset 0 0 0 1px #6b5a35; }
.sea-cell.player { background: #13404a; box-shadow: inset 0 0 0 2px var(--info); }
.sea-cell.sailable:not(:disabled) { cursor: pointer; outline: 1px dashed var(--info); outline-offset: -3px; }
.sea-cell.sailable.risk:not(:disabled) { outline-color: var(--warn); }
.sea-cell.sailable:not(:disabled):hover { filter: brightness(1.3); }
.sea-msg { margin: 0.5rem 0 0; color: var(--warn); }
.sea-theme { margin: 0 0 0.5rem; font-size: 0.85rem; }
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
.flag-tag { font: 600 10px var(--font-mono); color: var(--text); white-space: nowrap; margin-left: auto; }
.mode-tag { font: 600 10px var(--font-mono); letter-spacing: .1em; text-transform: uppercase; color: var(--text-muted); border: 1px solid var(--line-btn); border-radius: 999px; padding: 0.15rem 0.55rem; white-space: nowrap; }
.desc { color: var(--text-desc); margin: 0.4rem 0 0.5rem; }
.island-meta { display: flex; gap: 0.9rem; margin-bottom: 0.6rem; flex-wrap: wrap; font-size: 14px; }
.btn { padding: 9px 16px; background: var(--accent); color: var(--accent-ink); border: 0; border-radius: 10px; cursor: pointer; text-decoration: none; display: inline-block; font: 700 14px var(--font-head); }
.btn.small { padding: 5px 10px; font-size: 12px; border-radius: 8px; background: var(--bg-card-active); color: var(--text); border: 1px solid var(--line-btn); }
.btn[disabled] { background: var(--bg-disabled); color: var(--text-dim); cursor: not-allowed; }
.muted { color: var(--text-muted); font-size: 0.9rem; }
.err { color: var(--danger); }
</style>
