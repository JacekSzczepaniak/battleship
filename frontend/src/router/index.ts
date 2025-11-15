// typescript
import { createRouter, createWebHistory } from 'vue-router'
import Home from '../views/Home.vue'
import Game from '../components/Game.vue'
const PlaceFleet = () => import('../views/PlaceFleet.vue')

// Uwaga: w prod aplikacja będzie pod /frontend/ więc ustaw base:
const base = import.meta.env.PROD ? '/frontend/' : '/'

const router = createRouter({
    history: createWebHistory(base),
    routes: [
        { path: '/', name: 'home', component: Home },
        { path: '/place-fleet/:id', name: 'place-fleet', component: PlaceFleet },
        { path: '/game/:id', name: 'game', component: Game },
        { path: '/board', redirect: '/' },
    ]
})

export default router
