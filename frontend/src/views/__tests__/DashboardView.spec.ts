import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { asentar, usarRelojFalso } from './asentar'
import PrimeVue from 'primevue/config'
import { createPinia, setActivePinia } from 'pinia'
import type { DashboardServicio } from '@/services/dashboard'
import type { Dashboard, ProyectoDashboard } from '@/types/dashboard'
import type { Alerta } from '@/types/alertas'
import DashboardView from '@/views/DashboardView.vue'

const { toast, router, servicio } = vi.hoisted(() => ({
  toast: { add: vi.fn<(mensaje: object) => void>() },
  router: { push: vi.fn<(destino: unknown) => void>() },
  servicio: { consultar: vi.fn<DashboardServicio['consultar']>() } satisfies DashboardServicio,
}))

vi.mock('primevue/usetoast', () => ({ useToast: () => toast }))
vi.mock('vue-router', () => ({ useRouter: () => router }))
vi.mock('@/services/dashboard', () => ({ dashboardServicio: servicio }))
// El gráfico necesita canvas, que jsdom no trae: el stub muestra rótulos y colores.
vi.mock('primevue/chart', () => ({
  default: {
    name: 'Chart',
    props: ['data', 'plugins'],
    template:
      '<div data-grafico>{{ data.labels.join("|") }}/{{ data.datasets[0].backgroundColor.join("|") }}</div>',
  },
}))

const proyecto = (
  id: number,
  codigo: string,
  pct: number | null,
  supera: boolean,
): ProyectoDashboard => ({
  id,
  codigo,
  nombre: `Obra ${codigo}`,
  guias: 2,
  recibido_m3: 12,
  vaciado_m3: 12,
  costo_soles: 3720,
  desperdicio: {
    sectores: 1,
    presupuestado_m3: 10,
    vaciado_m3: 12,
    diferencia_m3: 2,
    desperdicio_pct: pct,
    limite_m3: 11,
    limite_pct: 10,
    supera_limite: supera,
  },
})

const alerta = {
  id: 7,
  fecha: '2026-10-28T15:00:00+00:00',
  estado: 'pendiente',
  exceso_m3: 1,
  proyecto: { id: 1, codigo: 'OBR-01', nombre: 'Obra OBR-01' },
  sector: { id: 3, nombre: 'S1', nivel: 'Piso 1', elemento: "Columnas · f'c 210" },
} as Alerta

const respuesta = (cambios: Partial<Dashboard> = {}): Dashboard => ({
  periodo: {
    desde: '2026-11',
    hasta: '2026-11',
    fecha_inicio: '2026-11-01',
    fecha_cierre: '2026-11-30',
    parcial: true,
  },
  indicadores: {
    proyectos_activos: 2,
    vaciado_m3: 22,
    costo_soles: 6520,
    desperdicio_pct: 10,
    limite_pct: 7.5,
    supera_limite: true,
  },
  proyectos: [proyecto(1, 'OBR-01', 20, true), proyecto(2, 'OBR-02', 0, false)],
  alertas: { pendientes: 3, recientes: [alerta] },
  mensaje: null,
  ...cambios,
})

async function montar(datos: Dashboard = respuesta()) {
  servicio.consultar.mockResolvedValue(datos)
  const pinia = createPinia()
  setActivePinia(pinia)
  const vista = mount(DashboardView, {
    global: { plugins: [PrimeVue, pinia], stubs: { teleport: true } },
  })
  await asentar()
  return vista
}

