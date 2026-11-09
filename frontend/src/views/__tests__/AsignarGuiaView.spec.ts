import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { asentar, usarRelojFalso } from './asentar'
import PrimeVue from 'primevue/config'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { PartidasServicio } from '@/services/partidas'
import type { NivelesServicio } from '@/services/niveles'
import type { AsignacionesServicio } from '@/services/asignaciones'
import AsignarGuiaView from '@/views/guias/AsignarGuiaView.vue'
import { useAuthStore } from '@/stores/auth'
import type { RolClave, Usuario } from '@/types/auth'

const { toast, servicios } = vi.hoisted(() => ({
  toast: { add: vi.fn<(mensaje: object) => void>() },
  servicios: {
    partidas: null as PartidasServicio | null,
    niveles: null as NivelesServicio | null,
    asignaciones: null as AsignacionesServicio | null,
    alertasPendientes: vi.fn(async () => 0),
  },
}))

vi.mock('@/services/alertas', () => ({
  alertasServicio: { pendientes: () => servicios.alertasPendientes() },
}))

vi.mock('primevue/usetoast', () => ({ useToast: () => toast }))

vi.mock('@/services/partidas', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/partidas')>()
  return {
    ...real,
    get partidasServicio() {
      return servicios.partidas
    },
  }
})

vi.mock('@/services/niveles', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/niveles')>()
  return {
    ...real,
    get nivelesServicio() {
      return servicios.niveles
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

async function montar(opciones: { rol?: RolClave; guia?: number; sinSectores?: boolean } = {}) {
  const { crearPartidasEnMemoria } = await import('@/services/partidas')
  const { crearNivelesEnMemoria } = await import('@/services/niveles')
  const { crearAsignacionesEnMemoria } = await import('@/services/asignaciones')
  servicios.partidas = crearPartidasEnMemoria(async () => servicios.niveles!)
  servicios.niveles = crearNivelesEnMemoria(servicios.partidas)
  if (opciones.sinSectores) servicios.niveles.listarSectores = async () => []
  servicios.asignaciones = crearAsignacionesEnMemoria()
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().usuario = { rol: { clave: opciones.rol ?? 'ingeniero_produccion' } } as Usuario
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/guias/sin-sector', name: 'guias-sin-sector', component: { template: '<div />' } },
      { path: '/guias/:id/asignar', name: 'asignar-guia', component: AsignarGuiaView },
      { path: '/niveles', component: { template: '<div />' } },
    ],
  })
  await router.push(`/guias/${opciones.guia ?? 1}/asignar`)
  const vista = mount(AsignarGuiaView, {
    global: { plugins: [PrimeVue, pinia, router], stubs: { teleport: true } },
  })
  await asentar()
  return { vista, router }
}

const select = (vista: VueWrapper, i: number) =>
  vista.findAllComponents(Select).find((s) => s.props('labelId') === `sector-${i}`)!

const numero = (vista: VueWrapper, i: number) =>
  vista.findAllComponents(InputNumber).find((n) => n.props('inputId') === `volumen-${i}`)!

const boton = (vista: VueWrapper, texto: string) =>
  vista.findAll('button').find((b) => b.text().includes(texto))!

async function guardar(vista: VueWrapper) {
  await vista.get('form').trigger('submit')
  await asentar()
}

const errores = (vista: VueWrapper) => vista.findAll('.text-red-600').map((e) => e.text())

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

