<template>
    <main class="p-6">
        <h1>Witaj w grze</h1>
        <p>Rozpocznij nową rozgrywkę.</p>

        <fieldset class="mode">
            <legend>Wariant zasad</legend>
            <label>
                <input type="radio" value="classic" v-model="mode" />
                Klasyczny — tylko pojedyncze strzały
            </label>
            <label>
                <input type="radio" value="fun" v-model="mode" />
                Fun — bronie specjalne: 🚀 torpeda ×2, 📡 sonar ×3, ✈️ nalot ×1
            </label>
        </fieldset>

        <fieldset class="mode">
            <legend>Rozmiar planszy</legend>
            <label><input type="radio" :value="10" v-model="size" /> 10 × 10 — klasyka</label>
            <label><input type="radio" :value="12" v-model="size" /> 12 × 12 — więcej wody</label>
            <label><input type="radio" :value="15" v-model="size" /> 15 × 15 — polowanie</label>
        </fieldset>

        <button class="btn" :disabled="loading" @click="onNewGame">
            {{ loading ? 'Tworzenie…' : 'Nowa gra' }}
        </button>

        <p v-if="error" class="err">{{ error }}</p>
    </main>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { createGame, type RulesetName } from '../api/gameApi'

const router = useRouter()
const loading = ref(false)
const error = ref('')
const mode = ref<RulesetName>('classic')
const size = ref(10)

async function onNewGame() {
  loading.value = true
  error.value = ''
  try {
    const g = await createGame(mode.value, size.value)
    await router.push({ name: 'place-fleet', params: { id: g.id } })
  } catch (e: any) {
    error.value = e?.message ?? 'Nie udało się utworzyć gry'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
h1 { margin-bottom: 0.5rem; }
.mode { margin: 0.75rem 0 1rem; padding: 0.5rem 0.75rem; border: 1px solid #cbd5e1; border-radius: 6px; display: inline-flex; flex-direction: column; gap: 0.35rem; }
.mode legend { padding: 0 0.3rem; color: #475569; font-size: 0.9rem; }
.mode label { cursor: pointer; }
.btn { padding: 0.5rem 1rem; background: #1f6feb; color: #fff; border: 0; border-radius: 4px; cursor: pointer; }
.btn[disabled] { opacity: 0.7; cursor: default; }
.err { color: crimson; margin-top: 0.5rem; }
</style>