enableAutoUnmount(afterEach)
beforeEach(() => {
  usarRelojFalso()
  vi.setSystemTime(new Date(2026, 10, 9, 12))
  toast.add.mockClear()
  router.push.mockClear()
  servicio.consultar.mockReset()
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

describe('DashboardView (CU17)', () => {
  it('al entrar consulta el mes en curso y muestra los cuatro indicadores', async () => {
    const vista = await montar()

    expect(servicio.consultar).toHaveBeenCalledWith('2026-11', '2026-11')
    expect(vista.get('[data-indicador="activos"]').text()).toContain('2')
    expect(vista.get('[data-indicador="vaciado"]').text()).toContain('22.00')
    expect(vista.get('[data-indicador="costo"]').text()).toContain('S/ 6,520.00')
    const desperdicio = vista.get('[data-indicador="desperdicio"]')
    expect(desperdicio.text()).toContain('10.00 %')
    expect(desperdicio.text()).toContain('Límite 7.50 %')
    expect(desperdicio.classes()).toContain('bg-red-50')
    expect(vista.find('[data-parcial]').exists()).toBe(true)
  })

  it('grafica el desperdicio por proyecto y resalta el que pasa su límite', async () => {
    const vista = await montar()

    expect(vista.get('[data-grafico]').text()).toBe('OBR-01|OBR-02/#dc2626|#2563eb')
    const filas = vista.findAll('tbody tr').slice(0, 2)
    expect(filas.map((tr) => tr.classes('fila-excedida'))).toEqual([true, false])
    expect(filas[0]!.text()).toContain('20.00 %')
  })

  it('distingue un 0 % medido de un proyecto sin datos, en la tabla y sobre las barras', async () => {
    const vista = await montar(
      respuesta({
        proyectos: [
          proyecto(1, 'OBR-01', 20, true),
          proyecto(2, 'OBR-02', 0, false),
          proyecto(3, 'OBR-03', null, false),
        ],
      }),
    )

    const celdas = vista
      .findAll('tbody tr')
      .slice(0, 3)
      .map((tr) => tr.text())
    expect(celdas[1]).toContain('0.00 %')
    expect(celdas[2]).toContain('Sin datos')
    expect(celdas[2]).not.toContain('%')

    const [plugin] = vista.findComponent({ name: 'Chart' }).props('plugins') as {
      afterDatasetsDraw(grafico: object): void
    }[]
    const fillText = vi.fn<(texto: string) => void>()
    const ctx = { save() {}, restore() {}, fillText }
    const escala = { getPixelForValue: (v: number) => v }
    plugin!.afterDatasetsDraw({ ctx, scales: { x: escala, y: escala } })
    expect(fillText.mock.calls.map(([texto]) => texto)).toEqual(['20.00 %', '0.00 %', 'Sin datos'])
  })

  it('al cambiar el rango vuelve a consultar', async () => {
    const vista = await montar()

    const [desde] = vista.findAllComponents({ name: 'Select' })
    await desde!.vm.$emit('update:modelValue', '2026-09')
    await asentar()

    expect(servicio.consultar).toHaveBeenLastCalledWith('2026-09', '2026-11')
  })

  it('lleva al control de desperdicio y al corte del último mes del proyecto', async () => {
    const vista = await montar()

    await vista.findAll('[data-ver-desperdicio]')[1]!.trigger('click')
    expect(router.push).toHaveBeenLastCalledWith({ path: '/desperdicio', query: { proyecto: 2 } })

    await vista.findAll('[data-corte-mes]')[0]!.trigger('click')
    expect(router.push).toHaveBeenLastCalledWith({
      path: '/reportes/corte-mes',
      query: { proyecto: 1, anio: 2026, mes: 11 },
    })
  })

  it('lista las alertas abiertas con su total', async () => {
    const vista = await montar()

    expect(vista.get('[data-pendientes]').text()).toBe('(3)')
    expect(vista.text()).toContain('Piso 1 – S1 – Columnas')
    await vista.get('[data-ver-alertas]').trigger('click')
    expect(router.push).toHaveBeenLastCalledWith('/alertas')
  })

  it('sin proyectos activos muestra el mensaje y no grafica', async () => {
    const vista = await montar(
      respuesta({
        proyectos: [],
        alertas: { pendientes: 0, recientes: [] },
        mensaje: 'No hay proyectos activos',
      }),
    )

    expect(vista.get('[data-sin-proyectos]').text()).toBe('No hay proyectos activos')
    expect(vista.find('[data-grafico]').exists()).toBe(false)
    expect(vista.text()).toContain('No hay alertas abiertas')
  })

  it('avisa si la consulta falla', async () => {
    servicio.consultar.mockRejectedValue(new Error('sin red'))
    setActivePinia(createPinia())
    mount(DashboardView, { global: { plugins: [PrimeVue], stubs: { teleport: true } } })
    await asentar()

    expect(toast.add).toHaveBeenCalledWith(expect.objectContaining({ severity: 'error' }))
  })
})
