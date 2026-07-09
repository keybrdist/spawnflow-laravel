import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@spawnflow-dx/react-shadcn': path.resolve(__dirname, '../react-shadcn/src/index.ts'),
      '@spawnflow-dx/core': path.resolve(__dirname, '../core/src/index.ts'),
    },
  },
});
