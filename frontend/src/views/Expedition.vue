<template>
    <main class="p-6 expedition">
        <h1>⚓ Wyprawa</h1>

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

            <!-- Trwająca bitwa -->
            <section v-if="pendingBattle" class="card pending">
                ⚔️ Trwa bitwa o <strong>{{ pendingBattle.islandName }}</strong>.
                <router-link class="btn" :to="{ name: 'game', params: { id: pendingBattle.gameId } }">
                    Wróć do bitwy
                </router-link>
            </section>

            <!-- Trasa wyprawy -->
            <ol class="islands">
                <li
                    v-for="island in expedition.islands"
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
                        <span>🏆 +{{ island.xpWin }} XP</span>
                        <span class="muted">porażka: +{{ island.xpLoss }} XP</span>
                        <span v-if="island.wins || island.losses" class="muted">
                            bilans: {{ island.wins }}W / {{ island.losses }}P
                        </span>
                    </div>
                    <button
                        v-if="island.unlocked"
                        class="btn"
                        :disabled="starting !== null || pendingBattle !== null"
                        @click="onStartBattle(island)"
                    >
                        {{ starting === island.id ? 'Stawianie żagli…' : '⚔️ Bitwa' }}
                    </button>
                    <p v-else class="muted">Wymaga rangi: {{ rankLabel(island.requiredRank) }}</p>
                </li>
            </ol>
        </template>
    </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import {
    createProfile, getExpedition, settleBattle, startIslandBattle,
    RANK_LABELS, type ExpeditionDTO, type IslandDTO, type RankName,
} from '../api/expeditionApi';
import { ApiError } from '../api/http';
import {
    clearCurrentBattle, clearProfileId, getCurrentBattle, getProfileId,
    setCurrentBattle, setProfileId, type CurrentBattle,
} from '../lib/expeditionSession';

const router = useRouter();

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

async function onStartBattle(island: IslandDTO) {
    const profileId = getProfileId();
    if (!profileId) return;
    starting.value = island.id;
    error.value = '';
    try {
        const game = await startIslandBattle(profileId, island.id);
        setCurrentBattle({ profileId, gameId: game.id, islandId: island.id, islandName: island.name });
        await router.push({ name: 'place-fleet', params: { id: game.id } });
    } catch (e: any) {
        error.value = e?.message ?? 'Nie udało się rozpocząć bitwy';
        starting.value = null;
    }
}
</script>

<style scoped>
.expedition { max-width: 720px; }
h1 { margin-bottom: 0.75rem; }
.card { border: 1px solid #cbd5e1; border-radius: 8px; padding: 0.9rem 1rem; margin-bottom: 0.9rem; background: #fff; }
.intro h2 { margin-top: 0; }
.name-form { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
.name-form input { padding: 0.45rem 0.6rem; border: 1px solid #cbd5e1; border-radius: 6px; }
.profile-head { display: flex; gap: 0.6rem; align-items: baseline; }
.rank { color: #1f6feb; font-weight: 600; }
.xp-row { display: flex; gap: 0.6rem; align-items: center; margin-top: 0.4rem; flex-wrap: wrap; }
.xp-bar { flex: 1 1 140px; min-width: 120px; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; }
.xp-fill { height: 100%; background: #1f6feb; transition: width 0.4s ease; }
.pending { display: flex; gap: 0.75rem; align-items: center; background: #fff7ed; border-color: #fed7aa; }
.islands { list-style: none; padding: 0; margin: 0; }
.island.locked { opacity: 0.6; background: #f8fafc; }
.island-head { display: flex; justify-content: space-between; align-items: baseline; gap: 0.6rem; }
.island-head h3 { margin: 0; }
.mode-tag { font-size: 0.8rem; color: #475569; border: 1px solid #cbd5e1; border-radius: 999px; padding: 0.1rem 0.5rem; white-space: nowrap; }
.desc { color: #334155; margin: 0.4rem 0 0.5rem; }
.island-meta { display: flex; gap: 0.9rem; margin-bottom: 0.5rem; flex-wrap: wrap; }
.btn { padding: 0.45rem 0.9rem; background: #1f6feb; color: #fff; border: 0; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
.btn[disabled] { opacity: 0.7; cursor: default; }
.muted { color: #64748b; font-size: 0.9rem; }
.err { color: crimson; }
</style>
