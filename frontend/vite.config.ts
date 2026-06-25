import { defineConfig } from 'vite';

export default defineConfig({
  base: './',
  build: {
    outDir: '../rfq-intake/build',
    emptyOutDir: true,
    cssCodeSplit: false,
    rollupOptions: {
      input: 'src/main.tsx',
      output: {
        entryFileNames: 'rfq-form.js',
        assetFileNames: ({ name }) => (name?.endsWith('.css') ? 'rfq-form.css' : 'assets/[name]-[hash][extname]'),
      },
    },
  },
});

