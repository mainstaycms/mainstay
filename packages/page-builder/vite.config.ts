import { defineConfig } from 'vite'
import tailwindcss from '@tailwindcss/vite'

/*
 | Fixed filenames, as the admin's: the Blade shell cache-busts with
 | ?v={Mainstay::VERSION}. Published into public/vendor/page_builder.
 */
export default defineConfig({
  base: './',
  plugins: [tailwindcss()],
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    rollupOptions: {
      input: 'src/main.ts',
      output: {
        entryFileNames: 'page-builder.js',
        chunkFileNames: 'page-builder-[name].js',
        assetFileNames: 'page-builder.[ext]',
      },
    },
  },
})
