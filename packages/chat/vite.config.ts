import { defineConfig } from 'vite'
import tailwindcss from '@tailwindcss/vite'
import { omitRulesOf } from '@mainstay/ui/vite'

/*
 | Fixed filenames, as the admin's: the Blade shell cache-busts with
 | ?v={Mainstay::VERSION}. Published into public/vendor/chat.
 */
export default defineConfig({
  base: './',
  plugins: [tailwindcss(), omitRulesOf(new URL('../cms/dist/mainstay.css', import.meta.url))],
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    rollupOptions: {
      input: 'src/main.ts',
      output: {
        entryFileNames: 'chat.js',
        chunkFileNames: 'chat-[name].js',
        assetFileNames: 'chat.[ext]',
      },
    },
  },
})
