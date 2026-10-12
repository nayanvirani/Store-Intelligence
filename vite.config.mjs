// Builds the embedded admin's React app (resources/app) into public/spa.
// The built files are committed, like the storefront runtime, so deploys don't need Node.
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  root: 'resources/app',
  base: '/spa/',
  plugins: [react()],
  build: {
    outDir: '../../public/spa',
    emptyOutDir: true,
    sourcemap: false,
    // Hashed names + a manifest (read by Laravel's @vite) so browsers cache each file safely
    // and every chunk imports the exact same entry module.
    manifest: 'manifest.json',
    rollupOptions: {
      input: 'resources/app/main.jsx',
      output: {
        entryFileNames: 'assets/[name]-[hash].js',
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: 'assets/[name]-[hash].[ext]',
      },
    },
  },
});
