import { defineConfig } from 'vite'

/*
 | Fixed filename, as the admin's: the Blade shell cache-busts with
 | ?v={Mainstay::VERSION}. Published into public/vendor/ai. Script only, so
 | it brings no stylesheet.
 */
export default defineConfig({
  base: './',
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    rollupOptions: {
      input: 'src/main.ts',
      output: {
        entryFileNames: 'ai.js',
      },
    },
  },
})
