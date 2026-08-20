import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        include: ['tests/Frontend/**/*.test.{ts,tsx}'],
        passWithNoTests: true,
    },
});
