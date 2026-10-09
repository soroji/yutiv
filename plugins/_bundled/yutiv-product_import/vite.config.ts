import { defineConfig } from 'vite';
export default defineConfig({ build: { lib: { entry: 'resources/js/index.ts', name: 'YutivProductImport', formats: ['iife'], fileName: () => 'js/plugin.iife.js' }, outDir: 'dist', emptyOutDir: true } });
