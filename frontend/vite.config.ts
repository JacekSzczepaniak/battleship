// typescript
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vite.dev/config/

export default defineConfig(({ mode }) => ({
    plugins: [vue()],
    server: {
        port: 5173,
        strictPort: true,
        proxy: {
            '/api': {
                target: 'http://localhost:8000',
                changeOrigin: true
            }
        }
    },
    // w prod budujemy do sub-ścieżki /frontend/
    base: mode === 'production' ? '/frontend/' : '/'
}))
