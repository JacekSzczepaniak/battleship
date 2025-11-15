// typescript
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vite.dev/config/

export default defineConfig(({ mode }) => ({
    plugins: [vue()],
    server: {
        // Nasłuchuj na wszystkich interfejsach/hostach (umożliwia użycie statki.local)
        host: true,
        port: 5173,
        strictPort: true,
        // Proxy nie jest potrzebne, bo używamy VITE_API_URL do wywołań API
    },
    // w prod budujemy do sub-ścieżki /frontend/
    base: mode === 'production' ? '/frontend/' : '/'
}))
