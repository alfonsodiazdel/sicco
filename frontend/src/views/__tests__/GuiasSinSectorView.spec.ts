import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { asentar, usarRelojFalso } from './asentar'
import PrimeVue from 'primevue/config'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { ProyectosServicio } from '@/services/proyectos'
import type { AsignacionesServicio } from '@/services/asignaciones'
import GuiasSinSectorView from '@/views/guias/GuiasSinSectorView.vue'
import { useAuthStore } from '@/stores/auth'
import type { RolClave, Usuario } from '@/types/auth'

const { toast, servicios } = vi.hoisted(() => ({
  toast: { add: vi.fn<(mensaje: object) => void>() },
  servicios: {
    proyectos: null as ProyectosServicio | null,
    asignaciones: null as AsignacionesServicio | null,
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

vi.mock('@/services/asignaciones', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/asignaciones')>()
  return {
    ...real,
    get asignacionesServicio() {
      return servicios.asignaciones
    },
  }
})

async function montar(rol: RolClave = 'ingeniero_produccion') {
  const { crearProyectosEnMemoria } = await import('@/services/proyectos')
  const { crearAsignacionesEnMemoria } = await import('@/services/asignaciones')
  servicios.proyectos = crearProyectosEnMemoria(async () => [1, 2])
  servicios.asignaciones = crearAsignacionesEnMemoria()
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().usuario = { rol: { clave: rol } } as Usuario
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/guias/sin-sector', name: 'guias-sin-sector', component: GuiasSinSectorView },
      { path: '/guias/:id/asignar', name: 'asignar-guia', component: { template: '<div />' } },
    ],
  })
  await router.push('/guias/sin-sector')
  const vista = mount(GuiasSinSectorView, {
    global: { plugins: [PrimeVue, pinia, router], stubs: { teleport: true } },
  })
  await asentar()
  return { vista, router }
}

const numeros = (vista: VueWrapper) =>
  vista.findAll('tbody tr').map((tr) => tr.find('td span.font-medium').text())

enableAutoUnmount(afterEach)
beforeEach(() => {
  usarRelojFalso()
  toast.add.mockClear()
  // Select y DatePicker de PrimeVue lo usan al montarse; jsdom no lo trae.
  vi.stubGlobal(
    'matchMedia',
    vi.fn<() => object>(() => ({
      matches: false,
      addEventListener: vi.fn<() => void>(),
      removeEventListener: vi.fn<() => void>(),
    })),
  )
})
afterEach(() => vi.useRealTimers())

describe('GuiasSinSectorView (CU10)', () => {
  it('al entrar lista las guías pendientes del proyecto, de la más antigua a la más reciente', async () => {
    const { vista } = await montar()

    expect(numeros(vista)).toEqual(['T001-00012340', 'T001-00012345', 'T001-00012350'])
    const primera = vista
      .findAll('tbody tr')[0]!
      .findAll('td')
      .map((td) => td.text())
    expect(primera).toContain('28/10/2026')
    expect(primera).toContain('8.50')
  })

  it('resalta en rojo las guías con más de 2 días sin asignar', async () => {
    const { vista } = await montar()

    const resaltadas = vista.findAll('tbody tr').map((tr) => tr.classes('guia-atrasada'))
    expect(resaltadas).toEqual([true, false, false])
    expect(vista.get('[data-atrasadas]').text()).toBe('1 guía lleva más de 2 días sin asignar.')
  })

  it('filtra por rango de fechas al pulsar Buscar', async () => {
    const { vista } = await montar()
    const [desde, hasta] = vista.findAllComponents({ name: 'DatePicker' })
    await desde!.vm.$emit('update:modelValue', new Date(2026, 9, 30))
    await hasta!.vm.$emit('update:modelValue', new Date(2026, 10, 1))
    await vista.get('form').trigger('submit')
    await asentar()

    expect(numeros(vista)).toEqual(['T001-00012345'])
  })

  it('no busca con un rango invertido', async () => {
    const { vista } = await montar()
    const [desde, hasta] = vista.findAllComponents({ name: 'DatePicker' })
    await desde!.vm.$emit('update:modelValue', new Date(2026, 10, 1))
    await hasta!.vm.$emit('update:modelValue', new Date(2026, 9, 1))
    await asentar()

    expect(vista.get('#hasta-error').text()).toBe(
      'La fecha final debe ser igual o posterior a la inicial',
    )
    expect(vista.get('button[type="submit"]').attributes('disabled')).toBeDefined()
  })

  it('muestra el mensaje cuando no hay guías pendientes', async () => {
    const { vista } = await montar()
    const [desde] = vista.findAllComponents({ name: 'DatePicker' })
    await desde!.vm.$emit('update:modelValue', new Date(2026, 11, 1))
    await vista.get('form').trigger('submit')
    await asentar()

    expect(vista.text()).toContain('No hay guías sin sector asignado')
  })

  it('el ingeniero abre la asignación de la guía (CU09)', async () => {
    const { vista, router } = await montar()
    await vista.get('button[aria-label="Asignar guía T001-00012340"]').trigger('click')
    await asentar()

    expect(router.currentRoute.value.name).toBe('asignar-guia')
    expect(router.currentRoute.value.params.id).toBe('1')
  })

  it('Oficina técnica consulta sin asignar', async () => {
    const { vista } = await montar('oficina_tecnica')

    expect(numeros(vista)).toHaveLength(3)
    expect(vista.find('button[aria-label^="Asignar guía"]').exists()).toBe(false)
  })

  it('exporta la lista a Excel', async () => {
    const { vista } = await montar()
    const exportar = vi.spyOn(servicios.asignaciones!, 'exportarSinSector')
    URL.createObjectURL = vi.fn<() => string>(() => 'blob:guias')
    URL.revokeObjectURL = vi.fn<() => void>()
    const clic = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

    const boton = vista.findAll('button').find((b) => b.text().includes('Exportar a Excel'))!
    await boton.trigger('click')
    await asentar()

    expect(exportar).toHaveBeenCalledWith(1, { desde: null, hasta: null })
    expect(clic).toHaveBeenCalled()
  })
})
