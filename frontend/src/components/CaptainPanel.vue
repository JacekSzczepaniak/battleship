<template>
    <!-- Panel kapitana (#160): dwie ścieżki specjalizacji, postęp do progu, źródła punktów i perki -->
    <div class="captain-panel">
        <div class="head">
            <h3>Specjalizacje</h3>
            <span class="muted small">punkty tylko z akcji · obie ścieżki naraz</span>
        </div>
        <section v-for="t in tracks" :key="t.track" class="track" :class="t.track">
            <div class="track-head">
                <span class="track-name">{{ TRACK_ICONS[t.track] }} {{ TRACK_LABELS[t.track] }}</span>
                <span class="track-level">poziom <b>{{ t.level }}</b> / {{ t.maxLevel }}</span>
            </div>
            <div class="bar" role="progressbar" :aria-valuenow="t.points" :aria-valuemin="t.levelFrom" :aria-valuemax="t.next?.points ?? t.points" :aria-label="`specjalizacja ${TRACK_LABELS[t.track]}`">
                <div :style="{ width: levelProgress(t) + '%' }"></div>
            </div>
            <div class="track-points">
                <b>{{ t.points }}</b> pkt
                <template v-if="t.next"> · do poziomu {{ t.next.level }}: {{ t.next.pointsNeeded }} (próg {{ t.next.points }})</template>
                <template v-else> · najwyższy poziom</template>
            </div>

            <ul class="sources">
                <li v-for="s in t.sources" :key="s.source">
                    <span class="src-name">{{ SOURCE_LABELS[s.source] }}</span>
                    <span class="src-rule">{{ sourceRule(s) }}</span>
                    <span class="src-units">
                        {{ s.units }}<template v-if="s.unitsPerPoint > 1"> · do punktu {{ s.unitsPerPoint - s.units % s.unitsPerPoint }}</template>
                    </span>
                    <span class="src-points">{{ s.points }} pkt</span>
                </li>
            </ul>

            <ol class="perks">
                <li v-for="p in t.perks" :key="p.perk" :class="{ on: p.active, next: !p.active && p.level === t.level + 1 }">
                    <span class="perk-level">{{ p.level }}</span>
                    <span class="perk-name">{{ PERK_LABELS[p.perk] }}</span>
                    <span class="perk-state">{{ p.active ? 'zdobyty' : p.level === t.level + 1 ? 'następny' : '' }}</span>
                </li>
            </ol>
        </section>
        <p class="muted small">Perki działają od zdobycia poziomu i tylko u ciebie — wróg ich nie dostaje.</p>
    </div>
</template>

<script setup lang="ts">
import type { SpecializationTrackDTO } from '../api/expeditionApi';
import { levelProgress, PERK_LABELS, SOURCE_LABELS, sourceRule, TRACK_ICONS, TRACK_LABELS } from '../lib/specializations';

defineProps<{ tracks: SpecializationTrackDTO[] }>();
</script>

<style scoped>
.captain-panel { display: flex; flex-direction: column; gap: 14px; }
.head { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; flex-wrap: wrap; }
.head h3 { margin: 0; font: 700 22px var(--font-head); }
.small { font-size: 11px; }
.track { display: flex; flex-direction: column; gap: 8px; padding: 12px 14px; border-radius: 12px; background: var(--bg-card); border: 1px solid var(--line-card); }
.track-head { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; }
.track-name { font: 700 16px var(--font-head); text-transform: capitalize; }
.track-level { font: 500 12px var(--font-mono); color: var(--text-label); }
.bar { height: 8px; border-radius: 4px; background: var(--bg-panel); overflow: hidden; }
.bar div { height: 100%; background: var(--info); }
.track.combat .bar div { background: var(--warn); }
.track-points { font: 500 12px var(--font-mono); color: var(--text-muted); }
.sources, .perks { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; }
.sources li { display: grid; grid-template-columns: minmax(0, 1fr) auto auto auto; gap: 10px; font: 500 12px var(--font-mono); color: var(--text-muted); }
.src-name { color: var(--text); }
.src-points { min-width: 48px; text-align: right; color: var(--text); }
.perks li { display: grid; grid-template-columns: 22px minmax(0, 1fr) auto; gap: 8px; align-items: center; padding: 6px 8px; border-radius: 8px; border: 1px solid var(--line-card); font: 500 13px var(--font-head); opacity: .6; }
.perks li.on { opacity: 1; border-color: var(--line-strong); background: var(--bg-card-active); }
.perks li.next { opacity: .9; border-style: dashed; }
.perk-level { font: 700 12px var(--font-mono); text-align: center; }
.perk-state { font: 500 10px var(--font-mono); letter-spacing: .08em; text-transform: uppercase; color: var(--info); }
@media (max-width: 560px) {
    .sources li { grid-template-columns: minmax(0, 1fr) auto; }
    .src-rule, .src-units { grid-column: 1; }
}
</style>
