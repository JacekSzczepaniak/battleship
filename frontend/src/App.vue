<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';

// Nawigacja z projektu (kafle 01–04) zastąpiona routingiem: pasek kroków
// pokazuje, gdzie w flow bitwy jest gracz. Klikalny jest tylko port.
const route = useRoute();

const STEPS = [
    { n: '01', label: 'Port', route: 'home' },
    { n: '02', label: 'Rozstawianie', route: 'place-fleet' },
    { n: '03', label: 'Bitwa', route: 'game' },
    { n: '04', label: 'Wynik', route: 'result' },
];

const current = computed(() => String(route.name ?? ''));
</script>

<template>
    <div class="shell">
        <header class="masthead">
            <router-link :to="{ name: 'home' }" class="brand">STATKI<span>.</span></router-link>
            <nav class="steps" aria-label="Etapy bitwy">
                <template v-for="step in STEPS" :key="step.n">
                    <router-link
                        v-if="step.route === 'home'"
                        :to="{ name: 'home' }"
                        class="step"
                        :class="{ active: current === step.route }"
                    >
                        <span class="n">{{ step.n }}</span>{{ step.label }}
                    </router-link>
                    <span v-else class="step" :class="{ active: current === step.route }">
                        <span class="n">{{ step.n }}</span>{{ step.label }}
                    </span>
                </template>
                <router-link :to="{ name: 'expedition' }" class="step" :class="{ active: current === 'expedition' }">
                    <span class="n">⚓</span>Rejs
                </router-link>
            </nav>
        </header>
        <router-view />
    </div>
</template>

<style scoped>
.shell {
    max-width: 1512px;
    margin: 0 auto;
    padding: 24px 32px 64px;
}
.masthead {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}
.brand {
    font: 700 24px var(--font-head);
    letter-spacing: .02em;
    color: var(--text);
}
.brand span { color: var(--info); }
.brand:hover { color: var(--text); }
.steps { display: flex; gap: 8px; flex-wrap: wrap; }
.step {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border-radius: 10px;
    background: var(--bg-nav);
    border: 1px solid var(--line-card);
    font: 600 13px var(--font-head);
    color: var(--text-muted);
}
.step .n { font: 600 10px var(--font-mono); color: var(--text-label); }
.step.active { color: var(--text); border-color: var(--accent); }
.step.active .n { color: var(--accent); }
a.step:hover { color: var(--text); border-color: var(--info); }
</style>