describe('AsignarGuiaView (CU09)', () => {
  it('muestra la guía con su volumen disponible y propone una fila con todo el volumen', async () => {
    const { vista } = await montar()

    expect(vista.text()).toContain('Asignar guía T001-00012340')
    expect(vista.get('[data-disponible]').text()).toBe('0.00 m³')
    expect(vista.findAll('[data-fila]')).toHaveLength(1)
    expect(numero(vista, 0).props('modelValue')).toBe(8.5)
  })

  it('lista los sectores del proyecto agrupados por nivel', async () => {
    const { vista } = await montar()

    const grupos = select(vista, 0).props('options') as { nivel: string; sectores: unknown[] }[]
    expect(grupos.map((g) => g.nivel)).toEqual(['Sótano 1', 'Piso 1'])
  })

  it('reparte la guía en dos sectores y vuelve a la lista', async () => {
    const { vista, router } = await montar()
    const guardarApi = vi.spyOn(servicios.asignaciones!, 'guardar')
    await select(vista, 0).setValue(1)
    await numero(vista, 0).setValue(5)
    await asentar()
    await boton(vista, 'Agregar sector').trigger('click')
    await asentar()
    expect(numero(vista, 1).props('modelValue')).toBe(3.5)
    await select(vista, 1).setValue(2)
    await guardar(vista)

    expect(guardarApi).toHaveBeenCalledWith(1, [
      { sector_id: 1, volumen_m3: 5 },
      { sector_id: 2, volumen_m3: 3.5 },
    ])
    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ severity: 'success', summary: 'Guía asignada correctamente' }),
    )
    expect(router.currentRoute.value.name).toBe('guias-sin-sector')
  })

  it('no deja asignar más que el volumen de la guía', async () => {
    const { vista } = await montar()
    const guardarApi = vi.spyOn(servicios.asignaciones!, 'guardar')
    await select(vista, 0).setValue(1)
    await numero(vista, 0).setValue(9)
    await guardar(vista)

    expect(errores(vista)).toContain('El volumen asignado supera el de la guía')
    expect(vista.get('[data-disponible]').text()).toBe('-0.50 m³')
    expect(guardarApi).not.toHaveBeenCalled()
  })

  it('exige elegir el sector y no repetirlo', async () => {
    const { vista } = await montar()
    await numero(vista, 0).setValue(4)
    await asentar()
    await boton(vista, 'Agregar sector').trigger('click')
    await asentar()
    await guardar(vista)
    expect(errores(vista)).toEqual(['Este dato es requerido', 'Este dato es requerido'])

    await select(vista, 0).setValue(1)
    await select(vista, 1).setValue(1)
    await guardar(vista)
    expect(errores(vista)).toEqual(['El sector ya está en la asignación'])
  })

  it("avisa si el f'c del sector no es el de la guía", async () => {
    const { vista } = await montar()
    // Las partidas de ejemplo: la guía es f'c 210.
    const partidas = await servicios.partidas!.listar(1)
    const sectores = await servicios.niveles!.listarSectores(1)
    const distinto = sectores.find(
      (s) => partidas.find((p) => p.id === s.presupuesto_id)?.resistencia_id !== 2,
    )!
    await select(vista, 0).setValue(distinto.id)
    await asentar()

    expect(vista.text()).toContain("f'c distinto al de la guía")
  })

  it('pide registrar sectores si el proyecto no tiene (flujo alternativo 3)', async () => {
    const { vista } = await montar({ sinSectores: true })

    expect(vista.text()).toContain('El proyecto no tiene sectores de vaciado')
    expect(vista.find('form').exists()).toBe(false)
  })

  it('el administrador consulta sin poder guardar', async () => {
    const { vista } = await montar({ rol: 'administrador' })

    expect(boton(vista, 'Guardar asignación')).toBeUndefined()
    expect(select(vista, 0).props('disabled')).toBe(true)
  })

  it('avisa que se registró la alerta de consumo (CU14) y refresca el ícono', async () => {
    const { vista } = await montar()
    const real = servicios.asignaciones!.guardar
    vi.spyOn(servicios.asignaciones!, 'guardar').mockImplementation(async (...args) => ({
      ...(await real(...args)),
      sectores_excedidos: [{ id: 1, nombre: 'Sector A' }],
      alertas_registradas: 1,
    }))
    servicios.alertasPendientes.mockClear()
    await select(vista, 0).setValue(1)
    await guardar(vista)

    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({
        severity: 'warn',
        summary: 'El Sector A supera lo presupuestado más el desperdicio',
        detail: expect.stringContaining('Se registró la alerta de consumo'),
      }),
    )
    expect(servicios.alertasPendientes).toHaveBeenCalled()
  })
})
