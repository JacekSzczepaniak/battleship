<template>
    <main class="p-6">
        <h1>Witaj w grze</h1>
        <p>Rozpocznij nową rozgrywkę.</p>

        <button class="btn" :disabled="loading" @click="onNewGame">
            {{ loading ? 'Tworzenie…' : 'Nowa gra' }}
        </button>

        <p v-if="error" class="err">{{ error }}</p>
    </main>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { createGame } from '../api/gameApi'

const router = useRouter()
const loading = ref(false)
const error = ref('')

async function onNewGame() {
  loading.value = true
  error.value = ''
  try {
    const g = await createGame()
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
.btn { padding: 0.5rem 1rem; background: #1f6feb; color: #fff; border: 0; border-radius: 4px; cursor: pointer; }
.btn[disabled] { opacity: 0.7; cursor: default; }
.err { color: crimson; margin-top: 0.5rem; }
</style>
