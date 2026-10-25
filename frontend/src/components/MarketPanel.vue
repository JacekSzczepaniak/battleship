<!--
    Rynek portu handlowego (#140): stały kurs akwenu, kupno i sprzedaż drewna
    i stali w porcjach po 1 złoto (sprzedaż po połowie ceny kupna) oraz
    sprzedaż statku za złoto (zwrot jak przy złomowaniu, przeliczony po kursie
    sprzedaży). Płaci magazyn portu, resztę ładownia; towar i złoto trafiają
    do ładowni. Każda transakcja to 1 tik. Blokady liczy też backend.
    Port może też sprzedawać mapę akwenu (#143) — przedmiot w profilu, który
    otwiera bramę „wody nieznane”; nie zajmuje ładowni.
    Reputacja (#142) zmienia ceny (kupno liczone od całego zlecenia, żeby
    rabat kupca nie ginął w zaokrągleniu), a przy embargu port nie handluje.
-->
<script setup lang="ts">
import { computed, ref } from 'vue';
import {
    fleetShipLabel, SHIP_ICONS,
    type FleetShipDTO, type MarketGoodName, type MarketSideName, type ReputationDTO, type ResourcesDTO, type ShipTypeDTO, type TradePortDTO,
} from '../api/expeditionApi';
import { nextThresholds, signed, TIER_LABELS, tierEffect } from '../lib/reputation';
import { RESOURCES, addResources, totalResources } from '../lib/resources';

const props = defineProps<{
    port: TradePortDTO;
    cargo: ResourcesDTO;
    capacity: number;
    fleet: FleetShipDTO[];
    shipTypes: ShipTypeDTO[];
    reputation: ReputationDTO;
    busy: boolean;
}>();

const EMBARGO = 'embargo: port nie handluje z korsarzem';

const emit = defineEmits<{
    trade: [side: MarketSideName, good: MarketGoodName, gold: number];
    sellShip: [ship: FleetShipDTO];
    buyChart: [];
}>();

const GOODS = RESOURCES.filter((r): r is typeof r & { key: MarketGoodName } => r.key !== 'gold');
const goldLabel = RESOURCES.find(r => r.key === 'gold')!;

// porcje po 1 złoto: ile złota naraz
const lots = ref(1);

function setLots(raw: string) {
    const n = Math.floor(Number(raw));
    lots.value = Number.isFinite(n) && n > 0 ? n : 1;
}

const available = computed(() => addResources(props.port.stockpile, props.cargo));

/** Paczka z jednym surowcem. */
function pack(key: keyof ResourcesDTO, amount: number): ResourcesDTO {
    const r: ResourcesDTO = { wood: 0, steel: 0, gold: 0 };
    r[key] = amount;
    return r;
}

/** Ile ładowni ubędzie przy płatności: najpierw płaci magazyn portu (jak CaptainProfile::trade). */
function paidFromCargo(paid: ResourcesDTO): number {
    return RESOURCES.reduce((sum, r) => sum + Math.max(0, paid[r.key] - props.port.stockpile[r.key]), 0);
}

/** Kupno od całego zlecenia, jak MarketRate::buyTotal: floor(złoto × kurs bazowy × 100 / mnożnik), nie mniej niż złoto. */
function buyTotal(good: MarketGoodName, gold: number): number {
    return Math.max(gold, Math.floor((gold * props.port.baseRate[good].buy * 100) / props.port.pricePercent));
}

/** Ile towaru kupuje 1 złoto po mnożniku reputacji, z jednym miejscem po przecinku. */
function buyPerGold(good: MarketGoodName): string {
    // w dół, żeby podgląd nie obiecywał więcej, niż da zlecenie
    const per = Math.floor((props.port.baseRate[good].buy * 1000) / props.port.pricePercent) / 10;
    return String(per).replace('.', ',');
}

function deal(side: MarketSideName, good: MarketGoodName): { paid: ResourcesDTO; received: ResourcesDTO } {
    const rate = props.port.rate[good];
    return side === 'buy'
        ? { paid: pack('gold', lots.value), received: pack(good, buyTotal(good, lots.value)) }
        : { paid: pack(good, lots.value * rate.sell), received: pack('gold', lots.value) };
}

/** Powód blokady transakcji; null = można. */
function tradeBlock(side: MarketSideName, good: MarketGoodName): string | null {
    if (props.port.embargo) return EMBARGO;
    const { paid, received } = deal(side, good);
    const key = side === 'buy' ? 'gold' : good;
    if (available.value[key] < paid[key]) {
        return side === 'buy' ? 'za mało złota w magazynie portu i ładowni' : 'za mało towaru w magazynie portu i ładowni';
    }
    if (totalResources(props.cargo) - paidFromCargo(paid) + totalResources(received) > props.capacity) {
        return 'zabraknie miejsca w ładowni — rozładuj coś do magazynu portu';
    }
    return null;
}

function amountLabel(key: keyof ResourcesDTO, amount: number): string {
    const r = RESOURCES.find(x => x.key === key)!;
    return `${amount} ${r.genitive}`;
}

// sprzedaż statku: sprawne i uszkodzone osobno, bo mają różną wartość
const shipGroups = computed(() => {
    const byKey = new Map<string, { key: string; label: string; ships: FleetShipDTO[] }>();
    for (const ship of props.fleet) {
        const key = `${ship.type}:${ship.variant ?? ''}:${ship.damaged ? 'damaged' : 'ok'}`;
        if (!byKey.has(key)) byKey.set(key, { key, label: fleetShipLabel(ship), ships: [] });
        byKey.get(key)!.ships.push(ship);
    }
    return [...byKey.values()];
});

/** Statek grupy do sprzedaży: pierwszy bez blokady, żeby statek z bitwy nie zasłaniał reszty grupy. */
function candidate(ships: FleetShipDTO[]): FleetShipDTO {
    return ships.find(s => s.scrapBlock === null) ?? ships[0];
}

/** Powód blokady sprzedaży statku; null = można. */
function saleBlock(ship: FleetShipDTO): string | null {
    if (props.port.embargo) return EMBARGO;
    switch (ship.scrapBlock) {
        case 'in-battle': return 'statek walczy w nierozliczonej bitwie';
        case 'last-seaworthy': return 'ostatnia sprawna jednostka floty — bez niej nie stoczysz bitwy';
    }
    const hold = props.shipTypes.find(t => t.type === ship.type)?.cargoCapacity ?? 0;
    if (totalResources(props.cargo) + (ship.saleValue ?? 0) > props.capacity - hold) {
        return 'bez tego statku ładunek ze złotem nie zmieści się w ładowni — rozładuj go najpierw do magazynu portu';
    }
    return null;
}

/** Powód blokady kupna mapy akwenu; null = można. */
const chartBlock = computed((): string | null => {
    const chart = props.port.chart;
    if (!chart) return null;
    if (chart.owned) return 'mapa jest już w profilu';
    if (props.port.embargo) return EMBARGO;
    if (available.value.gold < chart.price) return `za mało złota: potrzeba ${chart.price}, w magazynie portu i ładowni jest ${available.value.gold}`;
    return null;
});

const confirming = ref<string | null>(null);

function confirmSale(ship: FleetShipDTO) {
    confirming.value = null;
    emit('sellShip', ship);
}
</script>

<template>
    <div class="market">
        <!-- reputacja (#142): poziom, skutek na tym rynku i najbliższe progi -->
        <div class="rep-note" :class="reputation.tier">
            <span>
                ⚖️ Reputacja <b>{{ signed(reputation.value) }}</b> · {{ TIER_LABELS[reputation.tier] }}:
                {{ reputation.embargo ? 'port odmawia handlu, sprzedaży statku i mapy' : tierEffect(reputation.tier, reputation) }}.
            </span>
            <span v-if="reputation.embargo" class="muted small">
                Przeładunek do własnego magazynu w porcie działa. Embargo zdejmuje reputacja powyżej {{ signed(reputation.thresholds.embargo) }} —
                pokonany pirat daje {{ signed(reputation.changes.pirateDefeated) }}.
            </span>
            <span v-else class="muted small">
                Każda transakcja {{ signed(reputation.changes.trade) }}. Progi: {{ nextThresholds(reputation).join(' · ') }}.
            </span>
            <!-- perk ceny ze ścieżki ekonomicznej (#162) mnoży się z reputacją -->
            <span v-if="port.perkPercent < 100" class="small">
                ⚖️ Perk kupiecki: ceny rynku {{ signed(port.perkPercent - 100) }}% · łącznie z reputacją {{ port.pricePercent }}% ceny bazowej.
            </span>
        </div>

        <div class="block">
            <div class="head">
                <span class="kicker">Kurs akwenu · za 1 złoto</span>
                <label class="lots">
                    złota naraz
                    <input
                        type="number"
                        min="1"
                        inputmode="numeric"
                        aria-label="ile złota w jednej transakcji"
                        :value="lots"
                        :disabled="busy"
                        @input="setLots(($event.target as HTMLInputElement).value)"
                    />
                </label>
            </div>
            <ul class="goods">
                <li v-for="g in GOODS" :key="g.key" class="good">
                    <span class="good-name"><span class="res-dot" :style="{ background: g.color }"></span>{{ g.label }}</span>
                    <span class="rate muted">kupno {{ buyPerGold(g.key) }} · sprzedaż {{ port.rate[g.key].sell }}</span>
                    <span class="actions">
                        <button
                            class="btn btn-small btn-secondary"
                            :disabled="busy || tradeBlock('buy', g.key) !== null"
                            :title="tradeBlock('buy', g.key) ?? 'płaci magazyn portu, resztę ładownia · 1 tik'"
                            @click="emit('trade', 'buy', g.key, lots)"
                        >
                            Kup {{ amountLabel(g.key, buyTotal(g.key, lots)) }} za {{ amountLabel('gold', lots) }}
                        </button>
                        <button
                            class="btn btn-small btn-secondary"
                            :disabled="busy || tradeBlock('sell', g.key) !== null"
                            :title="tradeBlock('sell', g.key) ?? 'oddajesz z magazynu portu, resztę z ładowni · 1 tik'"
                            @click="emit('trade', 'sell', g.key, lots)"
                        >
                            Sprzedaj {{ amountLabel(g.key, lots * port.rate[g.key].sell) }} za {{ amountLabel('gold', lots) }}
                        </button>
                    </span>
                    <span v-if="tradeBlock('buy', g.key) && tradeBlock('sell', g.key)" class="why">{{ tradeBlock('buy', g.key) }}</span>
                </li>
            </ul>
            <p class="note">
                Sprzedaż idzie po połowie ceny kupna, kupno liczy się od całego zlecenia. Płacisz z magazynu portu, a brakującą resztę bierze ładownia;
                towar i złoto trafiają do ładowni. Każda transakcja to 1 tik.
                Do wydania: {{ available.gold }} {{ goldLabel.genitive }}.
            </p>
        </div>

        <div v-if="port.chart" class="block">
            <div class="kicker">Mapa akwenu</div>
            <div class="chart" :class="{ owned: port.chart.owned }">
                <span class="chart-name">🗺️ Mapa: {{ port.chart.seaName }}</span>
                <span class="muted small">
                    Prowadzi przez wody nieznane na wschodzie Morza Mgieł. Mapy się nie zużywa: brama zostaje otwarta w obie strony.
                </span>
                <span class="actions">
                    <span v-if="port.chart.owned" class="tag-owned">✓ w profilu</span>
                    <button
                        v-else
                        class="btn btn-small btn-primary"
                        :disabled="busy || chartBlock !== null"
                        :title="chartBlock ?? 'płaci magazyn portu, resztę ładownia · 1 tik'"
                        @click="emit('buyChart')"
                    >
                        Kup mapę za {{ amountLabel('gold', port.chart.price) }}
                    </button>
                </span>
                <span v-if="chartBlock && !port.chart.owned" class="why">{{ chartBlock }}</span>
            </div>
        </div>

        <div class="block">
            <div class="kicker">Sprzedaż statku</div>
            <p class="muted small">Zwrot jak przy złomowaniu (połowa kosztu budowy, za uszkodzony mniej o remont), przeliczony na złoto po kursie sprzedaży. Stocznia nie jest potrzebna.</p>
            <ul class="ships">
                <li v-for="g in shipGroups" :key="g.key" class="ship">
                    <span class="ship-name">{{ SHIP_ICONS[g.ships[0].type] }} {{ g.label }} <span class="muted">({{ g.ships[0].damaged ? 'uszkodzone' : 'sprawne' }}: {{ g.ships.length }})</span></span>
                    <template v-if="confirming === candidate(g.ships).id">
                        <span class="ask">
                            Sprzedać statek: {{ fleetShipLabel(candidate(g.ships)).toLowerCase() }}{{ candidate(g.ships).damaged ? ' (uszkodzony)' : '' }}
                            za {{ amountLabel('gold', candidate(g.ships).saleValue ?? 0) }}? Tego nie da się cofnąć.
                        </span>
                        <button class="btn btn-small btn-sell" :disabled="busy" @click="confirmSale(candidate(g.ships))">Tak, sprzedaj</button>
                        <button class="btn btn-small btn-secondary" :disabled="busy" @click="confirming = null">Anuluj</button>
                    </template>
                    <template v-else>
                        <button
                            class="btn btn-small btn-secondary"
                            :disabled="busy || saleBlock(candidate(g.ships)) !== null"
                            :title="saleBlock(candidate(g.ships)) ?? 'złoto do ładowni · 1 tik'"
                            @click="confirming = candidate(g.ships).id"
                        >
                            Sprzedaj 1 szt. (+{{ amountLabel('gold', candidate(g.ships).saleValue ?? 0) }})
                        </button>
                        <span v-if="saleBlock(candidate(g.ships))" class="why">{{ saleBlock(candidate(g.ships)) }}</span>
                    </template>
                </li>
            </ul>
        </div>
    </div>
</template>

<style scoped>
.market { display: flex; flex-direction: column; gap: 22px; }
.block { display: flex; flex-direction: column; gap: 10px; }
.head { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
.lots { display: inline-flex; align-items: center; gap: 8px; font: 500 11px var(--font-mono); color: var(--text-muted); }
.lots input { width: 56px; height: 30px; border-radius: 8px; border: 1px solid var(--line-btn); background: var(--bg-panel); color: var(--text); text-align: center; font: 600 13px var(--font-mono); }
.goods, .ships { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.good, .ship { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; padding: 12px 14px; border: 1px solid var(--line-card); border-radius: 12px; background: var(--bg-card); }
.good-name, .ship-name { display: inline-flex; align-items: center; gap: 9px; font: 600 14px var(--font-head); }
.good-name { min-width: 80px; }
.rate { font: 500 11px var(--font-mono); }
.actions { display: flex; flex-wrap: wrap; gap: 6px; margin-left: auto; }
.res-dot { width: 12px; height: 12px; border-radius: 3px; }
.why { flex-basis: 100%; font: 500 11px var(--font-mono); color: var(--text-muted); }
.ask { flex-basis: 100%; font: 500 12px var(--font-mono); color: var(--warn); }
.btn-sell { background: transparent; border: 1px solid var(--accent); color: var(--accent); }
.btn-sell:hover:not(:disabled) { background: var(--accent); color: var(--accent-ink); }
.chart { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; padding: 12px 14px; border: 1px solid var(--res-gold); border-radius: 12px; background: var(--bg-card); }
.chart.owned { border-color: var(--line-card); }
.chart-name { font: 600 14px var(--font-head); }
.tag-owned { font: 600 11px var(--font-mono); color: var(--info); }
.rep-note { display: flex; flex-direction: column; gap: 4px; padding: 10px 14px; border: 1px solid var(--line-card); border-radius: 12px; background: var(--bg-card); font: 500 13px var(--font-body); }
.rep-note.merchant { border-color: var(--info); }
.rep-note.corsair { border-color: var(--warn); }
.rep-note.embargo { border-color: var(--danger); }
.note { margin: 0; font: 400 12px var(--font-body); color: var(--text-muted); }
.small { margin: 0; font-size: 12px; }
</style>
