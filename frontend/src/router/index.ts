// typescript
import { createRouter, createWebHistory } from 'vue-router'
import Port from '../views/Port.vue'
const PlaceFleet = () => import('../views/PlaceFleet.vue')
const Battle = () => import('../views/Battle.vue')
const BattleResult = () => import('../views/BattleResult.vue')
const Expedition = () => import('../views/Expedition.vue')

// Uwaga: w prod aplikacja będzie pod /frontend/ więc ustaw base:
const base = import.meta.env.PROD ? '/frontend/' : '/'

const router = createRouter({
    history: createWebHistory(base),
    routes: [
        { path: '/', name: 'home', component: Port },
        { path: '/expedition', name: 'expedition', component: Expedition },
        { path: '/place-fleet/:id', name: 'place-fleet', component: PlaceFleet },
        { path: '/game/:id', name: 'game', component: Battle },
        { path: '/result/:id', name: 'result', component: BattleResult },
        { path: '/board', redirect: '/' },
    ]
})

export default router
