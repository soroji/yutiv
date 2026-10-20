import { defineConfig } from 'vitest/config';
import path from 'path';

const projectRoot = path.resolve(__dirname, '../../..');
export default defineConfig({
    test: { environment: 'jsdom', globals: true, include: ['resources/js/__tests__/**/*.test.{ts,tsx}'], setupFiles: ['./resources/js/tests/setup.ts'] },
    resolve: { alias: { '@core': path.join(projectRoot, 'resources/js/core'), '@': path.join(projectRoot, 'resources/js') } },
});
