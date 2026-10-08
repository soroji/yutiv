import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import path from 'path';
import fs from 'fs';

// 프로젝트 루트를 동적으로 탐색 (artisan 파일 기준 — _bundled/활성 모두 호환)
function findProjectRoot(startDir: string): string {
    let dir = startDir;
    while (dir !== path.dirname(dir)) {
        if (fs.existsSync(path.join(dir, 'artisan'))) return dir;
        dir = path.dirname(dir);
    }
    return path.resolve(startDir, '../..'); // fallback
}

const rootDir = findProjectRoot(__dirname);

export default defineConfig({
  plugins: [react()],
  server: {
    fs: {
      allow: [rootDir],
    },
  },
  test: {
    globals: true,
    environment: 'jsdom',
    setupFiles: [path.resolve(__dirname, 'src/test-setup.ts')],
    include: ['src/**/*.{test,spec}.{ts,tsx}', '__tests__/**/*.{test,spec}.{ts,tsx}'],
  },
  resolve: {
    alias: {
      '@': path.resolve(rootDir, 'resources/js'),
      '@core': path.resolve(rootDir, 'resources/js/core'),
      'react': path.resolve(__dirname, 'node_modules/react'),
      'react-dom': path.resolve(__dirname, 'node_modules/react-dom'),
      'vitest': path.resolve(__dirname, 'node_modules/vitest'),
      '@testing-library/react': path.resolve(__dirname, 'node_modules/@testing-library/react'),
      '@testing-library/user-event': path.resolve(__dirname, 'node_modules/@testing-library/user-event'),
      'axios': path.resolve(__dirname, 'node_modules/axios'),
      '@dnd-kit/core': path.resolve(__dirname, 'node_modules/@dnd-kit/core'),
      '@dnd-kit/sortable': path.resolve(__dirname, 'node_modules/@dnd-kit/sortable'),
      '@dnd-kit/utilities': path.resolve(__dirname, 'node_modules/@dnd-kit/utilities'),
    },
  },
});
