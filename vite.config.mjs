import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'node:path'

export default defineConfig({
  base: './',
  plugins: [vue()],
  build: {
    outDir: 'app',
    emptyOutDir: false,
    cssCodeSplit: false,
    target: 'es2022',
    sourcemap: false,
    license: { fileName: 'js/THIRD_PARTY_LICENSES.md' },
    rollupOptions: {
      input: {
        main: path.resolve('app/src/main.ts'),
        files: path.resolve('app/src/files.ts'),
      },
      output: {
        entryFileNames: 'js/[name].mjs',
        chunkFileNames: 'js/chunks/[name]-[hash].mjs',
        assetFileNames: (asset) => asset.names?.some(name => name.endsWith('.css'))
          ? 'css/archive-tools.css'
          : 'css/assets/[name]-[hash][extname]',
      },
    },
  },
})
