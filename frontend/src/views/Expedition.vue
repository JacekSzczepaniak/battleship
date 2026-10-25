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
                <!-- reputacja kupiec ↔ korsarz (#142): stan, skutek i próg embarga zawsze na widoku -->
                <div class="rep" :class="reputation.tier" :title="reputationTitle">
                    <div class="rep-head">
                        <span>Reputacja</span>
                        <span><b>{{ signed(reputation.value) }}</b> · {{ TIER_LABELS[reputation.tier] }}</span>
                    </div>
                    <div class="rep-bar" role="meter" :aria-valuenow="reputation.value" :aria-valuemin="reputation.min" :aria-valuemax="reputation.max" aria-label="reputacja od korsarza do kupca">
                        <span v-for="z in reputationZones" :key="z.tier" class="rep-zone" :class="z.tier" :style="{ left: z.left + '%', width: z.width + '%' }"></span>
                        <span class="rep-mark" :style="{ left: axisPercent(reputation.value, reputation) + '%' }"></span>
                    </div>
                    <div class="rep-effect">
                        {{ tierEffect(reputation.tier, reputation) }}<template v-if="!reputation.embargo"> · embargo od {{ signed(reputation.thresholds.embargo) }}</template>
                    </div>
                </div>
                <div class="sep"></div>
                <div class="tick">
                    <div class="kicker">Tik</div>
                    <div ref="tickEl" class="tick-n">{{ expedition.world.tick }}</div>
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
                <span>⚔️ Trwa bitwa {{ pendingBattle.kind === 'guardian' || pendingBattle.kind === 'encounter' ? 'z:' : 'o' }} <strong>{{ pendingBattle.islandName }}</strong>.</span>
                <router-link class="btn btn-small btn-primary" :to="{ name: 'game', params: { id: pendingBattle.gameId } }">
                    Wróć do bitwy
                </router-link>
            </section>

            <!-- Zdobycz z abordażu (#118): stan do decyzji, jak kontratak — na wierzchu, w dowolnym miejscu mapy -->
            <section v-for="prize in expedition.prizes" :key="prize.id" class="alert prize">
                <strong>⚓ Zdobyty abordażem: {{ fleetShipLabel(prize) }}</strong>
                <span class="muted">Co z nim robisz?</span>
                <span class="prize-actions">
                    <button
                        v-for="option in prizeOptions(prize)"
                        :key="option.decision"
                        class="btn btn-small"
                        :class="option.primary ? 'btn-primary' : 'btn-secondary'"
                        :disabled="busy"
                        :title="option.hint"
                        @click="onDecidePrize(prize, option.decision)"
                    >{{ option.label }}</button>
                </span>
                <span v-if="prizeOverflow(prize)" class="prize-warn">⚠️ Ładownia pomieści {{ cargoFree }} — przy rozbiórce {{ prizeOverflow(prize) }} pójdzie za burtę. Rozładuj ją najpierw w porcie, decyzja poczeka.</span>
            </section>

            <!-- Spotkanie na morzu (#141): rejs stoi do decyzji, skutki każdego wyboru podane z góry -->
            <section v-if="encounter" class="alert encounter" :class="encounter.type">
                <strong>{{ ENCOUNTER_TITLES[encounter.type] }}</strong>
                <span class="muted">Sektor {{ sectorLabel(encounter.position) }} · {{ encounter.sea.name }} · rejs czeka na Twoją decyzję.</span>
                <div class="encounter-options">
                    <div class="encounter-option">
                        <div class="kicker">{{ ENCOUNTER_BATTLE_KICKERS[encounter.type] }}</div>
                        <span class="muted">{{ encounter.battle.name }}: {{ guardianFleet(encounter.battle) }} · plansza {{ encounter.battle.board.w }}×{{ encounter.battle.board.h }} · bronie specjalne</span>
                        <span>🏆 +{{ encounter.battle.xpWin }} XP, {{ formatResources(encounter.battle.lootWin) }} do ładowni</span>
                        <span class="muted">porażka: +{{ encounter.battle.xpLoss }} XP, bez łupu · zatopione statki przepadają · nadmiar łupu ponad ładownię idzie za burtę</span>
                        <!-- skutek dla reputacji (#142): napad od razu, pirat po wygranej; łowcy bez zmiany -->
                        <template v-if="encounter.type === 'merchant'">
                            <span class="rep-note">⚖️ Napad: {{ battleReputation.text }} od razu, bez względu na wynik.</span>
                            <span v-if="battleReputation.crossing" class="over">⚠️ Przekroczysz próg: {{ battleReputation.crossing }}.</span>
                        </template>
                        <span v-else-if="encounter.type === 'pirate'" class="rep-note">⚖️ Wygrana: {{ battleReputation.text }}<template v-if="battleReputation.crossing"> ({{ battleReputation.crossing }})</template>.</span>
                        <span v-else class="rep-note">⚖️ Łowcy nagród ścigają korsarza objętego embargiem: nie przyjmą okupu i nie dadzą uciec. Wygrana nie zmienia reputacji — z embarga wyprowadzają tylko pokonani piraci ({{ signed(reputation.changes.pirateDefeated) }}).</span>
                        <div class="squad">
                            <span :class="{ over: activeCells > encounter.battle.squadLimit }">
                                skład: {{ squadCells(squadFor(encounter.battle)) }} / {{ encounter.battle.squadLimit }} pól
                                <template v-if="activeCells > encounter.battle.squadLimit"> · flota ma {{ activeCells }} pól — część nie stanie do bitwy</template>
                            </span>
                            <button v-if="activeCells > encounter.battle.squadLimit || leavesShipsInPort(encounter.battle)" class="btn btn-small btn-secondary" @click="togglePicker(encounter.battle)">
                                {{ squadPick ? 'skład domyślny' : 'wybierz skład' }}
                            </button>
                            <ul v-if="squadPick" class="squad-pick">
                                <li v-for="ship in activeShips" :key="ship.id">
                                    <label :class="{ off: !squadPick.has(ship.id) && squadCells(squadFor(encounter.battle)) + ship.length > encounter.battle.squadLimit }">
                                        <input
                                            type="checkbox"
                                            :checked="squadPick.has(ship.id)"
                                            :disabled="!squadPick.has(ship.id) && squadCells(squadFor(encounter.battle)) + ship.length > encounter.battle.squadLimit"
                                            @change="toggleShip(ship.id)"
                                        />
                                        {{ SHIP_ICONS[ship.type] }} {{ fleetShipLabel(ship) }} · {{ fieldsLabel(ship.length) }}
                                    </label>
                                </li>
                            </ul>
                            <span v-if="convoyBlocked(encounter.battle)" class="over">⚠️ konwój bez eskorty nie stanie do bitwy — dobierz kuter, niszczyciel albo lotniskowiec</span>
                        </div>
                        <button
                            class="btn btn-small btn-primary"
                            :disabled="busy || starting !== null || pendingBattle !== null || squadFor(encounter.battle).length === 0 || convoyBlocked(encounter.battle)"
                            @click="onStartEncounter"
                        >{{ starting === 'encounter' ? 'Stawianie żagli…' : ENCOUNTER_BATTLE_BUTTONS[encounter.type] }}</button>
                    </div>

                    <template v-if="encounter.type === 'merchant' && encounter.trade">
                        <div class="encounter-option">
                            <div class="kicker">Handel na miejscu · {{ encounter.trade.ticks }} tik</div>
                            <span class="muted">Ceny o {{ encounter.trade.worsePercent }}% gorsze niż w porcie. Za 1 złoto kupisz {{ encounter.trade.rate.wood.buy }} drewna albo {{ encounter.trade.rate.steel.buy }} stali (w porcie {{ encounter.trade.portRate.wood.buy }} i {{ encounter.trade.portRate.steel.buy }}), a sprzedasz za nie {{ encounter.trade.rate.wood.sell }} drewna albo {{ encounter.trade.rate.steel.sell }} stali (w porcie {{ encounter.trade.portRate.wood.sell }} i {{ encounter.trade.portRate.steel.sell }}).</span>
                            <div class="trade-grid">
                                <span></span><span class="muted">sprzedaj</span><span class="muted">kup</span>
                                <span class="trade-res"><span class="res-dot" style="background: var(--res-wood)"></span> drewno</span>
                                <input v-model.number="tradeSell.wood" type="number" min="0" :max="expedition.profile.cargo.wood" />
                                <input v-model.number="tradeBuy.wood" type="number" min="0" />
                                <span class="trade-res"><span class="res-dot" style="background: var(--res-steel)"></span> stal</span>
                                <input v-model.number="tradeSell.steel" type="number" min="0" :max="expedition.profile.cargo.steel" />
                                <input v-model.number="tradeBuy.steel" type="number" min="0" />
                            </div>
                            <span>
                                złoto: +{{ tradePreview.received }} za sprzedaż, −{{ tradePreview.paid }} za kupno
                                · w ładowni po handlu {{ tradePreview.load }} / {{ expedition.profile.cargoCapacity }}
                            </span>
                            <span v-if="encounter.reputation.trade" class="rep-note">⚖️ Udany handel: {{ changePreview(reputation, encounter.reputation.trade).text }}.</span>
                            <span v-if="tradePreview.problem" class="over">⚠️ {{ tradePreview.problem }}</span>
                            <button class="btn btn-small btn-primary" :disabled="busy || !!tradePreview.problem" @click="onMerchantTrade">⚖️ Handluj</button>
                        </div>
                        <div class="encounter-option">
                            <div class="kicker">Minięcie</div>
                            <span class="muted">Bez skutków i bez tiku — każdy płynie w swoją stronę.</span>
                            <button class="btn btn-small btn-secondary" :disabled="busy" @click="onDecideEncounter('pass')">⛵ Płyń dalej</button>
                        </div>
                    </template>

                    <template v-if="encounter.type === 'pirate' && encounter.flee && encounter.ransom">
                        <div class="encounter-option">
                            <div class="kicker">Ucieczka · {{ encounter.flee.ticks }} tik</div>
                            <span class="muted">Piraci dopadną część ładunku: {{ formatResources(encounter.flee.cargoLost, 'nic — ładownia jest pusta') }} za burtą (20% każdego surowca).</span>
                            <button class="btn btn-small btn-secondary" :disabled="busy" @click="onDecideEncounter('flee')">💨 Uciekaj</button>
                        </div>
                        <div class="encounter-option">
                            <div class="kicker">Okup</div>
                            <span class="muted">{{ encounter.ransom.gold }} złota z ładowni — bez tiku i bez strat we flocie.</span>
                            <span v-if="!encounter.ransom.affordable" class="over">⚠️ W ładowni jest {{ expedition.profile.cargo.gold }} złota — za mało na okup.</span>
                            <button class="btn btn-small btn-secondary" :disabled="busy || !encounter.ransom.affordable" @click="onDecideEncounter('ransom')">🪙 Zapłać {{ encounter.ransom.gold }} złota</button>
                        </div>
                    </template>
                </div>
            </section>

            <div class="main">
                <SeaMap
                    ref="seaMap"
                    :expedition="mapView?.expedition ?? expedition"
                    :busy="busy || !!encounter"
                    :note="sailing ? voyageNote : encounter ? 'Spotkanie czeka na decyzję — rejs stoi.' : seaMsg"
                    :gate-status="gateStatus"
                    :masked="mapView?.masked"
                    :fleet-hidden="mapView?.fleetHidden"
                    :animating="sailing"
                    @sail="onSail"
                    @skip="skipVoyage"
                />

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
                                    <span v-else-if="currentIsland.besieged" class="tag siege">🏰 Oblężona</span>
                                    <span v-else class="tag">Wyspa do zdobycia</span>
                                    <span v-if="currentIsland.stronghold" class="tag">🏰 Twierdza z fortem</span>
                                    <span class="tag">Sektor {{ sectorLabel(currentIsland.position!) }} · zadokowano</span>
                                    <span class="tag">{{ currentIsland.mode === 'fun' ? 'bronie specjalne' : 'klasyczna bitwa' }}</span>
                                </div>
                                <h2 class="place-name">{{ currentIsland.name }}</h2>
                                <p class="desc">{{ currentIsland.description }}</p>
                            </div>

                            <!-- Bitwa o wyspę albo obrona przed kontratakiem -->
                            <div class="block">
                                <div class="kicker">{{ currentIsland.owned ? 'Obrona' : currentIsland.besieged ? 'Oblężenie fortu' : currentIsland.stronghold ? 'Potyczka z flotą strażniczą' : 'Bitwa' }}</div>
                                <!-- twierdza (#120): potyczka oblega wyspę, banderę i pełny łup daje oblężenie fortu -->
                                <div v-if="currentIsland.besieged" class="meta">
                                    <span>🏆 wygrane oblężenie: 🚩 bandera, +{{ currentIsland.xpWin }} XP, {{ formatResources(currentIsland.lootWin, 'bez łupów') }}</span>
                                    <span class="muted">porażka: +{{ currentIsland.xpLoss }} XP, {{ formatResources(currentIsland.lootLoss, 'bez łupów') }} · zatopione statki przepadają, oblężenie trwa</span>
                                    <span class="muted">plansza fortu 10×10 · zniszcz wszystkie działa · każdy szturm to nowy układ fortu<template v-if="currentIsland.wins || currentIsland.losses"> · bilans: {{ currentIsland.wins }}W / {{ currentIsland.losses }}P</template></span>
                                </div>
                                <div v-else-if="currentIsland.stronghold && !currentIsland.owned" class="meta">
                                    <span>⚔️ wygrana potyczka obleże twierdzę: +{{ currentIsland.xpLoss }} XP, {{ formatResources(currentIsland.lootLoss, 'bez łupów') }}</span>
                                    <span class="muted">🏰 banderę, +{{ currentIsland.xpWin }} XP i {{ formatResources(currentIsland.lootWin, 'bez łupów') }} da dopiero oblężenie fortu</span>
                                    <span class="muted">porażka: +{{ currentIsland.xpLoss }} XP, {{ formatResources(currentIsland.lootLoss, 'bez łupów') }} · plansza {{ currentIsland.board.w }}×{{ currentIsland.board.h }}<template v-if="currentIsland.wins || currentIsland.losses"> · bilans: {{ currentIsland.wins }}W / {{ currentIsland.losses }}P</template></span>
                                </div>
                                <div v-else-if="!currentIsland.owned" class="meta">
                                    <span>🏆 +{{ currentIsland.xpWin }} XP, {{ formatResources(currentIsland.lootWin, 'bez łupów') }}</span>
                                    <span class="muted">porażka: +{{ currentIsland.xpLoss }} XP, {{ formatResources(currentIsland.lootLoss, 'bez łupów') }}</span>
                                    <span class="muted">plansza {{ currentIsland.board.w }}×{{ currentIsland.board.h }}<template v-if="currentIsland.wins || currentIsland.losses"> · bilans: {{ currentIsland.wins }}W / {{ currentIsland.losses }}P</template></span>
                                </div>
                                <div v-if="currentIsland.unlocked && (!currentIsland.owned || canDefend(currentIsland, counterattack.pending))" class="squad">
                                    <span :class="{ over: activeCells > islandTarget!.squadLimit }">
                                        skład: {{ squadCells(squadFor(islandTarget!)) }} / {{ islandTarget!.squadLimit }} pól
                                        <template v-if="activeCells > islandTarget!.squadLimit"> · flota ma {{ activeCells }} pól — część zostanie w porcie</template>
                                        <template v-else-if="!squadPick && leavesShipsInPort(islandTarget!)"> · transportowiec płynie tylko wybrany ręcznie</template>
                                    </span>
                                    <button v-if="activeCells > islandTarget!.squadLimit || leavesShipsInPort(islandTarget!)" class="btn btn-small btn-secondary" @click="togglePicker(islandTarget!)">
                                        {{ squadPick ? 'skład domyślny' : 'wybierz skład' }}
                                    </button>
                                    <FleetPresetPicker
                                        v-if="expedition!.fleetPresets?.length"
                                        :presets="expedition!.fleetPresets"
                                        :fleet="expedition!.fleet"
                                        :limit="islandTarget!.squadLimit"
                                        :selected="squadPresetId"
                                        @pick="onPickPreset"
                                    />
                                    <ul v-if="squadPick" class="squad-pick">
                                        <li v-for="ship in activeShips" :key="ship.id">
                                            <label :class="{ off: !squadPick.has(ship.id) && squadCells(squadFor(islandTarget!)) + ship.length > islandTarget!.squadLimit }">
                                                <input
                                                    type="checkbox"
                                                    :checked="squadPick.has(ship.id)"
                                                    :disabled="!squadPick.has(ship.id) && squadCells(squadFor(islandTarget!)) + ship.length > islandTarget!.squadLimit"
                                                    @change="toggleShip(ship.id)"
                                                />
                                                {{ SHIP_ICONS[ship.type] }} {{ fleetShipLabel(ship) }} · {{ fieldsLabel(ship.length) }}
                                            </label>
                                        </li>
                                    </ul>
                                    <span v-if="convoyBlocked(islandTarget!)" class="over">⚠️ konwój bez eskorty nie zaatakuje wyspy — dobierz kuter, niszczyciel albo lotniskowiec</span>
                                </div>
                                <!-- wyspa pod banderą nie jest areną: bitwa tylko w obronie przed kontratakiem (#78) -->
                                <template v-if="currentIsland.owned">
                                    <button
                                        v-if="canDefend(currentIsland, counterattack.pending)"
                                        class="btn btn-primary act"
                                        :disabled="starting !== null || pendingBattle !== null || squadFor(islandTarget!).length === 0 || convoyBlocked(islandTarget!)"
                                        @click="onStartBattle(currentIsland)"
                                    >
                                        {{ starting === currentIsland.id ? 'Stawianie żagli…' : `🛡️ Broń wyspy — uderzenie za ${ticksLabel(counterattack.pending!.ticksLeft)}` }}
                                    </button>
                                    <p v-else-if="counterattack.pending?.island === currentIsland.id" class="muted">🛡️ Obrona trwa — wróć do bitwy, by ją rozstrzygnąć.</p>
                                    <p v-else class="muted">🚩 Wyspa pod Twoją banderą — walczysz tu tylko w obronie przed kontratakiem.</p>
                                </template>
                                <button
                                    v-else-if="currentIsland.unlocked && currentIsland.besieged"
                                    class="btn btn-primary act"
                                    :disabled="starting !== null || pendingBattle !== null || squadFor(islandTarget!).length === 0 || convoyBlocked(islandTarget!)"
                                    @click="onStartSiege(currentIsland)"
                                >
                                    {{ starting === currentIsland.id ? 'Stawianie żagli…' : '🏰 Rozpocznij oblężenie' }}
                                </button>
                                <button
                                    v-else-if="currentIsland.unlocked"
                                    class="btn btn-primary act"
                                    :disabled="starting !== null || pendingBattle !== null || squadFor(islandTarget!).length === 0 || convoyBlocked(islandTarget!)"
                                    @click="onStartBattle(currentIsland)"
                                >
                                    {{ starting === currentIsland.id ? 'Stawianie żagli…' : currentIsland.stronghold ? '⚔️ Potyczka' : '⚔️ Bitwa' }}
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

                            <!-- wyspa bez stoczni (#130): zakładki Stocznia nie ma, a tratwę skleisz na każdej wyspie -->
                            <div v-if="currentIsland.shipyardLevel === 0" class="block">
                                <div class="kicker">Tratwa</div>
                                <p class="muted">Bez stoczni — tratwę skleisz z desek na każdej wyspie. Pozostałe statki zbudujesz w stoczni.</p>
                                <ul class="ships">
                                    <li v-for="t in raftTypes" :key="t.type" class="ship">
                                        <span class="ship-name">{{ SHIP_ICONS[t.type] }} {{ SHIP_LABELS[t.type] }} <span class="muted">({{ t.length }}-masztowiec{{ t.armed ? '' : ' · bez broni' }})</span></span>
                                        <button class="btn btn-small btn-secondary" :disabled="busy || buildBlock(t) !== null" :title="buildBlock(t) ?? ''" @click="onBuild(t)">
                                            Buduj ({{ formatResources(t.buildCost) }})
                                        </button>
                                        <span v-if="buildBlock(t)" class="why">{{ buildBlock(t) }}</span>
                                    </li>
                                </ul>
                            </div>
                        </template>

                        <!-- Brama akwenu (#107): położenie, powód blokady, strażnik, przejście -->
                        <!-- Port handlowy (#140): neutralna wyspa — bez bitwy i bandery, rynek w zakładce Rynek -->
                        <template v-else-if="currentPort">
                            <div>
                                <div class="tags">
                                    <span class="tag own">Port handlowy</span>
                                    <span class="tag">Sektor {{ sectorLabel(currentPort.position!) }} · zadokowano</span>
                                    <span class="tag">neutralna wyspa · bez bitwy i bandery</span>
                                </div>
                                <h2 class="place-name">{{ currentPort.name }}</h2>
                                <p class="desc">{{ currentPort.description }}</p>
                            </div>
                            <div class="block">
                                <div class="kicker">Rynek</div>
                                <p class="muted">Stały kurs akwenu: 1 złoto kupi {{ currentPort.rate.wood.buy }} drewna albo {{ currentPort.rate.steel.buy }} stali; sprzedaż po połowie ceny. Tu sprzedasz też statek za złoto.</p>
                                <button class="btn btn-primary act" @click="setTab('rynek')">⚖️ Otwórz rynek</button>
                            </div>
                            <CargoPanel
                                :island="currentPort"
                                :cargo="expedition.profile.cargo"
                                :capacity="expedition.profile.cargoCapacity"
                                :busy="busy"
                                @transfer="onTransfer"
                            />
                        </template>

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
                                        <FleetPresetPicker
                                            v-if="expedition!.fleetPresets?.length"
                                            :presets="expedition!.fleetPresets"
                                            :fleet="expedition!.fleet"
                                            :limit="currentGate.guardian.squadLimit"
                                            :selected="squadPresetId"
                                            @pick="onPickPreset"
                                        />
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
                            <div v-if="!currentGate.guardian && currentGate.blocker" class="block">
                                <div class="kicker">Przejście</div>
                                <p class="muted">🔒 {{ gateBlockText(currentGate) }}</p>
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
                        <component :is="currentIsland || currentPort || currentGate ? 'details' : 'div'" class="overview">
                            <summary v-if="currentIsland || currentPort || currentGate">Wyspy i bramy akwenu</summary>
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

                    <!-- BUDYNKI (#130): tylko na wyspie pod banderą -->
                    <div v-else-if="activeTab === 'budynki' && currentIsland" class="tab-body" role="tabpanel">
                        <div class="block">
                            <div class="kicker">Budynki · {{ currentIsland.name }}</div>
                            <SettlementPanel
                                :island="currentIsland"
                                :building-types="expedition.buildingTypes"
                                :available="addResources(currentIsland.stockpile, expedition.profile.cargo)"
                                :busy="busy"
                                @build="onBuildBuilding"
                            />
                        </div>
                    </div>

                    <!-- STOCZNIA (#130): tylko na wyspie ze stocznią — budowa, remont, rozbudowa, zdobycz -->
                    <div v-else-if="activeTab === 'stocznia' && currentIsland" class="tab-body" role="tabpanel">
                        <div class="block">
                            <div class="kicker">
                                Stocznia · {{ currentIsland.name }} · poziom {{ currentIsland.shipyardLevel }}{{ hasShipyardUpgrade ? ', z rozbudową' : '' }}
                            </div>
                            <ul class="ships">
                                <li v-for="t in expedition.shipTypes" :key="t.type" class="ship">
                                    <span class="ship-name">{{ SHIP_ICONS[t.type] }} {{ SHIP_LABELS[t.type] }} <span class="muted">({{ t.length }}-masztowiec · ładownia {{ t.cargoCapacity }}{{ t.armed ? '' : ' · bez broni' }})</span></span>
                                    <!-- wariant wybiera się przy budowie i zostaje na stałe (#99); kuter i lotniskowiec
                                         mają obok wariantów statek podstawowy (defaultVariant null, #121) -->
                                    <button
                                        v-if="!t.defaultVariant"
                                        class="btn btn-small btn-secondary"
                                        :disabled="busy || buildBlock(t) !== null"
                                        :title="buildBlock(t) ?? ''"
                                        @click="onBuild(t)"
                                    >
                                        Buduj{{ t.variants.length && t.armed ? ` · ${carriedWeapon(t.length)}` : '' }} ({{ formatResources(t.buildCost) }})
                                    </button>
                                    <button
                                        v-for="v in buildableVariants(t)"
                                        :key="v.variant"
                                        class="btn btn-small btn-secondary"
                                        :disabled="busy || buildBlock(t, v) !== null"
                                        :title="buildBlock(t, v) ?? `${carriedWeapon(t.length, VARIANTS[v.variant].w) ? `nośnik: ${carriedWeapon(t.length, VARIANTS[v.variant].w)}` : 'bez broni'} · wariant stały`"
                                        @click="onBuild(t, v.variant)"
                                    >
                                        Buduj {{ SHIP_VARIANT_LABELS[v.variant] }} · {{ carriedWeapon(t.length, VARIANTS[v.variant].w) ?? 'warsztat' }} ({{ formatResources(t.buildCost) }})
                                    </button>
                                    <span v-if="buildBlock(t)" class="why">{{ buildBlock(t) }}</span>
                                    <span v-for="v in lockedVariants(t)" :key="v.variant" class="why">
                                        <template v-if="v.unlock === 'boarding'">{{ SHIP_VARIANT_NAMES[v.variant] }}: tylko z abordażu — zdobądź go u wroga i zbadaj konstrukcję</template>
                                        <template v-else>{{ SHIP_VARIANT_LABELS[v.variant] }}: po zbadaniu „{{ TECH_LABELS[v.requiredTechnology!] }}”</template>
                                    </span>
                                </li>
                            </ul>
                        </div>

                        <div class="block">
                            <div class="kicker">Remont</div>
                            <!-- remont całej floty (#151): od najtańszego, dopóki starczy surowców i poziomu stoczni -->
                            <div v-if="damagedGroups.length" class="ship">
                                <span class="ship-name">🔧 Cała flota <span class="muted">(do remontu w tej stoczni: {{ fleetRepairShips.length }} z {{ damagedCount }})</span></span>
                                <button
                                    class="btn btn-small btn-primary"
                                    :disabled="busy || fleetRepairBlock !== null"
                                    :title="fleetRepairBlock ?? 'od najtańszego, dopóki starczy surowców'"
                                    @click="onRepairFleet"
                                >
                                    Napraw całą flotę<template v-if="fleetRepairShips.length"> ({{ formatResources(fleetRepairCost) }})</template>
                                </button>
                                <span v-if="fleetRepairBlock" class="why">{{ fleetRepairBlock }}</span>
                            </div>
                            <div v-if="fleetRepairReport" class="repair-report" role="status">
                                <p>{{ fleetRepairReport.headline }}</p>
                                <ul v-if="fleetRepairReport.skipped.length">
                                    <li v-for="s in fleetRepairReport.skipped" :key="s.id">{{ s.label }} — {{ s.reason }}</li>
                                </ul>
                            </div>
                            <ul v-if="damagedGroups.length" class="ships">
                                <li v-for="g in damagedGroups" :key="g.key" class="ship">
                                    <span class="ship-name">{{ SHIP_ICONS[g.ships[0].type] }} {{ g.label }} <span class="muted">(uszkodzone: {{ g.ships.length }})</span></span>
                                    <button
                                        class="btn btn-small btn-secondary"
                                        :disabled="busy || repairBlock(g.ships[0]) !== null"
                                        :title="repairBlock(g.ships[0]) ?? ''"
                                        @click="onRepair(g.ships[0])"
                                    >
                                        Remont 1 szt. ({{ formatResources(g.ships[0].repairCost) }})
                                    </button>
                                    <span v-if="repairBlock(g.ships[0])" class="why">{{ repairBlock(g.ships[0]) }}</span>
                                </li>
                            </ul>
                            <p v-else class="muted">Cała flota sprawna.</p>
                        </div>

                        <div class="block">
                            <div class="kicker">Złomowanie</div>
                            <p class="muted">Połowa kosztu budowy wraca do magazynu wyspy, za uszkodzony statek mniej o koszt remontu. Tratwa nic nie zwraca.</p>
                            <ul class="ships">
                                <li v-for="g in scrapGroups" :key="g.key" class="ship">
                                    <span class="ship-name">{{ SHIP_ICONS[g.ships[0].type] }} {{ g.label }} <span class="muted">({{ g.ships[0].damaged ? 'uszkodzone' : 'sprawne' }}: {{ g.ships.length }})</span></span>
                                    <ScrapButton :ship="scrapCandidate(g.ships)" :busy="busy" :block="scrapBlock(scrapCandidate(g.ships))" @scrap="onScrap" />
                                </li>
                            </ul>
                        </div>

                        <div class="block">
                            <div class="kicker">Rozbudowa stoczni</div>
                            <p v-if="hasShipyardUpgrade" class="muted">{{ BUILDING_ICONS['rozbudowa-stoczni'] }} Stoi — stocznia ma poziom {{ currentIsland.shipyardLevel }}<template v-if="!currentIsland.owned">, ale bez bandery rozbudowa nie działa</template>.</p>
                            <div v-else class="ship">
                                <span class="ship-name">{{ BUILDING_ICONS['rozbudowa-stoczni'] }} {{ BUILDING_LABELS['rozbudowa-stoczni'] }} <span class="muted">(poziom stoczni +1)</span></span>
                                <button
                                    v-if="upgradeType"
                                    class="btn btn-small btn-secondary"
                                    :disabled="busy || upgradeBlock !== null"
                                    :title="upgradeBlock ?? ''"
                                    @click="onBuildBuilding('rozbudowa-stoczni')"
                                >
                                    Buduj ({{ formatResources(upgradeType.buildCost) }})
                                </button>
                                <span v-if="upgradeBlock" class="why">{{ upgradeBlock }}</span>
                            </div>
                        </div>

                        <div v-if="expedition.prizes.length" class="block">
                            <div class="kicker">Zdobycz z abordażu</div>
                            <ul class="ships">
                                <li v-for="prize in expedition.prizes" :key="prize.id" class="ship">
                                    <span class="ship-name">⚓ {{ fleetShipLabel(prize) }}</span>
                                    <button
                                        v-for="option in prizeOptions(prize)"
                                        :key="option.decision"
                                        class="btn btn-small"
                                        :class="option.primary ? 'btn-primary' : 'btn-secondary'"
                                        :disabled="busy"
                                        :title="option.hint"
                                        @click="onDecidePrize(prize, option.decision)"
                                    >{{ option.label }}</button>
                                    <span class="why">Dołączony statek trafia do floty uszkodzony — wyremontujesz go w sekcji Remont.</span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- RYNEK (#140): tylko w porcie handlowym -->
                    <div v-else-if="activeTab === 'rynek' && currentPort" class="tab-body" role="tabpanel">
                        <div class="kicker">Rynek · {{ currentPort.name }}</div>
                        <MarketPanel
                            :port="currentPort"
                            :cargo="expedition.profile.cargo"
                            :capacity="expedition.profile.cargoCapacity"
                            :fleet="expedition.fleet"
                            :ship-types="expedition.shipTypes"
                            :reputation="reputation"
                            :busy="busy"
                            @trade="onTrade"
                            @sell-ship="onSellShip"
                            @buy-chart="onBuyChart"
                        />
                    </div>

                    <!-- FLOTA -->
                    <div v-else-if="activeTab === 'flota'" class="tab-body" role="tabpanel">
                        <FleetPanel
                            :fleet="expedition.fleet"
                            :ship-types="expedition.shipTypes"
                            :busy="busy"
                            :unescorted-convoy="unescortedConvoy"
                            :field-repairs="expedition.profile.fieldRepairs"
                            :repair-block="repairBlock"
                            :scrap-block="scrapBlock"
                            @repair="onRepair"
                            @scrap="onScrap"
                            @field-repairs="onFieldRepairs"
                        />
                    </div>

                    <!-- BADANIA -->
                    <div v-else-if="activeTab === 'badania'" class="tab-body" role="tabpanel">
                        <TechTreePanel
                            :research="expedition.research"
                            :available="currentIsland ? addResources(currentIsland.stockpile, expedition.profile.cargo) : null"
                            :academy-here="academyHere"
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
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    buildBuilding, buildShip, buySeaChart, createProfile, decideEncounter, decidePrize, getExpedition, passGate, repairFleet, repairShip, sail, scrapShip, sellShip, setFieldRepairs, settleBattle, startEncounterBattle, startGuardianBattle, startIslandBattle, startSiege,
    startResearch, trade, tradeWithMerchant, transferCargo,
    BUILDING_ICONS, BUILDING_LABELS, RANK_LABELS, RANK_ORDER, SHIP_ICONS, SHIP_LABELS, SHIP_VARIANT_LABELS, SHIP_VARIANT_NAMES, TECH_LABELS, fleetShipLabel,
    type BuildingTypeName, type EncounterDecisionName, type EncounterTypeName, type ReputationDTO, type ShipTypeName, type TechnologyName, type ExpeditionDTO, type TradePortDTO, type FleetShipDTO, type GateDTO, type GuardianDTO, type IslandDTO,
    type MarketGoodName, type MarketSideName, type PrizeDTO, type PrizeDecisionName, type RankName, type RepairFleetDTO, type ResourcesDTO, type SailResultDTO, type SeaThemeDTO, type ShipTypeDTO, type ShipVariantDTO, type ShipVariantName,
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
import { buyCost, sellValue } from '../lib/merchant';
import { axisPercent, changePreview, nextThresholds, signed, TIER_LABELS, tierEffect } from '../lib/reputation';
import CargoPanel from '../components/CargoPanel.vue';
import FleetPresetPicker from '../components/FleetPresetPicker.vue';
import FleetPanel from '../components/FleetPanel.vue';
import ScrapButton from '../components/ScrapButton.vue';
import MarketPanel from '../components/MarketPanel.vue';
import SeaMap from '../components/SeaMap.vue';
import SettlementPanel from '../components/SettlementPanel.vue';
import TechTreePanel from '../components/TechTreePanel.vue';
import type { Cell, FxBoard } from '../fx/board';
import { FxSequencer, isCancelled, type FxToken } from '../fx/core';
import { fxMode } from '../fx/speed';
import { playGate, playVoyage, revealReduced } from '../fx/voyage';
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

// --- HUD: reputacja kupiec ↔ korsarz (#142) ---

const NEUTRAL_REPUTATION: ReputationDTO = {
    value: 0, tier: 'neutral', pricePercent: 100, embargo: false, min: -100, max: 100,
    changes: { trade: 2, pirateDefeated: 5, raid: -20 },
    thresholds: { merchant: 40, corsair: -40, embargo: -70 },
    prices: { merchant: 90, corsair: 125 },
};
const reputation = computed(() => expedition.value?.profile.reputation ?? NEUTRAL_REPUTATION);

/** Strefy osi: embargo, korsarz, neutralny, kupiec — granice z progów backendu. */
const reputationZones = computed(() => {
    const r = reputation.value;
    const at = (v: number) => axisPercent(v, r);
    return [
        { tier: 'embargo', left: 0, width: at(r.thresholds.embargo) },
        { tier: 'corsair', left: at(r.thresholds.embargo), width: at(r.thresholds.corsair) - at(r.thresholds.embargo) },
        { tier: 'neutral', left: at(r.thresholds.corsair), width: at(r.thresholds.merchant) - at(r.thresholds.corsair) },
        { tier: 'merchant', left: at(r.thresholds.merchant), width: 100 - at(r.thresholds.merchant) },
    ];
});

const reputationTitle = computed(() => {
    const r = reputation.value;
    return [
        `Reputacja ${signed(r.value)} (${TIER_LABELS[r.tier]}): ${tierEffect(r.tier, r)}.`,
        `Handel ${signed(r.changes.trade)}, pokonany pirat ${signed(r.changes.pirateDefeated)}, napad na handlarza ${signed(r.changes.raid)}.`,
        ...nextThresholds(r).map(t => `Próg ${t}.`),
    ].join('\n');
});

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

type TabId = 'wyspa' | 'budynki' | 'stocznia' | 'rynek' | 'flota' | 'badania' | 'dziennik';
const TAB_IDS: TabId[] = ['wyspa', 'budynki', 'stocznia', 'rynek', 'flota', 'badania', 'dziennik'];

const activeTab = ref<TabId>('flota');
const panelEl = ref<HTMLElement | null>(null);

/** Zakładka z hashu, o ile jest w tym miejscu: „#stocznia” z innej wyspy (port, wynik bitwy) odpada. */
function tabFromHash(hash: string): TabId | null {
    const id = hash.replace(/^#/, '');
    return (TAB_IDS as string[]).includes(id) && tabs.value.some(t => t.id === id) ? id as TabId : null;
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

/** Domyślna zakładka: w porcie (także handlowym) albo na sektorze bramy — miejsce floty, na morzu — flota. */
function defaultTab(): TabId {
    return currentIsland.value || currentPort.value || currentGate.value ? 'wyspa' : 'flota';
}

const researchDone = computed(() => expedition.value?.research.technologies.filter(t => t.state === 'researched').length ?? 0);

const tabs = computed(() => {
    const exp = expedition.value;
    const island = currentIsland.value;
    const current = exp?.research.current ?? null;
    return [
        { id: 'wyspa' as TabId, label: island?.name ?? currentPort.value?.name ?? currentGate.value?.name ?? 'Akwen', badge: '', live: false },
        // #130: budynki pod banderą (postawione/możliwe na tej wyspie), stocznia tam, gdzie jest
        ...(island?.owned ? [{ id: 'budynki' as TabId, label: 'Budynki', badge: `${island.buildings.length}/${buildingSlots.value}`, live: false }] : []),
        ...(island && island.shipyardLevel > 0 ? [{ id: 'stocznia' as TabId, label: 'Stocznia', badge: `poz. ${island.shipyardLevel}`, live: false }] : []),
        // #140: rynek w porcie handlowym akwenu
        ...(currentPort.value ? [{ id: 'rynek' as TabId, label: 'Rynek', badge: '', live: false }] : []),
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

/** Cel składu bitewnego na wyspie floty: oblężenie liczy pola w limicie planszy fortu (#120). */
const islandTarget = computed<IslandDTO | null>(() => {
    const island = currentIsland.value;
    if (!island?.besieged || island.siegeSquadLimit === null) return island;
    return { ...island, squadLimit: island.siegeSquadLimit };
});

/** Brama na sektorze floty (#107). */
const currentGate = computed<GateDTO | null>(
    () => expedition.value?.world.gates.find(g => g.present) ?? null
);

/** Port handlowy na sektorze floty (#140). */
const currentPort = computed<TradePortDTO | null>(
    () => (expedition.value?.world.tradePort?.present ? expedition.value.world.tradePort : null)
);

/** Miejsce floty w stanie wyprawy: wyspa w porcie, port handlowy albo sektor bramy. */
function placeOf(exp: ExpeditionDTO | null): { key: string; name: string; label: string } | null {
    if (!exp) return null;
    const island = exp.islands.find(i => i.present);
    if (island) return { key: island.id, name: island.name, label: `Zadokowano · wyspa ${island.name}` };
    const port = exp.world.tradePort?.present ? exp.world.tradePort : null;
    if (port) return { key: port.id, name: port.name, label: `Zadokowano · port ${port.name}` };
    const gate = exp.world.gates.find(g => g.present);
    return gate ? { key: gate.id, name: gate.name, label: `Na sektorze bramy · ${gate.name}` } : null;
}

// --- Animacja rejsu (#146) ---

const fx = new FxSequencer();
const seaMap = ref<InstanceType<typeof SeaMap> | null>(null);
const tickEl = ref<HTMLElement | null>(null);
const sailing = ref(false);
const voyageNote = ref('');
/** Mapa w trakcie animacji: nowy stan z sektorami pod mgłą, flota w overlayu. Reszta ekranu czeka. */
const mapView = ref<{ expedition: ExpeditionDTO; masked: Set<string>; fleetHidden: boolean } | null>(null);

interface VoyageHooks {
    /** Flota wpływa na nowe miejsce (wyspa, port, brama). */
    docks: boolean;
    onReveal: (keys: string[]) => void;
    onArrive: () => void;
    /** Mapa przełącza się na nowy stan (brama: nowy akwen); geometria po przerysowaniu. */
    swap: () => Promise<FxBoard | null>;
}

/**
 * Rejs albo brama: nowy stan jest już pobrany, animacja tylko opóźnia jego
 * pokazanie. Po kroku zapis stanu (TIK, baner, spotkanie); dokowanie przy
 * wyspie, porcie lub na sektorze bramy przełącza panel na zakładkę miejsca.
 * „Wył.” — od razu wynik, prefers-reduced-motion — wynik z fade 150 ms.
 */
async function showVoyage(
    next: ExpeditionDTO, discovered: string[], parts: string[], note: string, arrivedLabel: string | null,
    play: ((tok: FxToken, board: FxBoard, hooks: VoyageHooks) => Promise<void>) | null,
    startOnNext: boolean,
) {
    const before = placeOf(expedition.value);
    const after = placeOf(next);
    const docks = !!after && after.key !== before?.key;
    let arrived = false;
    const arrive = () => {
        if (arrived) return;
        arrived = true;
        expedition.value = next;
        if (sailing.value) tickEl.value?.animate([{ transform: 'scale(1.35)', color: 'var(--info)' }, { transform: 'scale(1)' }], { duration: 400, easing: 'ease-out' });
    };
    const finish = () => {
        arrive();
        mapView.value = null;
        seaMsg.value = [arrivedLabel ?? (docks ? after!.label : ''), ...parts].filter(Boolean).join(' · ');
        if (docks) setTab('wyspa');
    };

    const mode = fxMode();
    if (mode === 'off' || !play) {
        finish();
        return;
    }
    const tok = fx.begin();
    if (mode === 'reduced') {
        finish();
        await nextTick();
        const board = seaMap.value?.fxBoard();
        if (board) revealReduced(tok, board, discovered);
        fx.end(tok);
        return;
    }

    const view = (exp: ExpeditionDTO, masked: string[]) => ({ expedition: exp, masked: new Set(masked), fleetHidden: true });
    sailing.value = true;
    voyageNote.value = `${note} · Kliknij mapę albo Esc — pomiń animację.`;
    mapView.value = startOnNext ? view(next, discovered) : view(expedition.value!, []);
    try {
        await nextTick();
        const board = seaMap.value?.fxBoard();
        if (board) {
            await play(tok, board, {
                docks,
                onReveal: keys => keys.forEach(k => mapView.value?.masked.delete(k)),
                onArrive: arrive,
                swap: async () => {
                    mapView.value = view(next, discovered);
                    await nextTick();
                    return seaMap.value?.fxBoard() ?? null;
                },
            });
        }
    } catch (e) {
        if (!isCancelled(e)) {
            try { console.warn('[Expedition.fx]', e); } catch {}
        }
    } finally {
        fx.end(tok);
        sailing.value = false;
        finish();
        // znaczniki efektów schodzą dopiero, gdy mapa pokazuje już nowy stan
        await nextTick();
        seaMap.value?.fxBoard()?.clear();
    }
}

/** Klik w mapę albo Esc w trakcie animacji: od razu wynik. */
function skipVoyage() {
    if (sailing.value) fx.cancel();
}

function onVoyageKey(e: KeyboardEvent) {
    if (sailing.value && e.key === 'Escape') skipVoyage();
}

onMounted(() => window.addEventListener('keydown', onVoyageKey));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onVoyageKey);
    fx.cancel();
});

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
    switch (gate.blocker) {
        case 'rank': return gate.guardian ? `🔒 od rangi ${rankLabel(gate.guardian.requiredRank)}` : '🔒 zamknięta';
        case 'technology': return '🔒 wymaga technologii';
        case 'chart': return '🔒 wymaga mapy';
        default: return '🔒 strzeżona';
    }
}

/** Powód zamknięcia bramy pełnym zdaniem; nowy warunek bramy dopisuje się tutaj. */
function gateBlockText(gate: GateDTO): string {
    switch (gate.blocker) {
        case null: return 'otwarta';
        case 'rank': return gate.guardian ? `strażnik przyjmie bitwę od rangi ${rankLabel(gate.guardian.requiredRank)}` : 'zamknięta rangą';
        case 'guardian': return gate.guardian ? `strzeże jej ${gate.guardian.name} — pokonaj go na sektorze bramy` : 'strzeżona';
        case 'technology': return gate.technology ? `otworzy ją zbadana technologia „${TECH_LABELS[gate.technology]}” (uczelnia)` : 'wymaga technologii';
        case 'chart': return `poprowadzi przez nią tylko mapa akwenu ${gate.to.name} — do kupienia na Bazarze we Mgle`;
    }
}

/** „Lotniskowiec, 2× Niszczyciel, 2× Kuter, 3× Tratwa”. */
function guardianFleet(guardian: Pick<GuardianDTO, 'fleet'> | { fleet: ShipTypeName[] }): string {
    const counts = new Map<string, number>();
    guardian.fleet.forEach(t => counts.set(SHIP_LABELS[t], (counts.get(SHIP_LABELS[t]) ?? 0) + 1));
    return [...counts].map(([name, n]) => (n > 1 ? `${n}× ${name}` : name)).join(', ');
}

async function onSail(x: number, y: number) {
    const profileId = getProfileId();
    const start = expedition.value?.world.position;
    if (!profileId || !start) return;
    busy.value = true;
    error.value = '';
    try {
        const result = await sail(profileId, x, y);
        const next = await getExpedition(profileId);
        const from: Cell = [start.x, start.y];
        const to: Cell = [x, y];
        const after = placeOf(next);
        const target = after && after.key !== placeOf(expedition.value)?.key ? after.name : sectorLabel({ x, y });
        // w jednym rejsie najwyżej jedno zdarzenie: sztorm albo spotkanie — rejs staje z banerem
        const stopped = !!result.event || !!result.encounter;
        await showVoyage(next, result.discoveredNow, voyageMessage(result), `Kurs na ${target} · 1 tik`, null,
            (tok, board, hooks) => playVoyage(tok, board, {
                from, to, discovered: result.discoveredNow, onReveal: hooks.onReveal, onArrive: hooks.onArrive,
                stopped, docks: hooks.docks,
            }), true);
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
        const next = await getExpedition(profileId);
        squadPick.value = null;
        const at = gate.position;
        const stopped = !!result.event || !!result.encounter;
        await showVoyage(next, result.discoveredNow, voyageMessage(result), `${gate.name} → ${gate.to.name} · 1 tik`, `Za bramą · ${next.world.sea.name}`,
            at ? (tok, board, hooks) => playGate(tok, board, {
                at: [at.x, at.y], to: [result.position.x, result.position.y], discovered: result.discoveredNow,
                swap: hooks.swap, onReveal: hooks.onReveal, onArrive: hooks.onArrive, stopped,
            }) : null, false);
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

/** Powód blokady złomowania (#124); null = można złomować. */
function scrapBlock(ship: FleetShipDTO): string | null {
    const island = currentIsland.value;
    if (!expedition.value) return 'wczytywanie…';
    if (!island) return 'dopłyń do portu ze stocznią';
    if (island.shipyardLevel < 1) return shipyardMissing(island, 1);
    switch (ship.scrapBlock) {
        case 'in-battle': return 'statek walczy w nierozliczonej bitwie';
        case 'last-seaworthy': return 'ostatnia sprawna jednostka floty — bez niej nie stoczysz bitwy';
        case null: return null;
    }
}

/** Statek grupy do złomowania: pierwszy bez blokady, żeby statek z bitwy nie zasłaniał reszty grupy. */
function scrapCandidate(ships: FleetShipDTO[]): FleetShipDTO {
    return ships.find(s => s.scrapBlock === null) ?? ships[0];
}

/** Warianty czekające na technologię — podpowiedź, gdy sam typ da się już budować. */
function lockedVariants(t: ShipTypeDTO): ShipVariantDTO[] {
    return buildBlock(t) === null ? t.variants.filter(v => !v.unlocked) : [];
}

/** Przyciski wariantów: wariant ze zdobyczy (#121) pojawia się dopiero po zbadaniu konstrukcji. */
function buildableVariants(t: ShipTypeDTO): ShipVariantDTO[] {
    return t.variants.filter(v => v.unlocked || v.unlock !== 'boarding');
}

// --- zakładki Budynki i Stocznia (#130) ---

/** Typy budynków, które mogą stanąć na tej wyspie (bez tych, którym nie pasuje miejsce). */
const buildingSlots = computed(() => {
    const island = currentIsland.value;
    return island ? (expedition.value?.buildingTypes ?? []).filter(t => island.buildBlockers[t.type] !== 'site').length : 0;
});

/** Na wyspie bez stoczni zostaje tratwa (requiredShipyardLevel 0). */
const raftTypes = computed(() => (expedition.value?.shipTypes ?? []).filter(t => t.requiredShipyardLevel === 0));

const hasShipyardUpgrade = computed(() => currentIsland.value?.buildings.includes('rozbudowa-stoczni') ?? false);
const upgradeType = computed(() => expedition.value?.buildingTypes.find(t => t.type === 'rozbudowa-stoczni') ?? null);

/** Powód blokady rozbudowy stoczni (kody jak w SettlementPanel); null = można budować. */
const upgradeBlock = computed<string | null>(() => {
    const island = currentIsland.value;
    const type = upgradeType.value;
    const profile = expedition.value?.profile;
    if (!island || !type || !profile) return 'wczytywanie…';
    switch (island.buildBlockers['rozbudowa-stoczni']) {
        case null: return null;
        case 'not-owned': return 'rozbudowa wymaga Twojej bandery na wyspie';
        case 'already-built': return 'już stoi';
        case 'site': return 'stocznia ma już najwyższy poziom';
        case 'not-present': return 'dopłyń na wyspę';
        case 'resources': return withSources(addResources(island.stockpile, profile.cargo), type.buildCost);
    }
});

/** Uszkodzone statki zgrupowane po typie i wariancie, jak w zakładce Flota. */
const damagedGroups = computed(() => {
    const byKey = new Map<string, { key: string; label: string; ships: FleetShipDTO[] }>();
    for (const ship of expedition.value?.fleet ?? []) {
        if (!ship.damaged) continue;
        const key = `${ship.type}:${ship.variant ?? ''}`;
        if (!byKey.has(key)) byKey.set(key, { key, label: fleetShipLabel(ship), ships: [] });
        byKey.get(key)!.ships.push(ship);
    }
    return [...byKey.values()];
});

const damagedCount = computed(() => (expedition.value?.fleet ?? []).filter(s => s.damaged).length);

/** Remont całej floty (#151): uszkodzone statki, które ta stocznia wyremontuje — od najtańszego, jak w domenie. */
const fleetRepairShips = computed(() => {
    const island = currentIsland.value;
    const types = expedition.value?.shipTypes ?? [];
    if (!island) return [];
    return (expedition.value?.fleet ?? [])
        .filter(s => s.damaged && island.shipyardLevel >= (types.find(t => t.type === s.type)?.requiredShipyardLevel ?? Infinity))
        .sort((a, b) => totalResources(a.repairCost) - totalResources(b.repairCost));
});

const fleetRepairCost = computed(() => fleetRepairShips.value.reduce((sum, s) => addResources(sum, s.repairCost), { wood: 0, steel: 0, gold: 0 }));

/** Powód blokady przycisku: stocznia nie wyremontuje żadnego statku albo nie starcza na żaden; null = można. */
const fleetRepairBlock = computed((): string | null => {
    const island = currentIsland.value;
    if (!island) return 'dopłyń do portu ze stocznią';
    if (!fleetRepairShips.value.length) {
        const types = expedition.value?.shipTypes ?? [];
        const levels = (expedition.value?.fleet ?? []).filter(s => s.damaged).map(s => types.find(t => t.type === s.type)?.requiredShipyardLevel ?? 0);
        return shipyardMissing(island, Math.min(...levels));
    }
    return fleetRepairShips.value.every(s => repairBlock(s) !== null) ? repairBlock(fleetRepairShips.value[0]) : null;
});

/** Podsumowanie ostatniego remontu całej floty: ile wyremontowano, które statki zostały i dlaczego. */
const fleetRepairResult = ref<RepairFleetDTO | null>(null);

const fleetRepairReport = computed(() => {
    const result = fleetRepairResult.value;
    const island = currentIsland.value;
    const profile = expedition.value?.profile;
    if (!result || !island || !profile) return null;
    const types = expedition.value?.shipTypes ?? [];
    const available = addResources(island.stockpile, profile.cargo);
    const repaired = result.repaired.length;
    const headline = repaired
        ? `Wyremontowano ${repaired} szt. za ${formatResources(result.paid)}.${result.skipped.length ? ` Zostały uszkodzone: ${result.skipped.length} szt.` : ''}`
        : `Nic nie wyremontowano. Zostały uszkodzone: ${result.skipped.length} szt.`;
    const skipped = result.skipped.map(({ ship, reason }) => {
        const cost = expedition.value?.fleet.find(s => s.id === ship.id)?.repairCost ?? { wood: 0, steel: 0, gold: 0 };
        return {
            id: ship.id,
            label: `${SHIP_ICONS[ship.type]} ${fleetShipLabel(ship)}`,
            reason: reason === 'shipyard-too-low'
                ? shipyardMissing(island, types.find(t => t.type === ship.type)?.requiredShipyardLevel ?? 0)
                : `zabrakło surowców (${formatResources(cost)}): ${withSources(available, cost)}`,
        };
    });
    return { headline, skipped };
});

// podsumowanie dotyczy stoczni, przy której flota stała
watch(() => currentIsland.value?.id, () => { fleetRepairResult.value = null; });

// złomowanie (#124): sprawne i uszkodzone osobno, bo oddają różny zwrot
const scrapGroups = computed(() => {
    const byKey = new Map<string, { key: string; label: string; ships: FleetShipDTO[] }>();
    for (const ship of expedition.value?.fleet ?? []) {
        const key = `${ship.type}:${ship.variant ?? ''}:${ship.damaged ? 'damaged' : 'ok'}`;
        if (!byKey.has(key)) byKey.set(key, { key, label: fleetShipLabel(ship), ships: [] });
        byKey.get(key)!.ships.push(ship);
    }
    return [...byKey.values()];
});

/** Badania ruszą z tej wyspy: uczelnia stoi, a wyspa jest pod banderą. */
const academyHere = computed(() => {
    const island = currentIsland.value;
    return island?.owned && island.buildings.includes('uczelnia') ? island.name : null;
});

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

/** Jedno przeniesienie = jedno dokowanie = 1 tik (#49); magazyn ma też port handlowy (#140). */
function onTransfer(direction: 'load' | 'unload', amount: ResourcesDTO) {
    if (isEmpty(amount)) return;
    if (currentPort.value) {
        return inTradePort((profileId, port) => transferCargo(profileId, port.id, direction, amount), 'Przeładunek nie powiódł się');
    }
    return inPort((profileId, island) => transferCargo(profileId, island.id, direction, amount), 'Przeładunek nie powiódł się');
}

/** Akcja w porcie handlowym (#140): wywołanie API i świeży stan wyprawy. */
async function inTradePort(action: (profileId: string, port: TradePortDTO) => Promise<unknown>, failure: string) {
    const profileId = getProfileId();
    const port = currentPort.value;
    if (!profileId || !port) return;
    busy.value = true;
    error.value = '';
    try {
        await action(profileId, port);
        expedition.value = await getExpedition(profileId);
    } catch (e: any) {
        error.value = e?.message ?? failure;
    } finally {
        busy.value = false;
    }
}

/** Kupno albo sprzedaż towaru na rynku — 1 tik (#140). */
function onTrade(side: MarketSideName, good: MarketGoodName, gold: number) {
    return inTradePort((profileId, port) => trade(profileId, port.id, side, good, gold), 'Transakcja nie powiodła się');
}

function onSellShip(ship: FleetShipDTO) {
    return inTradePort((profileId, port) => sellShip(profileId, port.id, ship.id), 'Sprzedaż statku nie powiodła się');
}

/** Kupno mapy akwenu na rynku — 1 tik (#143). */
function onBuyChart() {
    return inTradePort((profileId, port) => buySeaChart(profileId, port.id), 'Kupno mapy nie powiodło się');
}

// --- zdobycz z abordażu (#118): opcje decyzji; „zbadaj konstrukcję” tylko dla nieznanego wariantu (#121) ---

interface PrizeOption { decision: PrizeDecisionName; label: string; hint: string; primary: boolean }

function prizeOptions(prize: PrizeDTO): PrizeOption[] {
    return [
        { decision: 'join', label: 'Wyremontuj i dołącz', hint: 'Statek dołącza do floty uszkodzony — remont w stoczni. Ranga nie ogranicza zdobyczy.', primary: true },
        { decision: 'scrap', label: `Rozbierz · +${formatResources(prize.scrapValue)}`, hint: 'Połowa kosztu budowy trafia do ładowni; to, co się nie zmieści, przepada.', primary: false },
        ...(prize.studyTechnology ? [{
            decision: 'study' as const,
            label: 'Zbadaj konstrukcję',
            hint: `Statek przepada, a „${TECH_LABELS[prize.studyTechnology]}” od razu trafia do stoczni — wariant wybierzesz przy budowie. Bez kosztu i tików.`,
            primary: false,
        }] : []),
    ];
}

/** Część zwrotu z rozbiórki, która nie zmieści się w ładowni; '' = zmieści się cała. */
function prizeOverflow(prize: PrizeDTO): string {
    const over = totalResources(prize.scrapValue) - cargoFree.value;
    return over > 0 ? `${over} szt.` : '';
}

async function onDecidePrize(prize: PrizeDTO, decision: PrizeDecisionName) {
    const profileId = getProfileId();
    if (!profileId) return;
    busy.value = true;
    error.value = '';
    try {
        await decidePrize(profileId, prize.id, decision);
        expedition.value = await getExpedition(profileId);
    } catch (e: any) {
        error.value = e?.message ?? 'Decyzja o zdobyczy nie powiodła się';
    } finally {
        busy.value = false;
    }
}

function onRepairFleet() {
    return inPort(async (profileId, island) => {
        fleetRepairResult.value = await repairFleet(profileId, island.id);
    }, 'Remont floty nie powiódł się');
}

function onRepair(ship: FleetShipDTO) {
    fleetRepairResult.value = null;
    return inPort((profileId, island) => repairShip(profileId, island.id, ship.id), 'Remont nie powiódł się');
}

// przełącznik napraw kutrów remontowych poza stocznią (#152) — bez tiku, działa także w rejsie
async function onFieldRepairs(enabled: boolean) {
    const profileId = getProfileId();
    if (!profileId || !expedition.value) return;
    busy.value = true;
    error.value = '';
    try {
        const { fieldRepairs } = await setFieldRepairs(profileId, enabled);
        expedition.value.profile.fieldRepairs = fieldRepairs;
    } catch (e: any) {
        error.value = e?.message ?? 'Zmiana napraw w rejsie nie powiodła się';
    } finally {
        busy.value = false;
    }
}

function onScrap(ship: FleetShipDTO) {
    return inPort((profileId, island) => scrapShip(profileId, island.id, ship.id), 'Złomowanie nie powiodło się');
}
// watch po definicjach zakładek: getter liczy się od razu przy rejestracji.
// Rejs, utrata bandery albo zmiana wyspy zabiera zakładkę Budynki lub Stocznia — wróć do domyślnej
watch(() => tabs.value.map(t => t.id).join(), () => {
    if (expedition.value && !tabs.value.some(t => t.id === activeTab.value)) setTab(defaultTab());
});

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
        // wejście z portu „Stocznia” (#stocznia) otwiera zakładkę stoczni, o ile flota przy niej stoi
        setTab(tabFromHash(route.hash) ?? defaultTab());
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
// zestaw floty, z którego wziął się skład (#125) — rozstawianie ustawi z niego statki,
// które płyną; powrót do składu domyślnego go odpina
const squadPresetId = ref<string | null>(null);
watch(squadPick, pick => { if (pick === null) squadPresetId.value = null; });
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

/** Zestaw floty (#125) ustawia skład; bez zestawu skład wraca do domyślnego. */
function onPickPreset(presetId: string | null, shipIds: string[] | null) {
    squadPick.value = shipIds ? new Set(shipIds) : null;
    squadPresetId.value = presetId;
}

async function onStartBattle(island: IslandDTO) {
    const profileId = getProfileId();
    if (!profileId) return;
    starting.value = island.id;
    error.value = '';
    try {
        const shipIds = squadPick.value ? [...squadPick.value] : undefined;
        const game = await startIslandBattle(profileId, island.id, shipIds);
        setCurrentBattle({ profileId, gameId: game.id, islandId: island.id, islandName: island.name, presetId: squadPresetId.value ?? undefined });
        await router.push({ name: 'place-fleet', params: { id: game.id } });
    } catch (e: any) {
        error.value = e?.message ?? 'Nie udało się rozpocząć bitwy';
        starting.value = null;
    }
}

async function onStartSiege(island: IslandDTO) {
    const profileId = getProfileId();
    if (!profileId) return;
    starting.value = island.id;
    error.value = '';
    try {
        const shipIds = squadPick.value ? [...squadPick.value] : undefined;
        const game = await startSiege(profileId, island.id, shipIds);
        setCurrentBattle({ profileId, gameId: game.id, islandId: island.id, islandName: island.name, kind: 'siege', presetId: squadPresetId.value ?? undefined });
        await router.push({ name: 'place-fleet', params: { id: game.id } });
    } catch (e: any) {
        error.value = e?.message ?? 'Nie udało się rozpocząć oblężenia';
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
        setCurrentBattle({ profileId, gameId: game.id, islandId: gate.id, islandName: gate.guardian.name, kind: 'guardian', presetId: squadPresetId.value ?? undefined });
        await router.push({ name: 'place-fleet', params: { id: game.id } });
    } catch (e: any) {
        error.value = e?.message ?? 'Nie udało się rozpocząć bitwy';
        starting.value = null;
    }
}

// --- Spotkania na morzu (#141): handlarz i pirat ---

const encounter = computed(() => expedition.value?.encounter ?? null);

const ENCOUNTER_TITLES: Record<EncounterTypeName, string> = {
    pirate: '🏴‍☠️ Piraci na kursie',
    merchant: '⚖️ Handlarz na kursie',
    'bounty-hunters': '🎯 Łowcy nagród na kursie',
};
const ENCOUNTER_BATTLE_KICKERS: Record<EncounterTypeName, string> = {
    pirate: 'Walka',
    merchant: 'Napad',
    'bounty-hunters': 'Bitwa — jedyne wyjście',
};
const ENCOUNTER_BATTLE_BUTTONS: Record<EncounterTypeName, string> = {
    pirate: '⚔️ Walcz z piratami',
    merchant: '⚔️ Napadnij handlarza',
    'bounty-hunters': '⚔️ Stań do walki',
};

/** Skutek bitwy spotkania dla reputacji (#142): napad od razu, pirat po wygranej. */
const battleReputation = computed(() => changePreview(reputation.value, encounter.value?.reputation.battle ?? 0));

const tradeSell = ref({ wood: 0, steel: 0 });
const tradeBuy = ref({ wood: 0, steel: 0 });
watch(() => encounter.value?.tick, () => {
    tradeSell.value = { wood: 0, steel: 0 };
    tradeBuy.value = { wood: 0, steel: 0 };
});

const amount = (n: unknown) => (typeof n === 'number' && Number.isFinite(n) && n > 0 ? Math.floor(n) : 0);

/** Zlecenie u handlarza: złoto za sprzedaż i kupno, ładunek po handlu i powód blokady (jak domena). */
const tradePreview = computed(() => {
    const trade = encounter.value?.trade;
    const profile = expedition.value?.profile;
    const sell = { wood: amount(tradeSell.value.wood), steel: amount(tradeSell.value.steel) };
    const buy = { wood: amount(tradeBuy.value.wood), steel: amount(tradeBuy.value.steel) };
    if (!trade || !profile) return { sell, buy, received: 0, paid: 0, load: 0, problem: 'brak handlarza' };
    const received = sellValue(trade, sell);
    const paid = buyCost(trade, buy);
    const cargo = profile.cargo;
    const load = totalResources(cargo) - sell.wood - sell.steel + buy.wood + buy.steel + received - paid;
    let problem = '';
    if (sell.wood + sell.steel + buy.wood + buy.steel === 0) problem = 'wpisz, co sprzedajesz albo kupujesz';
    else if (sell.wood > cargo.wood || sell.steel > cargo.steel) problem = 'nie masz tyle w ładowni';
    else if (cargo.gold + received < paid) problem = `zabraknie złota: potrzeba ${paid}, będzie ${cargo.gold + received}`;
    else if (load > profile.cargoCapacity) problem = `ładownia nie pomieści: ${load} / ${profile.cargoCapacity}`;
    return { sell, buy, received, paid, load, problem };
});

async function onMerchantTrade() {
    const profileId = getProfileId();
    if (!profileId) return;
    busy.value = true;
    error.value = '';
    try {
        await tradeWithMerchant(profileId, tradePreview.value.sell, tradePreview.value.buy);
        expedition.value = await getExpedition(profileId);
    } catch (e: any) {
        error.value = e?.message ?? 'Handel nie powiódł się';
    } finally {
        busy.value = false;
    }
}

async function onDecideEncounter(decision: EncounterDecisionName) {
    const profileId = getProfileId();
    if (!profileId) return;
    busy.value = true;
    error.value = '';
    try {
        await decideEncounter(profileId, decision);
        expedition.value = await getExpedition(profileId);
    } catch (e: any) {
        error.value = e?.message ?? 'Decyzja nie powiodła się';
    } finally {
        busy.value = false;
    }
}

async function onStartEncounter() {
    const profileId = getProfileId();
    const current = encounter.value;
    if (!profileId || !current) return;
    starting.value = 'encounter';
    error.value = '';
    try {
        const shipIds = squadPick.value ? [...squadPick.value] : undefined;
        const game = await startEncounterBattle(profileId, shipIds);
        setCurrentBattle({ profileId, gameId: game.id, islandId: current.sea.id, islandName: current.battle.name, kind: 'encounter', presetId: squadPresetId.value ?? undefined });
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

.rep { min-width: 190px; display: flex; flex-direction: column; gap: 6px; }
.rep-head { display: flex; justify-content: space-between; gap: 10px; font: 500 11px var(--font-mono); color: var(--text-muted); text-transform: uppercase; }
.rep-head b { color: var(--text); }
.rep-bar { position: relative; height: 8px; border-radius: 5px; background: var(--bg-card-off); border: 1px solid var(--line-card); overflow: hidden; }
.rep-zone { position: absolute; top: 0; bottom: 0; opacity: .45; }
.rep-zone.embargo { background: var(--danger); }
.rep-zone.corsair { background: var(--warn); }
.rep-zone.neutral { background: transparent; }
.rep-zone.merchant { background: var(--info); }
.rep-mark { position: absolute; top: -1px; bottom: -1px; width: 3px; margin-left: -1px; border-radius: 2px; background: var(--text); }
.rep-effect { font: 500 11px var(--font-mono); color: var(--text-muted); }
.rep.corsair .rep-effect { color: var(--warn); }
.rep.embargo .rep-effect { color: var(--danger); }
.rep.merchant .rep-effect { color: var(--info); }

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
.prize { border-color: var(--accent); }
.prize-actions { display: inline-flex; flex-wrap: wrap; gap: 8px; }
.prize-warn { flex-basis: 100%; font: 500 12px var(--font-mono); color: var(--warn); }
.encounter.pirate { border-color: var(--danger); }
.encounter.merchant { border-color: var(--info); }
.encounter.bounty-hunters { border-color: var(--danger); }
.rep-note { font: 500 12px var(--font-mono); color: var(--text-muted); }
.encounter-options { flex-basis: 100%; display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px; }
.encounter-option { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; padding: 10px 12px; border-radius: 10px; border: 1px solid var(--line-card); background: var(--bg-panel); }
.encounter-option .over { color: var(--warn); font: 500 12px var(--font-mono); }
.trade-grid { display: grid; grid-template-columns: auto 80px 80px; gap: 4px 8px; align-items: center; }
.trade-res { display: inline-flex; align-items: center; gap: 6px; }
.trade-grid input { width: 80px; padding: 4px 6px; border: 1px solid var(--line-btn); border-radius: 6px; background: var(--bg-card); color: var(--text); }

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
.tag.siege { border-color: var(--warn); color: var(--warn); }
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
.repair-report { margin: 4px 0 10px; padding: 8px 12px; border-radius: 10px; border: 1px solid var(--line-card); background: var(--bg-panel); font-size: 13px; }
.repair-report p { margin: 0; }
.repair-report ul { margin: 6px 0 0; padding-left: 18px; color: var(--text-muted); }

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
