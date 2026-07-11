<template>
  <main class="p-6">
    <h2>Rozstaw flotę</h2>
    <p>Ułóż klasyczną flotę 10×10: 1×4, 2×3, 3×2, 4×1. Statki nie mogą na siebie nachodzić ani stykać się bokiem/po skosie.</p>

    <section class="panel">
      <div class="toolbar">
        <div class="segment">
          Orientacja:
          <label><input type="radio" value="h" v-model="orientation"> Poziomo (→)</label>
          <label><input type="radio" value="v" v-model="orientation"> Pionowo (↓)</label>
        </div>
        <div class="segment">
          Do rozmieszczenia:
          <span v-for="(cnt,len) in remainingByLen" :key="len" class="pill">
            {{ len }}×: {{ cnt }}
          </span>
        </div>
        <div class="segment">
          <button class="btn outline" @click="resetFleet" :disabled="loading">Reset</button>
        </div>
      </div>

      <div class="board" role="grid" :style="{ gridTemplateRows: `repeat(${height}, 30px)` }">
        <div class="row" v-for="y in height" :key="y" :style="{ gridTemplateColumns: `repeat(${width}, 30px)` }">
          <div
            class="cell"
            v-for="x in width"
            :key="x"
            :class="cellClass(x-1, y-1)"
            @click="handlePlace(x-1, y-1)"
            @mouseenter="hover(x-1, y-1)"
            @mouseleave="hover(-1, -1)"
            :title="cellTitle(x-1, y-1)"
          />
        </div>
      </div>
    </section>

    <div class="actions">
      <button class="btn" :disabled="loading" @click="useClassicFleet">
        {{ loading ? 'Wysyłanie…' : 'Użyj klasycznej floty' }}
      </button>
      <button class="btn success" :disabled="loading || !allPlaced" @click="submitFleet">
        {{ loading ? 'Wysyłanie…' : 'Zapisz flotę' }}
      </button>
      <button class="btn outline" :disabled="loading" @click="goBack">Wróć</button>
    </div>

    <p v-if="error" class="err">{{ error }}</p>
    <p v-if="hint" class="hint">{{ hint }}</p>
  </main>

</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getGame, placeFleet, type PlayerFleetItem } from '../api/gameApi'

const route = useRoute()
const router = useRouter()
const id = String(route.params.id ?? '')

const loading = ref(false)
const error = ref('')
const hint = ref('Kliknij pole, aby postawić kolejny statek. Zmieniaj orientację w toolbarze.')

// wymiary planszy z gry (10×10 to tylko start do czasu odpowiedzi API)
const width = ref(10)
const height = ref(10)

onMounted(async () => {
  try {
    const g = await getGame(id)
    width.value = g.board.w
    height.value = g.board.h
  } catch {
    // zostają domyślne 10×10; błąd i tak wyjdzie przy zapisie floty
  }
})

// Inwentarz klasyczny: 1×4, 2×3, 3×2, 4×1
const inventory = ref<number[]>([4,3,3,2,2,2,1,1,1,1])
const placed = ref<PlayerFleetItem[]>([])
const orientation = ref<'h'|'v'>('h')
const hoverPos = ref<{x:number,y:number}>({x:-1,y:-1})

const remainingByLen = computed<Record<number, number>>(() => {
  const left: Record<number, number> = {1:0,2:0,3:0,4:0}
  for (const l of inventory.value) left[l] = (left[l] ?? 0) + 1
  return left
})

const allPlaced = computed(() => inventory.value.length === 0)

function resetFleet() {
  placed.value = []
  inventory.value = [4,3,3,2,2,2,1,1,1,1]
  error.value = ''
}

function cellClass(x: number, y: number) {
  const isShip = isShipAt(x,y)
  const preview = isPreviewCell(x,y)
  const can = canStartHere(hoverPos.value.x, hoverPos.value.y, nextLen(), orientation.value)
  return {
    ship: isShip,
    preview: preview && can && !isShip,
    previewBad: preview && !can && !isShip,
  }
}

function cellTitle(x: number, y: number) {
  if (isShipAt(x,y)) return 'Statek'
  if (!inventory.value.length) return 'Wszystkie statki rozmieszczone'
  return `Postaw ${nextLen()}-masztowiec ${orientation.value === 'h' ? 'poziomo' : 'pionowo'}`
}

function nextLen(): number { return inventory.value[0] ?? 0 }

function hover(x: number, y: number) { hoverPos.value = { x, y } }

function isPreviewCell(x:number,y:number): boolean {
  const len = nextLen()
  if (!len) return false
  const sx = hoverPos.value.x
  const sy = hoverPos.value.y
  if (sx < 0 || sy < 0) return false
  for (let i=0;i<len;i++) {
    const cx = sx + (orientation.value === 'h' ? i : 0)
    const cy = sy + (orientation.value === 'v' ? i : 0)
    if (cx === x && cy === y) return true
  }
  return false
}

function isShipAt(x: number, y: number): boolean {
  return placed.value.some(s => {
    for (let i=0;i<s.l;i++) {
      const sx = s.x + (s.o === 'h' ? i : 0)
      const sy = s.y + (s.o === 'v' ? i : 0)
      if (sx === x && sy === y) return true
    }
    return false
  })
}

