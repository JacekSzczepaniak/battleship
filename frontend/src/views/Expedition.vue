<template>
    <main class="expedition">
        <p v-if="loading" class="muted">Wczytywanie wyprawy…</p>
        <p v-if="error" class="alert err" role="alert">{{ error }}</p>

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
                <button class="btn btn-primary" :disabled="creating || newName.length === 0">
                    {{ creating ? 'Tworzenie…' : 'Wypłyń' }}
                </button>
            </form>
        </section>

        <template v-if="expedition">
            <!-- HUD: kapitan, surowce w ładowni, ładownia floty, tik -->
            <section class="hud">
                <div class="captain">
                    <div class="avatar">{{ expedition.profile.name.charAt(0).toUpperCase() }}</div>
                    <div>
                        <div class="captain-name"><strong>{{ expedition.profile.name }}</strong><span class="rank">{{ rankLabel(expedition.profile.rank) }}</span></div>
                        <div class="xp">
                            {{ expedition.profile.xp }} XP ·
                            <template v-if="expedition.profile.nextRank">do rangi {{ rankLabel(expedition.profile.nextRank.rank) }}: {{ expedition.profile.nextRank.xpNeeded }}</template>
                            <template v-else>najwyższa ranga</template>
                        </div>
                    </div>
                </div>
                <div class="sep"></div>
                <div class="pills" title="Surowce w ładowni floty">
                    <div v-for="r in RESOURCES" :key="r.key" class="pill">
                        <span class="res-dot" :style="{ background: r.color }"></span>
                        <b>{{ expedition.profile.cargo[r.key] }}</b>
                        <span>{{ r.label.toLowerCase() }}</span>
                    </div>
                </div>
                <div class="hold">
                    <div class="hold-head">
                        <span>Ładownia floty</span>
                        <span><b>{{ cargoUsed }}</b> / {{ expedition.profile.cargoCapacity }} · wolne {{ cargoFree }}</span>
                    </div>
                    <div class="hold-bar" role="progressbar" :aria-valuenow="cargoUsed" :aria-valuemax="expedition.profile.cargoCapacity">
                        <div v-for="s in cargoSegments" :key="s.key" :style="{ width: s.width + '%', background: s.color }"></div>
                    </div>
                </div>
                <div class="sep"></div>
                <div class="tick">
                    <div class="kicker">Tik</div>
                    <div class="tick-n">{{ expedition.world.tick }}</div>
                </div>
            </section>

            <!-- Baner: ostatnie zdarzenie z dziennika (#111) -->
            <section v-if="lastEntry" class="banner" :class="logTone(lastEntry)">
                <span class="signal"></span>
                <span class="banner-tick">TIK {{ lastEntry.tick }}</span>
                <span class="banner-text">{{ logText(lastEntry, expedition.world.tick) }}</span>
                <button class="btn btn-small btn-secondary" @click="openLog">Dziennik →</button>
            </section>

            <!-- Kontratak w toku (#78): stan do decyzji, nie zdarzenie — zawsze na wierzchu -->
            <section v-if="counterattack.pending" class="alert counterattack" :class="{ defending: counterattack.pending.defenseStarted }">
                <strong>{{ counterattack.pending.defenseStarted ? '🛡️' : '⚔️' }} {{ pendingLabel(counterattack.pending) }}</strong>
                <span v-if="pendingRoute" class="route" :class="{ late: !pendingRoute.inTime }">🧭 {{ pendingRoute.text }}</span>
                <span v-if="counterattack.pending.fullFlag" class="full-flag">🚩 {{ FULL_FLAG_BONUS }}</span>
                <span v-if="!counterattack.pending.defenseStarted" class="muted">{{ defenseHint(counterattack.pending) }}</span>
            </section>

            <!-- Trwająca bitwa -->
            <section v-if="pendingBattle" class="alert pending">
                <span>⚔️ Trwa bitwa {{ pendingBattle.kind === 'guardian' ? 'ze strażnikiem:' : 'o' }} <strong>{{ pendingBattle.islandName }}</strong>.</span>
                <router-link class="btn btn-small btn-primary" :to="{ name: 'game', params: { id: pendingBattle.gameId } }">
                    Wróć do bitwy
                </router-link>
            </section>

            <div class="main">
                <SeaMap :expedition="expedition" :busy="busy" :note="seaMsg" :gate-status="gateStatus" @sail="onSail" />

                <!-- Panel kontekstowy: zakładka w hashu adresu (#wyspa, #flota, #badania, #dziennik) -->
                <section ref="panelEl" class="panel">
                    <nav class="tabs" role="tablist" aria-label="Panel rejsu">
                        <button
                            v-for="t in tabs"
                            :key="t.id"
                            class="tab"
                            role="tab"
                            :aria-selected="activeTab === t.id"
                            :class="{ on: activeTab === t.id }"
                            @click="setTab(t.id)"
                        >
                            {{ t.label }}<span v-if="t.badge" class="badge" :class="{ live: t.live }">{{ t.badge }}</span>
                        </button>
                    </nav>

                    <!-- WYSPA: miejsce floty — wyspa w porcie, brama albo przegląd akwenu na morzu -->
                    <div v-if="activeTab === 'wyspa'" class="tab-body" role="tabpanel">
                        <template v-if="currentIsland">
                            <div>
                                <div class="tags">
                                    <span v-if="currentIsland.owned" class="tag own">Twoja osada</span>
                                    <span v-else-if="!currentIsland.unlocked" class="tag">🔒 od rangi {{ rankLabel(currentIsland.requiredRank) }}</span>
                                    <span v-else class="tag">Wyspa do zdobycia</span>
                                    <span class="tag">Sektor {{ sectorLabel(currentIsland.position!) }} · zadokowano</span>
                                    <span class="tag">{{ currentIsland.mode === 'fun' ? 'bronie specjalne' : 'klasyczna bitwa' }}</span>
                                </div>
                                <h2 class="place-name">{{ currentIsland.name }}</h2>
                                <p class="desc">{{ currentIsland.description }}</p>
                            </div>

                            <!-- Bitwa o wyspę albo obrona przed kontratakiem -->
                            <div class="block">
                                <div class="kicker">{{ currentIsland.owned ? 'Obrona' : 'Bitwa' }}</div>
                                <div v-if="!currentIsland.owned" class="meta">
                                    <span>🏆 +{{ currentIsland.xpWin }} XP, {{ formatResources(currentIsland.lootWin, 'bez łupów') }}</span>
                                    <span class="muted">porażka: +{{ currentIsland.xpLoss }} XP, {{ formatResources(currentIsland.lootLoss, 'bez łupów') }}</span>
                                    <span class="muted">plansza {{ currentIsland.board.w }}×{{ currentIsland.board.h }}<template v-if="currentIsland.wins || currentIsland.losses"> · bilans: {{ currentIsland.wins }}W / {{ currentIsland.losses }}P</template></span>
                                </div>
                                <div v-if="currentIsland.unlocked && (!currentIsland.owned || canDefend(currentIsland, counterattack.pending))" class="squad">
                                    <span :class="{ over: activeCells > currentIsland.squadLimit }">
                                        skład: {{ squadCells(squadFor(currentIsland)) }} / {{ currentIsland.squadLimit }} pól
                                        <template v-if="activeCells > currentIsland.squadLimit"> · flota ma {{ activeCells }} pól — część zostanie w porcie</template>
                                        <template v-else-if="!squadPick && leavesShipsInPort(currentIsland)"> · transportowiec płynie tylko wybrany ręcznie</template>
                                    </span>
                                    <button v-if="activeCells > currentIsland.squadLimit || leavesShipsInPort(currentIsland)" class="btn btn-small btn-secondary" @click="togglePicker(currentIsland)">
                                        {{ squadPick ? 'skład domyślny' : 'wybierz skład' }}
                                    </button>
                                    <ul v-if="squadPick" class="squad-pick">
                                        <li v-for="ship in activeShips" :key="ship.id">
                                            <label :class="{ off: !squadPick.has(ship.id) && squadCells(squadFor(currentIsland)) + ship.length > currentIsland.squadLimit }">
                                                <input
                                                    type="checkbox"
                                                    :checked="squadPick.has(ship.id)"
                                                    :disabled="!squadPick.has(ship.id) && squadCells(squadFor(currentIsland)) + ship.length > currentIsland.squadLimit"
                                                    @change="toggleShip(ship.id)"
                                                />
                                                {{ SHIP_ICONS[ship.type] }} {{ fleetShipLabel(ship) }} · {{ fieldsLabel(ship.length) }}
                                            </label>
                                        </li>
                                    </ul>
                                    <span v-if="convoyBlocked(currentIsland)" class="over">⚠️ konwój bez eskorty nie zaatakuje wyspy — dobierz kuter, niszczyciel albo lotniskowiec</span>
                                </div>
                                <!-- wyspa pod banderą nie jest areną: bitwa tylko w obronie przed kontratakiem (#78) -->
                                <template v-if="currentIsland.owned">
                                    <button
                                        v-if="canDefend(currentIsland, counterattack.pending)"
                                        class="btn btn-primary act"
                                        :disabled="starting !== null || pendingBattle !== null || squadFor(currentIsland).length === 0 || convoyBlocked(currentIsland)"
                                        @click="onStartBattle(currentIsland)"
                                    >
                                        {{ starting === currentIsland.id ? 'Stawianie żagli…' : `🛡️ Broń wyspy — uderzenie za ${ticksLabel(counterattack.pending!.ticksLeft)}` }}
                                    </button>
                                    <p v-else-if="counterattack.pending?.island === currentIsland.id" class="muted">🛡️ Obrona trwa — wróć do bitwy, by ją rozstrzygnąć.</p>
                                    <p v-else class="muted">🚩 Wyspa pod Twoją banderą — walczysz tu tylko w obronie przed kontratakiem.</p>
                                </template>
                                <button
                                    v-else-if="currentIsland.unlocked"
                                    class="btn btn-primary act"
                                    :disabled="starting !== null || pendingBattle !== null || squadFor(currentIsland).length === 0 || convoyBlocked(currentIsland)"
                                    @click="onStartBattle(currentIsland)"
                                >
                                    {{ starting === currentIsland.id ? 'Stawianie żagli…' : '⚔️ Bitwa' }}
                                </button>
                                <p v-else class="muted">Wymaga rangi: {{ rankLabel(currentIsland.requiredRank) }}</p>
                            </div>

                            <CargoPanel
                                :island="currentIsland"
                                :cargo="expedition.profile.cargo"
                                :capacity="expedition.profile.cargoCapacity"
                                :busy="busy"
                                @transfer="onTransfer"
                            />

                            <div v-if="islandHint" class="hint-box">{{ islandHint }}</div>

                            <div v-if="currentIsland.owned" class="block">
                                <div class="kicker">Budynki</div>
                                <SettlementPanel
                                    :island="currentIsland"
                                    :building-types="expedition.buildingTypes"
                                    :available="addResources(currentIsland.stockpile, expedition.profile.cargo)"
                                    :busy="busy"
                                    @build="onBuildBuilding"
                                />
                            </div>

                            <div id="stocznia" class="block">
                                <div class="kicker">
                                    Stocznia
                                    <template v-if="currentIsland.shipyardLevel > 0">· poziom {{ currentIsland.shipyardLevel }}{{ currentIsland.owned && currentIsland.buildings.includes('rozbudowa-stoczni') ? ', z rozbudową' : '' }}</template>
                                </div>
                                <p v-if="currentIsland.shipyardLevel === 0" class="muted">Bez stoczni — tratwę skleisz z desek na każdej wyspie.</p>
                                <ul class="ships">
                                    <li v-for="t in expedition.shipTypes" :key="t.type" class="ship">
                                        <span class="ship-name">{{ SHIP_ICONS[t.type] }} {{ SHIP_LABELS[t.type] }} <span class="muted">({{ t.length }}-masztowiec · ładownia {{ t.cargoCapacity }}{{ t.armed ? '' : ' · bez broni' }})</span></span>
                                        <!-- wariant wybiera się przy budowie i zostaje na stałe (#99) -->
                                        <template v-if="t.variants.length">
                                            <button
                                                v-for="v in t.variants"
                                                :key="v.variant"
                                                class="btn btn-small btn-secondary"
                                                :disabled="busy || buildBlock(t, v) !== null"
                                                :title="buildBlock(t, v) ?? `nośnik: ${carriedWeapon(t.length, VARIANTS[v.variant].w)} · wariant stały`"
                                                @click="onBuild(t, v.variant)"
                                            >
                                                Buduj {{ SHIP_VARIANT_LABELS[v.variant] }} · {{ carriedWeapon(t.length, VARIANTS[v.variant].w) }} ({{ formatResources(t.buildCost) }})
                                            </button>
                                        </template>
                                        <button
                                            v-else
                                            class="btn btn-small btn-secondary"
                                            :disabled="busy || buildBlock(t) !== null"
                                            :title="buildBlock(t) ?? ''"
                                            @click="onBuild(t)"
                                        >
                                            Buduj ({{ formatResources(t.buildCost) }})
                                        </button>
                                        <span v-if="buildBlock(t)" class="why">{{ buildBlock(t) }}</span>
                                        <span v-for="v in lockedVariants(t)" :key="v.variant" class="why">
                                            {{ SHIP_VARIANT_LABELS[v.variant] }}: po zbadaniu „{{ TECH_LABELS[v.requiredTechnology!] }}”
                                        </span>
                                    </li>
                                </ul>
                                <p class="muted">Remont uszkodzonych statków: zakładka Flota.</p>
                            </div>
                        </template>

                        <!-- Brama akwenu (#107): położenie, powód blokady, strażnik, przejście -->
                        <template v-else-if="currentGate">
                            <div>
                                <div class="tags">
                                    <span class="tag" :class="{ open: currentGate.open }">{{ gateStatus(currentGate) }}</span>
                                    <span class="tag">Sektor {{ sectorLabel(currentGate.position!) }} · brama akwenu</span>
                                </div>
                                <h2 class="place-name">{{ currentGate.name }} → {{ currentGate.to.name }}</h2>
                                <p class="desc">Za bramą: {{ themeText(currentGate.to.theme) }}</p>
                            </div>
                            <div v-if="currentGate.guardian && !currentGate.guardian.defeated" class="block">
                                <div class="kicker">Strażnik</div>
                                <p class="desc">⚔️ <strong>{{ currentGate.guardian.name }}.</strong> {{ currentGate.guardian.description }}</p>
                                <div class="meta">
                                    <span>🏆 +{{ currentGate.guardian.xpWin }} XP, {{ formatResources(currentGate.guardian.lootWin) }} do ładowni</span>
                                    <span class="muted">porażka: +{{ currentGate.guardian.xpLoss }} XP, {{ formatResources(currentGate.guardian.lootLoss, 'bez łupów') }}</span>
                                    <span class="muted">flota: {{ guardianFleet(currentGate.guardian) }} ({{ currentGate.guardian.fleetCells }} pól) · plansza {{ currentGate.guardian.board.w }}×{{ currentGate.guardian.board.h }} · bronie specjalne</span>
                                    <span v-if="currentGate.guardian.wins || currentGate.guardian.losses" class="muted">bilans: {{ currentGate.guardian.wins }}W / {{ currentGate.guardian.losses }}P</span>
                                </div>
                                <p class="muted">Łup trafia do ładowni — przy bramie nie ma magazynu, nadmiar ponad pojemność przepada.</p>
                                <template v-if="currentGate.blocker === 'guardian'">
                                    <div class="squad">
                                        <span :class="{ over: activeCells > currentGate.guardian.squadLimit }">
                                            skład: {{ squadCells(squadFor(currentGate.guardian)) }} / {{ currentGate.guardian.squadLimit }} pól
                                            <template v-if="activeCells > currentGate.guardian.squadLimit"> · flota ma {{ activeCells }} pól — część zostanie w porcie</template>
                                        </span>
                                        <button v-if="activeCells > currentGate.guardian.squadLimit || leavesShipsInPort(currentGate.guardian)" class="btn btn-small btn-secondary" @click="togglePicker(currentGate.guardian)">
                                            {{ squadPick ? 'skład domyślny' : 'wybierz skład' }}
                                        </button>
                                        <ul v-if="squadPick" class="squad-pick">
                                            <li v-for="ship in activeShips" :key="ship.id">
                                                <label :class="{ off: !squadPick.has(ship.id) && squadCells(squadFor(currentGate.guardian)) + ship.length > currentGate.guardian.squadLimit }">
                                                    <input
                                                        type="checkbox"
                                                        :checked="squadPick.has(ship.id)"
                                                        :disabled="!squadPick.has(ship.id) && squadCells(squadFor(currentGate.guardian)) + ship.length > currentGate.guardian.squadLimit"
                                                        @change="toggleShip(ship.id)"
                                                    />
                                                    {{ SHIP_ICONS[ship.type] }} {{ fleetShipLabel(ship) }} · {{ fieldsLabel(ship.length) }}
                                                </label>
                                            </li>
                                        </ul>
                                        <span v-if="convoyBlocked(currentGate.guardian)" class="over">⚠️ konwój bez eskorty nie stanie do bitwy — dobierz kuter, niszczyciel albo lotniskowiec</span>
                                    </div>
                                    <button
                                        class="btn btn-primary act"
                                        :disabled="starting !== null || pendingBattle !== null || squadFor(currentGate.guardian).length === 0 || convoyBlocked(currentGate.guardian)"
                                        @click="onStartGuardian(currentGate)"
                                    >
                                        {{ starting === currentGate.id ? 'Stawianie żagli…' : '⚔️ Bitwa ze strażnikiem' }}
                                    </button>
                                </template>
                                <p v-else-if="currentGate.blocker" class="muted">🔒 {{ gateBlockText(currentGate) }}</p>
                            </div>
                            <div v-if="currentGate.open" class="block">
                                <div class="kicker">Przejście</div>
                                <button class="btn btn-primary act" :disabled="busy || pendingBattle !== null" @click="onPassGate(currentGate)">
                                    ⛵ Przepłyń na {{ currentGate.to.name }} (1 tik)
                                </button>
                            </div>
                        </template>

                        <!-- Na morzu: przegląd akwenu -->
                        <template v-else>
                            <div>
                                <div class="tags"><span class="tag">Sektor {{ sectorLabel(expedition.world.position) }} · pełne morze</span></div>
                                <h2 class="place-name">{{ expedition.world.sea.name }}</h2>
                                <p class="desc">Dopłyń na wyspę, żeby walczyć, przeładować surowce, budować i badać. Brama prowadzi na inny akwen.</p>
                            </div>
                        </template>

                        <!-- Przegląd akwenu: na morzu wprost, w porcie i przy bramie zwinięty -->
                        <component :is="currentIsland || currentGate ? 'details' : 'div'" class="overview">
                            <summary v-if="currentIsland || currentGate">Wyspy i bramy akwenu</summary>
                            <div v-if="expedition.world.flags.length > 1" class="block">
                                <div class="kicker">Bandery na akwenach</div>
                                <ul class="flags-by-sea">
                                    <li v-for="f in expedition.world.flags" :key="f.sea" :class="{ full: f.fullFlag }">
                                        {{ f.name }}: {{ f.owned }}/{{ f.total }} wysp<span v-if="f.fullFlag"> · 🚩 pełna bandera</span>
                                    </li>
                                </ul>
                                <p class="muted small">Pełna bandera działa przy obronie wysp swojego akwenu.</p>
                            </div>
                            <div class="block">
                                <div class="kicker">Bramy akwenu</div>
                                <ul class="gates">
                                    <li v-for="gate in expedition.world.gates" :key="gate.id" :class="{ open: gate.open }">
                                        <div class="gate-name">🌀 {{ gate.name }} → {{ gate.to.name }} <span class="tag" :class="{ open: gate.open }">{{ gateStatus(gate) }}</span></div>
                                        <div class="muted small">
                                            {{ gate.position ? (gate.present ? 'flota na sektorze bramy' : `sektor ${sectorLabel(gate.position)}`) : `położenie nieznane — ${EDGE_LABELS[gate.edge]} krawędź mapy` }}
                                            · {{ gate.open ? 'przejście 1 tik' : gateBlockText(gate) }}
                                            · za bramą: {{ themeText(gate.to.theme) }}
                                        </div>
                                    </li>
                                </ul>
                            </div>
                            <div v-if="discoveredIslands.length" class="block">
                                <div class="kicker">Odkryte wyspy</div>
                                <div class="eco-wrap">
                                    <table class="eco">
                                        <thead><tr><th>wyspa</th><th>stocznia</th><th>złoże</th><th>magazyn</th></tr></thead>
                                        <tbody>
                                            <tr v-for="island in discoveredIslands" :key="island.id" :class="{ here: island.present, locked: !island.unlocked }">
                                                <th>
                                                    {{ island.present ? '📍' : '' }}{{ island.unlocked ? '' : '🔒' }} {{ island.name }}<span v-if="island.owned" title="pod Twoją banderą"> 🚩</span><span v-if="counterattack.pending?.island === island.id" :title="pendingLabel(counterattack.pending!)"> ⚔️</span>
                                                    <small class="muted">{{ island.position ? sectorLabel(island.position) : '' }}{{ island.unlocked ? '' : ` · od rangi ${rankLabel(island.requiredRank)}` }}{{ island.wins || island.losses ? ` · ${island.wins}W/${island.losses}P` : '' }}</small>
                                                </th>
                                                <td>{{ island.shipyardLevel > 0 ? `poz. ${island.shipyardLevel}` : '—' }}</td>
                                                <td :class="`dep-${depositState(island)}`">{{ island.deposit ? depositLabel(island) : '—' }}</td>
                                                <td>{{ formatResources(island.stockpile, 'pusto') }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <p class="muted small">Urobek złoża czeka w magazynie wyspy — dopłyń, załaduj i zawieź do stoczni.</p>
                            </div>
                        </component>
                    </div>

                    <!-- FLOTA -->
                    <div v-else-if="activeTab === 'flota'" class="tab-body" role="tabpanel">
                        <FleetPanel
                            :fleet="expedition.fleet"
                            :ship-types="expedition.shipTypes"
                            :busy="busy"
                            :unescorted-convoy="unescortedConvoy"
                            :repair-block="repairBlock"
                            @repair="onRepair"
                        />
                    </div>

                    <!-- BADANIA -->
                    <div v-else-if="activeTab === 'badania'" class="tab-body" role="tabpanel">
                        <TechTreePanel
                            :research="expedition.research"
                            :available="currentIsland ? addResources(currentIsland.stockpile, expedition.profile.cargo) : null"
                            :busy="busy"
                            @research="onStartResearch"
                        />
                    </div>

                    <!-- DZIENNIK: najnowsze na górze -->
                    <div v-else class="tab-body" role="tabpanel">
                        <ol v-if="expedition.log.length" class="log">
                            <li v-for="(entry, i) in expedition.log" :key="`${entry.tick}-${i}`" :class="logTone(entry)">
                                <span class="log-tick">TIK {{ entry.tick }}</span>
                                <span class="log-text">{{ logText(entry, expedition.world.tick) }}</span>
                            </li>
                        </ol>
                        <p v-else class="muted">Dziennik jest pusty. Zapisują się tu sztormy, zdobyte i utracone wyspy, kontrataki, ukończone badania, budowy i przejścia przez bramy.</p>
                        <p class="muted small">Dziennik pamięta {{ LOG_LIMIT }} ostatnich zdarzeń.</p>
                    </div>
                </section>
            </div>
        </template>
    </main>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    buildBuilding, buildShip, createProfile, getExpedition, passGate, repairShip, sail, settleBattle, startGuardianBattle, startIslandBattle,
    startResearch, transferCargo,
    RANK_LABELS, RANK_ORDER, SHIP_ICONS, SHIP_LABELS, SHIP_VARIANT_LABELS, TECH_LABELS, fleetShipLabel,
    type BuildingTypeName, type TechnologyName, type ExpeditionDTO, type FleetShipDTO, type GateDTO, type GuardianDTO, type IslandDTO,
    type RankName, type ResourcesDTO, type SailResultDTO, type SeaThemeDTO, type ShipTypeDTO, type ShipVariantDTO, type ShipVariantName,
} from '../api/expeditionApi';
import { ApiError } from '../api/http';
import {
    clearCurrentBattle, clearProfileId, getCurrentBattle, getProfileId,
    setCurrentBattle, setProfileId, type CurrentBattle,
} from '../lib/expeditionSession';
import { defaultSquad, isUnescortedConvoy, squadCells } from '../lib/squad';
import { VARIANTS, carriedWeapon, columnLabel, fieldsLabel } from '../lib/ships';
import { depositLabel, depositState, whereToFind } from '../lib/economy';
import { logText, logTone } from '../lib/eventLog';
import CargoPanel from '../components/CargoPanel.vue';
import FleetPanel from '../components/FleetPanel.vue';
import SeaMap from '../components/SeaMap.vue';
import SettlementPanel from '../components/SettlementPanel.vue';
import TechTreePanel from '../components/TechTreePanel.vue';
import { canDefend, defenseHint, FULL_FLAG_BONUS, pendingLabel, routeWarning, ticksLabel } from '../lib/counterattack';
import {
    RESOURCES, addResources, covers, formatResources, isEmpty, missing, totalResources,
} from '../lib/resources';

// jak CaptainProfile::LOG_LIMIT
const LOG_LIMIT = 50;

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

function rankLabel(rank: RankName): string {
    return RANK_LABELS[rank] ?? rank;
}

// --- HUD: ładownia floty ---

const cargoUsed = computed(() => totalResources(expedition.value?.profile.cargo ?? { wood: 0, steel: 0, gold: 0 }));
const cargoFree = computed(() => Math.max(0, (expedition.value?.profile.cargoCapacity ?? 0) - cargoUsed.value));

/** Pasek ładowni: udział każdego surowca w pojemności, kolorami surowców. */
const cargoSegments = computed(() => {
    const profile = expedition.value?.profile;
    return RESOURCES.map(r => ({
        key: r.key,
        color: r.color,
        width: profile && profile.cargoCapacity > 0 ? (profile.cargo[r.key] / profile.cargoCapacity) * 100 : 0,
    }));
});

// --- Panel z zakładkami (#111): aktywna zakładka w hashu adresu ---

type TabId = 'wyspa' | 'flota' | 'badania' | 'dziennik';
const TAB_IDS: TabId[] = ['wyspa', 'flota', 'badania', 'dziennik'];

const activeTab = ref<TabId>('flota');
const panelEl = ref<HTMLElement | null>(null);

/** Zakładka z hashu; stary link „#stocznia” (port, wynik bitwy) prowadzi do wyspy. */
function tabFromHash(hash: string): TabId | null {
    const id = hash.replace(/^#/, '');
    if (id === 'stocznia') return 'wyspa';
    return (TAB_IDS as string[]).includes(id) ? id as TabId : null;
}

function setTab(id: TabId) {
    activeTab.value = id;
    if (route.hash !== `#${id}`) router.replace({ hash: `#${id}` });
}

// wstecz/naprzód w historii przeglądarki zmienia hash
watch(() => route.hash, hash => {
    const id = tabFromHash(hash);
    if (id) activeTab.value = id;
});

/** Domyślna zakładka: w porcie albo na sektorze bramy — miejsce floty, na morzu — flota. */
function defaultTab(): TabId {
    return currentIsland.value || currentGate.value ? 'wyspa' : 'flota';
}

const researchDone = computed(() => expedition.value?.research.technologies.filter(t => t.state === 'researched').length ?? 0);

const tabs = computed(() => {
    const exp = expedition.value;
    const current = exp?.research.current ?? null;
    return [
        { id: 'wyspa' as TabId, label: currentIsland.value?.name ?? currentGate.value?.name ?? 'Akwen', badge: '', live: false },
        { id: 'flota' as TabId, label: 'Flota', badge: String(exp?.fleet.length ?? ''), live: false },
        { id: 'badania' as TabId, label: 'Badania', badge: `${researchDone.value}/${exp?.research.technologies.length ?? 0}`, live: !!current && !current.paused },
        { id: 'dziennik' as TabId, label: 'Dziennik', badge: '', live: false },
    ];
});

const lastEntry = computed(() => expedition.value?.log[0] ?? null);

function openLog() {
    setTab('dziennik');
    panelEl.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// --- Wolne morze: mapa, żegluga, port w doku ---

const busy = ref(false);
// wynik rejsu poza dziennikiem: kartografia (sztorm trafia do dziennika i banera)
const seaMsg = ref('');

function rankAtLeast(rank: RankName, required: RankName): boolean {
    return RANK_ORDER.indexOf(rank) >= RANK_ORDER.indexOf(required);
}

/** Wyspa, w której porcie stoi kapitan (stocznia i bitwa tylko tutaj). */
const currentIsland = computed<IslandDTO | null>(
    () => expedition.value?.islands.find(i => i.present) ?? null
);

/** Brama na sektorze floty (#107). */
const currentGate = computed<GateDTO | null>(
    () => expedition.value?.world.gates.find(g => g.present) ?? null
);

const placeKey = computed(() => currentIsland.value?.id ?? currentGate.value?.id ?? null);

/** Rejs albo brama: świeży stan, a dokowanie przy wyspie lub wpłynięcie na sektor bramy przełącza panel na miejsce floty. */
async function reloadAfterVoyage(profileId: string) {
    const before = placeKey.value;
    expedition.value = await getExpedition(profileId);
    if (placeKey.value && placeKey.value !== before) setTab('wyspa');
}

const counterattack = computed(() => expedition.value?.counterattack ?? { pending: null, last: null });
// cel na innym akwenie niż flota (#109): czas do uderzenia wobec drogi przez bramę
const pendingRoute = computed(() => counterattack.value.pending ? routeWarning(counterattack.value.pending) : null);

const discoveredIslands = computed<IslandDTO[]>(
    () => expedition.value?.islands.filter(i => i.discovered) ?? []
);

/** Podpowiedź pod przeładunkiem: złoże wyspy i fort na wyspie bez niego. */
const islandHint = computed(() => {
    const island = currentIsland.value;
    if (!island) return '';
    const parts: string[] = [];
    parts.push(isEmpty(island.stockpile) ? 'Magazyn wyspy jest pusty.' : `W magazynie: ${formatResources(island.stockpile)}.`);
    if (island.deposit) parts.push(`Złoże: ${depositLabel(island)}.`);
    if (island.owned && !island.buildings.includes('fort') && island.buildBlockers.fort !== 'site') {
        parts.push('Fort obroni tę wyspę przed utratą, gdy kontratak uderzy pod Twoją nieobecność.');
    }
    return parts.join(' ');
});

// motyw akwenu (Epic I): bazowy sztorm akwenu, promień odkrywania, mnożnik złóż
function themeText(theme: SeaThemeDTO | null): string {
    if (!theme) return 'nieznane wody';
    const visibility = theme.visibility === 0 ? 'tylko sektor floty' : `${theme.visibility} ${theme.visibility === 1 ? 'sektor' : 'sektory'} wokół floty`;
    return `sztorm ${theme.stormChance}% · widoczność: ${visibility} · złoża ×${theme.depositMultiplier}`;
}

// --- Bramy akwenów (#107) ---

const EDGE_LABELS: Record<GateDTO['edge'], string> = { north: 'północna', east: 'wschodnia', south: 'południowa', west: 'zachodnia' };

/** Sektor w notacji mapy: kolumna literą, wiersz od 1 (jak osie planszy). */
function sectorLabel(pos: { x: number; y: number }): string {
    return `${columnLabel(pos.x)}${pos.y + 1}`;
}

function gateStatus(gate: GateDTO): string {
    if (gate.open) return '🔓 otwarta';
    return gate.blocker === 'rank' && gate.guardian ? `🔒 od rangi ${rankLabel(gate.guardian.requiredRank)}` : '🔒 strzeżona';
}

/** Powód zamknięcia bramy pełnym zdaniem; kolejne warunki (#108) dopisują się tutaj. */
function gateBlockText(gate: GateDTO): string {
    switch (gate.blocker) {
        case null: return 'otwarta';
        case 'rank': return gate.guardian ? `strażnik przyjmie bitwę od rangi ${rankLabel(gate.guardian.requiredRank)}` : 'zamknięta rangą';
        case 'guardian': return gate.guardian ? `strzeże jej ${gate.guardian.name} — pokonaj go na sektorze bramy` : 'strzeżona';
    }
}

/** „Lotniskowiec, 2× Niszczyciel, 2× Kuter, 3× Tratwa”. */
function guardianFleet(guardian: GuardianDTO): string {
    const counts = new Map<string, number>();
    guardian.fleet.forEach(t => counts.set(SHIP_LABELS[t], (counts.get(SHIP_LABELS[t]) ?? 0) + 1));
    return [...counts].map(([name, n]) => (n > 1 ? `${n}× ${name}` : name)).join(', ');
}

async function onSail(x: number, y: number) {
    const profileId = getProfileId();
    if (!profileId) return;
    busy.value = true;
    error.value = '';
    try {
        const result = await sail(profileId, x, y);
        seaMsg.value = voyageMessage(result).join(' · ');
        await reloadAfterVoyage(profileId);
    } catch (e: any) {
        error.value = e?.message ?? 'Żegluga nie powiodła się';
    } finally {
        busy.value = false;
    }
}

/** Kartografia po wpłynięciu na sektor — rejsem albo przez bramę. Sztorm opisuje dziennik. */
function voyageMessage(result: SailResultDTO): string[] {
    const parts: string[] = [];
    if (result.cartography > result.cartographyLost) parts.push(`+${result.cartography - result.cartographyLost} drewna za nowe mapy`);
    if (result.cartographyLost > 0) parts.push(`ładownia pełna — ${result.cartographyLost} drewna przepadło`);
    return parts;
}

/** Przejście przez otwartą bramę: 1 tik, flota ląduje na sektorze bramy drugiego akwenu. */
async function onPassGate(gate: GateDTO) {
    const profileId = getProfileId();
    if (!profileId) return;
    busy.value = true;
    error.value = '';
    try {
        const result = await passGate(profileId, gate.id);
        seaMsg.value = voyageMessage(result).join(' · ');
        squadPick.value = null;
        await reloadAfterVoyage(profileId);
    } catch (e: any) {
        error.value = e?.message ?? 'Przejście przez bramę nie powiodło się';
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

/** Akcja w porcie: wywołanie API i świeży stan wyprawy (HUD, dziennik, baner). */
async function inPort(action: (profileId: string, island: IslandDTO) => Promise<unknown>, failure: string) {
    const profileId = getProfileId();
    const island = currentIsland.value;
    if (!profileId || !island) return;
    busy.value = true;
    error.value = '';
    try {
        await action(profileId, island);
        expedition.value = await getExpedition(profileId);
    } catch (e: any) {
        error.value = e?.message ?? failure;
    } finally {
        busy.value = false;
    }
}

function onBuild(t: ShipTypeDTO, variant?: ShipVariantName) {
    return inPort((profileId, island) => buildShip(profileId, island.id, t.type, variant), 'Budowa nie powiodła się');
}

/** Budynek osady na wyspie, przy której stoi kapitan (#80). */
function onBuildBuilding(type: BuildingTypeName) {
    return inPort((profileId, island) => buildBuilding(profileId, island.id, type), 'Budowa nie powiodła się');
}

/** Badanie w uczelni wyspy, przy której stoi kapitan (Epic G). */
function onStartResearch(technology: TechnologyName) {
    return inPort((profileId, island) => startResearch(profileId, island.id, technology), 'Badanie nie ruszyło');
}

/** Jedno przeniesienie = jedno dokowanie = 1 tik (#49). */
function onTransfer(direction: 'load' | 'unload', amount: ResourcesDTO) {
    if (isEmpty(amount)) return;
    return inPort((profileId, island) => transferCargo(profileId, island.id, direction, amount), 'Przeładunek nie powiódł się');
}

function onRepair(ship: FleetShipDTO) {
    return inPort((profileId, island) => repairShip(profileId, island.id, ship.id), 'Remont nie powiódł się');
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
        const hash = route.hash;
        setTab(tabFromHash(hash) ?? defaultTab());
        // wejście z portu „Stocznia” (#stocznia) — przewiń do sekcji po wyrenderowaniu
        if (hash === '#stocznia') {
            await nextTick();
            document.getElementById('stocznia')?.scrollIntoView({ block: 'start' });
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
        setTab(defaultTab());
    } catch (e: any) {
        error.value = e?.message ?? 'Nie udało się utworzyć profilu';
    } finally {
        creating.value = false;
    }
}

// --- skład bitewny (#73): domyślny dobór albo ręczny wybór, gdy flota przekracza limit ---

// cel bitwy z limitem pól składu: wyspa albo strażnik bramy (#107)
type SquadTarget = { squadLimit: number };

const squadPick = ref<Set<string> | null>(null);
const activeShips = computed(() => (expedition.value?.fleet ?? []).filter(s => !s.damaged));
const activeCells = computed(() => squadCells(activeShips.value));

// konwój w rejsie: flota z transportowcem (także uszkodzonym) bez sprawnego statku z bronią
const unescortedConvoy = computed(() => isUnescortedConvoy(expedition.value?.fleet ?? []));

/** Dobór domyślny zostawia w porcie statki spoza priorytetu (transportowiec), choć jest miejsce. */
function leavesShipsInPort(island: SquadTarget): boolean {
    return defaultSquad(expedition.value?.fleet ?? [], island.squadLimit).length < activeShips.value.length;
}

/** Konwój bez eskorty nie atakuje — także gdy sprawne są same transportowce (dobór domyślny jest pusty). */
function convoyBlocked(island: SquadTarget): boolean {
    const squad = squadFor(island);
    return isUnescortedConvoy(squad.length ? squad : activeShips.value);
}

function squadFor(island: SquadTarget): FleetShipDTO[] {
    if (squadPick.value) return activeShips.value.filter(s => squadPick.value!.has(s.id));
    return defaultSquad(expedition.value?.fleet ?? [], island.squadLimit);
}

function togglePicker(island: SquadTarget) {
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

async function onStartGuardian(gate: GateDTO) {
    const profileId = getProfileId();
    if (!profileId || !gate.guardian) return;
    starting.value = gate.id;
    error.value = '';
    try {
        const shipIds = squadPick.value ? [...squadPick.value] : undefined;
        const game = await startGuardianBattle(profileId, gate.id, shipIds);
        setCurrentBattle({ profileId, gameId: game.id, islandId: gate.id, islandName: gate.guardian.name, kind: 'guardian' });
        await router.push({ name: 'place-fleet', params: { id: game.id } });
    } catch (e: any) {
        error.value = e?.message ?? 'Nie udało się rozpocząć bitwy';
        starting.value = null;
    }
}
</script>

<style scoped>
.expedition { max-width: 1440px; margin: 0 auto; display: flex; flex-direction: column; gap: 16px; }
h2, h3 { font-family: var(--font-head); }
.intro h2 { margin-top: 0; }
.name-form { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
.name-form input { padding: 10px 12px; border: 1px solid var(--line-btn); border-radius: 10px; background: var(--bg-panel); color: var(--text); }
.name-form .btn { padding: 10px 18px; }

/* HUD */
.hud { display: flex; align-items: center; gap: 16px 22px; flex-wrap: wrap; padding: 16px 22px; border-radius: 16px; background: var(--bg-frame-a); border: 1px solid var(--line-card); }
.captain { display: flex; align-items: center; gap: 12px; }
.avatar { width: 42px; height: 42px; border-radius: 11px; background: #12303b; border: 1px solid var(--line-strong); display: flex; align-items: center; justify-content: center; font: 700 18px var(--font-head); color: var(--info); }
.captain-name { display: flex; align-items: baseline; gap: 9px; font: 700 17px var(--font-head); }
.rank { font: 600 10px var(--font-mono); color: var(--accent); letter-spacing: .18em; text-transform: uppercase; }
.xp { font: 500 11px var(--font-mono); color: var(--text-muted); }
.sep { width: 1px; height: 36px; background: var(--line-card); }
.pills { display: flex; gap: 8px; flex-wrap: wrap; }
.pill { display: flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 10px; background: var(--bg-card); border: 1px solid var(--line-card); }
.pill .res-dot { width: 12px; height: 12px; }
.pill b { font: 700 15px var(--font-head); }
.pill span:last-child { font: 500 10px var(--font-mono); color: var(--text-muted); }
.hold { flex: 1; min-width: 200px; }
.hold-head { display: flex; justify-content: space-between; gap: 10px; font: 500 11px var(--font-mono); color: var(--text-muted); text-transform: uppercase; }
.hold-head b { color: var(--text); }
.hold-bar { display: flex; height: 8px; margin-top: 7px; border-radius: 5px; background: var(--bg-card-off); border: 1px solid var(--line-card); overflow: hidden; }
.tick .kicker { font-size: 10px; letter-spacing: .16em; }
.tick-n { font: 700 20px var(--font-head); }

/* baner i alerty */
.banner { display: flex; align-items: center; gap: 12px; padding: 11px 18px; border-radius: 12px; background: var(--bg-card-active); border: 1px solid var(--line-strong); }
.signal { flex: none; width: 8px; height: 8px; border-radius: 50%; background: var(--info); box-shadow: 0 0 8px var(--info); }
.banner.warn .signal { background: var(--warn); box-shadow: 0 0 8px var(--warn); }
.banner.danger .signal { background: var(--danger); box-shadow: 0 0 8px var(--danger); }
.banner-tick { flex: none; font: 600 11px var(--font-mono); color: var(--info); }
.banner-text { flex: 1; min-width: 0; font: 500 14px var(--font-body); }
.alert { margin: 0; padding: 11px 18px; border-radius: 12px; border: 1px solid var(--line-card); background: var(--bg-card); display: flex; flex-wrap: wrap; gap: 6px 12px; align-items: center; }
.alert.err { border-color: var(--danger); color: var(--danger); }
.counterattack { border-color: var(--danger); }
.counterattack.defending { border-color: var(--info); }
.counterattack .full-flag { font: 600 12px var(--font-mono); color: var(--info); }
.counterattack .route { font: 600 12px var(--font-mono); color: var(--warn); }
.counterattack .route.late { color: var(--danger); }
.flags-by-sea { list-style: none; margin: 0 0 4px; padding: 0; font: 500 12px var(--font-mono); }
.flags-by-sea .full { color: var(--info); }
.pending { border-color: var(--accent); }

/* mapa + panel */
.main { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-start; }
.panel { flex: 1 1 380px; min-width: 0; border-radius: 18px; background: var(--bg-frame-a); border: 1px solid var(--line-card); overflow: hidden; display: flex; flex-direction: column; scroll-margin-top: 12px; }
.tabs { display: flex; gap: 4px; padding: 8px; border-bottom: 1px solid var(--line); background: var(--bg-panel); }
.tab { flex: 1; min-width: 0; display: flex; align-items: center; justify-content: center; gap: 8px; padding: 11px 10px; border-radius: 10px; border: none; cursor: pointer; background: transparent; color: var(--text-muted); font: 600 13px var(--font-head); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tab:hover { color: var(--text); }
.tab.on { background: var(--bg-card-active); box-shadow: inset 0 0 0 1px var(--line-strong); color: var(--text); }
.badge { padding: 1px 7px; border-radius: 999px; background: var(--bg-card-off); font: 600 10px var(--font-mono); color: var(--text-muted); }
.badge.live { color: var(--info); box-shadow: inset 0 0 0 1px var(--info); }
.tab-body { padding: 24px; display: flex; flex-direction: column; gap: 22px; }

/* zakładka miejsca */
.tags { display: flex; gap: 8px; flex-wrap: wrap; }
.tag { padding: 4px 10px; border-radius: 999px; background: var(--bg-card); border: 1px solid var(--line-btn); font: 600 10px var(--font-mono); color: var(--text-muted); letter-spacing: .1em; text-transform: uppercase; white-space: nowrap; }
.tag.own { background: #3a2516; border-color: #7a4a2a; color: var(--accent-light); }
.tag.open { border-color: var(--info); color: var(--info); }
.place-name { margin: 10px 0 0; font: 700 28px var(--font-head); }
.desc { margin: 6px 0 0; color: var(--text-desc); font-size: 14px; }
.block { display: flex; flex-direction: column; gap: 10px; }
.block > .kicker { font-size: 11px; }
.meta { display: flex; gap: 4px 14px; flex-wrap: wrap; font-size: 14px; }
.act { align-self: flex-start; padding: 11px 18px; font-size: 14px; border-radius: 10px; }
.squad { display: flex; flex-wrap: wrap; gap: 0.5rem 0.8rem; align-items: center; font: 500 12px var(--font-mono); color: var(--text-muted); }
.squad .over { color: var(--warn); }
.squad-pick { list-style: none; padding: 0; margin: 0; width: 100%; display: flex; flex-wrap: wrap; gap: 6px 14px; }
.squad-pick label { cursor: pointer; color: var(--text-2); }
.squad-pick label.off { opacity: .45; cursor: not-allowed; }
.hint-box { padding: 14px 16px; border-radius: 12px; background: var(--bg-card); border: 1px dashed var(--line-btn); font: 400 12px var(--font-body); color: var(--text-muted); line-height: 1.5; }
.ships { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 6px; }
.ship { display: flex; gap: 6px 10px; align-items: center; flex-wrap: wrap; padding: 8px 0; border-bottom: 1px solid var(--line); }
.ship-name { flex: 1 1 14rem; font: 600 14px var(--font-head); }
.ship-name .muted { font: 500 11px var(--font-mono); }
.why { flex-basis: 100%; font: 500 11px var(--font-mono); color: var(--text-muted); }

/* przegląd akwenu */
.overview { display: flex; flex-direction: column; gap: 18px; }
details.overview { padding: 12px 16px; border-radius: 12px; border: 1px solid var(--line-card); background: var(--bg-panel); }
details.overview summary { cursor: pointer; font: 600 13px var(--font-head); color: var(--text-2); }
details.overview[open] summary { margin-bottom: 14px; }
.gates { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.gates li { padding: 10px 12px; border-radius: 10px; border: 1px solid #4a5ea8; background: var(--bg-card); }
.gates li.open { border-color: var(--info); }
.gate-name { display: flex; flex-wrap: wrap; gap: 6px 10px; align-items: center; font: 600 14px var(--font-head); }
.eco-wrap { overflow-x: auto; }
.eco { border-collapse: collapse; width: 100%; font-size: 13px; }
.eco th, .eco td { padding: 6px 8px; text-align: left; border-bottom: 1px solid var(--line-card); vertical-align: top; }
.eco thead th { font: 600 10px var(--font-mono); letter-spacing: .12em; text-transform: uppercase; color: var(--text-label); }
.eco tbody th { font-weight: 600; }
.eco tbody th small { display: block; font: 500 10px var(--font-mono); }
.eco tr.here { background: var(--bg-card-active); }
.eco tr.locked { opacity: .6; }
.eco .dep-working { color: var(--info); }
.eco .dep-full { color: var(--warn); }
.eco .dep-idle { color: var(--text-muted); }

/* dziennik */
.log { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.log li { display: grid; grid-template-columns: 72px 1fr; gap: 12px; padding: 11px 14px; border-radius: 10px; background: var(--bg-card); border: 1px solid var(--line-card); border-left: 3px solid var(--info); }
.log li.warn { border-left-color: var(--warn); }
.log li.danger { border-left-color: var(--danger); }
.log-tick { font: 600 11px var(--font-mono); color: var(--text-label); padding-top: 1px; }
.log-text { font: 400 13px var(--font-body); color: var(--text-2); }

.muted { color: var(--text-muted); font-size: 0.9rem; margin: 0; }
.small { font-size: 12px; }
.err { color: var(--danger); }

@media (max-width: 640px) {
    .sep { display: none; }
    .tab-body { padding: 16px; }
    .banner { flex-wrap: wrap; }
}
</style>
