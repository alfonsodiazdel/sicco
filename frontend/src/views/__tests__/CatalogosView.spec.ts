import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { asentar, usarRelojFalso } from './asentar'
import PrimeVue from 'primevue/config'
import { createPinia, setActivePinia } from 'pinia'
import CatalogosView from '@/views/CatalogosView.vue'
import { useAuthStore } from '@/stores/auth'
import type { Usuario } from '@/types/auth'

vi.mock('primevue/usetoast', () => ({ useToast: () => ({ add: vi.fn() }) }))
vi.mock('primevue/useconfirm', () => ({ useConfirm: () => ({ require: vi.fn() }) }))

vi.mock('@/services/grupos', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/grupos')>()
  return { ...real, gruposServicio: real.crearGruposEnMemoria() }
})
vi.mock('@/services/elementos', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/elementos')>()
  return { ...real, elementosServicio: real.crearElementosEnMemoria() }
})
vi.mock('@/services/resistencias', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/resistencias')>()
  return { ...real, resistenciasServicio: real.crearResistenciasEnMemoria() }
})

// jsdom no trae ResizeObserver, que la lista de pestañas usa para medir el indicador.
vi.stubGlobal(
  'ResizeObserver',
  class {
    observe() {}
    unobserve() {}
    disconnect() {}
  },
)

async function montar() {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().usuario = { rol: { clave: 'oficina_tecnica' } } as Usuario
  const vista = mount(CatalogosView, {
    global: { plugins: [PrimeVue, pinia], stubs: { teleport: true } },
  })
  await asentar()
  return vista
}

enableAutoUnmount(afterEach)
beforeEach(usarRelojFalso)
afterEach(() => vi.useRealTimers())

describe('CatalogosView', () => {
  it('tiene tres pestañas y abre en Grupos de partidas', async () => {
    const vista = await montar()

    expect(vista.findAll('[role="tab"]').map((t) => t.text())).toEqual([
      'Grupos de partidas',
      'Elementos estructurales',
      "Resistencias f'c",
    ])
    expect(vista.text()).toContain('Muros anclados')
  })

  it('cambia de pestaña y muestra el catálogo elegido', async () => {
    const vista = await montar()
    const pestanas = vista.findAll('[role="tab"]')

    await pestanas[1]!.trigger('click')
    await asentar()
    expect(vista.text()).toContain('Cimiento corrido')

    await pestanas[2]!.trigger('click')
    await asentar()
    expect(vista.text()).toContain("f'c 280 kg/cm²")
  })
})
