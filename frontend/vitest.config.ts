import { fileURLToPath } from 'node:url'
import { mergeConfig, defineConfig, configDefaults } from 'vitest/config'
import viteConfig from './vite.config.ts'

export default mergeConfig(
  viteConfig,
  defineConfig({
    test: {
      environment: 'jsdom',
      exclude: [...configDefaults.exclude, 'e2e/**'],
      // Una vista monta PrimeVue entero: con la CPU repartida entre archivos pasa de 5 s.
      testTimeout: 20000,
      root: fileURLToPath(new URL('./', import.meta.url)),
    },
  }),
)
