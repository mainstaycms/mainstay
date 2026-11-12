import { defineConfig } from 'vite'
import tailwindcss from '@tailwindcss/vite'
import { omitRulesOf } from '@mainstay/ui/vite'

/*
 | Fixed filenames, as the admin's: the Blade shell cache-busts with
 | ?v={Mainstay::VERSION}. Published into public/vendor/seo.
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
        entryFileNames: 'seo.js',
        chunkFileNames: 'seo-[name].js',
        assetFileNames: 'seo.[ext]',
      },
    },
  },
})
