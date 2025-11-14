// typescript
import { createRouter, createWebHistory } from 'vue-router'
import Home from '../views/Home.vue'
import GameBoard from '../components/GameBoard.vue'

// Uwaga: w prod aplikacja będzie pod /frontend/ więc ustaw base:
const base = import.meta.env.PROD ? '/frontend/' : '/'

const router = createRouter({
    history: createWebHistory(base),
    routes: [
        { path: '/', name: 'home', component: Home },
        { path: '/board', name: 'board', component: GameBoard }
    ]
})

export default router
