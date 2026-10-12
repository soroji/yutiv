import { defineConfig } from 'vite';
export default defineConfig({build:{lib:{entry:'resources/js/index.js',name:'YutivLive',formats:['iife']},outDir:'dist',target:'es2020',sourcemap:false,
rollupOptions:{output:{entryFileNames:'js/plugin.iife.js',assetFileNames:info=>info.name?.endsWith('.css')?'css/plugin.css':'assets/[name][extname]'}}}});
