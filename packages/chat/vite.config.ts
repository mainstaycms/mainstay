import { defineConfig } from 'vite'
import tailwindcss from '@tailwindcss/vite'

/*
 | Fixed filenames, as the admin's: the Blade shell cache-busts with
 | ?v={Mainstay::VERSION}. Published into public/vendor/chat.
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
        entryFileNames: 'chat.js',
        chunkFileNames: 'chat-[name].js',
        assetFileNames: 'chat.[ext]',
      },
    },
  },
})
