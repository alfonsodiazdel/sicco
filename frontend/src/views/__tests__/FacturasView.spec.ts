import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { asentar, usarRelojFalso } from './asentar'
import PrimeVue from 'primevue/config'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { ProyectosServicio } from '@/services/proyectos'
import type { FacturasServicio } from '@/services/facturas'
import FacturasView from '@/views/facturas/FacturasView.vue'
import { useAuthStore } from '@/stores/auth'
import type { RolClave, Usuario } from '@/types/auth'

const { toast, servicios } = vi.hoisted(() => ({
  toast: { add: vi.fn<(mensaje: unknown) => void>() },
  servicios: {
    proyectos: null as ProyectosServicio | null,
    facturas: null as FacturasServicio | null,
  },
}))

vi.mock('primevue/usetoast', () => ({ useToast: () => toast }))

vi.mock('@/services/proyectos', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/proyectos')>()
  return {
    ...real,
    get proyectosServicio() {
      return servicios.proyectos
    },
  }
})

vi.mock('@/services/facturas', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/facturas')>()
  return {
    ...real,
    get facturasServicio() {
      return servicios.facturas
    },
  }
})

const pdf = () => new File(['%PDF'], 'factura.pdf', { type: 'application/pdf' })

async function montar(rol: RolClave = 'oficina_tecnica') {
  const { crearProyectosEnMemoria } = await import('@/services/proyectos')
  const { crearFacturasEnMemoria } = await import('@/services/facturas')
  servicios.proyectos = crearProyectosEnMemoria(async () => [1])
  servicios.facturas = crearFacturasEnMemoria()
  // Dos facturas de UNICON: una cuadra con sus guías y otra se registró con diferencia.
  await servicios.facturas.registrar({
    proyecto_id: 1,
    proveedor_id: 1,
    numero: 'F001-00002803',
    fecha: '2026-10-28',
    archivo: pdf(),
    lineas: [{ resistencia_id: 4, volumen_m3: 14.5, precio_unitario: 329 }],
    igv: 858.69,
    guia_ids: [2, 3],
  })
  await servicios.facturas.registrar({
    proyecto_id: 1,
    proveedor_id: 1,
    numero: 'F001-00002790',
    fecha: '2026-10-27',
    archivo: pdf(),
    lineas: [{ resistencia_id: 3, volumen_m3: 9, precio_unitario: 291 }],
    igv: 471.42,
    guia_ids: [1],
    confirmado: true,
  })
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().usuario = { rol: { clave: rol } } as Usuario
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/facturas', name: 'facturas', component: FacturasView },
      { path: '/facturas/nueva', name: 'nueva-factura', component: { template: '<div />' } },
    ],
  })
  await router.push('/facturas')
  const vista = mount(FacturasView, {
    global: { plugins: [PrimeVue, pinia, router], stubs: { teleport: true } },
  })
  await asentar()
  return { vista, router }
}

enableAutoUnmount(afterEach)
beforeEach(() => {
  usarRelojFalso()
  toast.add.mockClear()
})
afterEach(() => vi.useRealTimers())

describe('FacturasView (CU11)', () => {
  it('lista las facturas del proyecto, de la más reciente a la más antigua', async () => {
    const { vista } = await montar()

    const numeros = vista.findAll('tbody tr').map((tr) => tr.find('td span.font-medium').text())
    expect(numeros).toEqual(['F001-00002803', 'F001-00002790'])
    expect(vista.findAll('tbody tr')[1]!.text()).toContain('Volumen distinto')
  })

  it('muestra el costo real del proyecto: la suma de las facturas sin IGV', async () => {
    const { vista } = await montar()

    // 14.5 × 329 + 9 × 291
    expect(vista.get('[data-costo-real]').text()).toBe('S/ 7,389.50')
  })

  it('muestra el detalle con las guías vinculadas y su importe', async () => {
    const { vista } = await montar()
    await vista.get('button[aria-label="Ver detalle de la factura F001-00002803"]').trigger('click')
    await asentar()

    const detalle = vista.get('[data-detalle]').text()
    expect(detalle).toContain('T001-00003869')
    expect(detalle).toContain('2,467.50')
    expect(detalle).toContain('S/ 4,770.50')
  })

  it('Oficina técnica abre «Nueva factura»; el administrador solo consulta', async () => {
    const { vista, router } = await montar()
    await vista
      .findAll('button')
      .find((b) => b.text() === 'Nueva factura')!
      .trigger('click')
    await asentar()
    expect(router.currentRoute.value.name).toBe('nueva-factura')

    const admin = await montar('administrador')
    expect(admin.vista.findAll('button').some((b) => b.text() === 'Nueva factura')).toBe(false)
  })
})