function isOccupiedOrAdjacent(x: number, y: number): boolean {
  // zajęte lub sąsiednie (8-neighborhood)
  for (const s of placed.value) {
    for (let i=0;i<s.l;i++) {
      const sx = s.x + (s.o === 'h' ? i : 0)
      const sy = s.y + (s.o === 'v' ? i : 0)
      if (Math.abs(sx - x) <= 1 && Math.abs(sy - y) <= 1) return true
    }
  }
  return false
}

function fitsInside(x: number, y: number, len: number, o: 'h'|'v'): boolean {
  if (o === 'h') return x >= 0 && y >= 0 && x + len - 1 < width.value && y < height.value
  return x >= 0 && y >= 0 && x < width.value && y + len - 1 < height.value
}

function canStartHere(x: number, y: number, len: number, o: 'h'|'v'): boolean {
  if (!len) return false
  if (!fitsInside(x,y,len,o)) return false
  // brak kolizji i sąsiadowania
  for (let i=0;i<len;i++) {
    const cx = x + (o === 'h' ? i : 0)
    const cy = y + (o === 'v' ? i : 0)
    if (isOccupiedOrAdjacent(cx, cy)) return false
  }
  return true
}

function handlePlace(x: number, y: number) {
  error.value = ''
  const len = nextLen()
  if (!len) return
  if (!canStartHere(x,y,len,orientation.value)) {
    hint.value = 'Nieprawidłowa pozycja (graniczenie, kolizja lub sąsiedztwo). Wybierz inne pole lub zmień orientację.'
    return
  }
  placed.value.push({ x, y, o: orientation.value, l: len })
  // usuń zużyty element inwentarza (pierwszy)
  inventory.value = inventory.value.slice(1)
  hint.value = inventory.value.length
    ? `Ustaw następny statek: ${nextLen()}-masztowiec (${orientation.value === 'h' ? 'poziomo' : 'pionowo'})`
    : 'Wszystkie statki rozmieszczone — zapisz flotę.'
}

async function submitFleet() {
  if (!id || !allPlaced.value) return
  loading.value = true
  error.value = ''
  try {
    await placeFleet(id, placed.value)
    await router.push({ name: 'game', params: { id } })
  } catch (e: any) {
    error.value = e?.message ?? 'Nie udało się rozstawić floty (backend odrzucił pozycje).'
  } finally {
    loading.value = false
  }
}

// Klasyczna flota 10×10 zgodna z Tests\Support\FleetFactory::classic10x10Array()
function classicFleet(): PlayerFleetItem[] {
  return [
    { x: 0, y: 0, o: 'h', l: 4 },
    { x: 0, y: 2, o: 'h', l: 3 },
    { x: 6, y: 0, o: 'v', l: 3 },
    { x: 5, y: 4, o: 'h', l: 2 },
    { x: 9, y: 0, o: 'v', l: 2 },
    { x: 3, y: 6, o: 'v', l: 2 },
    { x: 0, y: 6, o: 'h', l: 1 },
    { x: 1, y: 8, o: 'h', l: 1 },
    { x: 5, y: 9, o: 'h', l: 1 },
    { x: 8, y: 8, o: 'h', l: 1 },
  ]
}

async function useClassicFleet() {
  if (!id) return
  loading.value = true
  error.value = ''
  try {
    await placeFleet(id, classicFleet())
    await router.push({ name: 'game', params: { id } })
  } catch (e: any) {
    error.value = e?.message ?? 'Nie udało się rozstawić floty'
  } finally {
    loading.value = false
  }
}

function goBack() { router.back() }
</script>

<style scoped>
.panel { margin: 1rem 0; }
.toolbar { display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; margin-bottom: .75rem; }
.toolbar .segment { display: flex; gap: .5rem; align-items: center; }
.pill { display: inline-block; padding: .15rem .4rem; border-radius: 10px; background: #f2f2f2; border: 1px solid #ddd; margin-right: .25rem; }

.board { display: grid; gap: 2px; }
.row { display: grid; gap: 2px; }
.cell { width: 30px; height: 30px; border: 1px solid #cbd5e1; background: #f8fafc; cursor: pointer; }
.cell.ship { background: #94a3b8; }
.cell.preview { outline: 2px solid #16a34a; outline-offset: -2px; background: #dcfce7; }
.cell.previewBad { outline: 2px dashed #ef4444; outline-offset: -2px; background: #fee2e2; }

.actions { display: flex; gap: .5rem; margin: 1rem 0; }
.btn { padding: 0.5rem 1rem; background: #1f6feb; color: #fff; border: 0; border-radius: 4px; cursor: pointer; }
.btn.success { background: #16a34a; }
.btn.outline { background: transparent; color: #1f6feb; border: 1px solid #1f6feb; }
.btn[disabled] { opacity: 0.7; cursor: default; }
.err { color: crimson; margin-top: 0.5rem; }
.hint { color: #475569; margin-top: .25rem; }
</style>
