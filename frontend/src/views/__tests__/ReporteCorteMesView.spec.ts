import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { asentar, usarRelojFalso } from './asentar'
import PrimeVue from 'primevue/config'
import { createPinia, setActivePinia } from 'pinia'
import type { ProyectosServicio } from '@/services/proyectos'
import type { ReportesServicio } from '@/services/reportes'
import { errorDeValidacion } from '@/services/errores'
import type { ReporteCorteMes } from '@/types/reportes'
import ReporteCorteMesView from '@/views/reportes/ReporteCorteMesView.vue'
import { useAuthStore } from '@/stores/auth'
import type { Usuario } from '@/types/auth'

const { toast, servicios } = vi.hoisted(() => ({
  toast: { add: vi.fn<(mensaje: object) => void>() },
  servicios: {
    proyectos: null as ProyectosServicio | null,
    reportes: {
      eat: vi.fn<ReportesServicio['eat']>(),
      descargarEat: vi.fn<ReportesServicio['descargarEat']>(),
      corteMes: vi.fn<ReportesServicio['corteMes']>(),
      descargarCorteMes: vi.fn<ReportesServicio['descargarCorteMes']>(),
    } satisfies ReportesServicio,
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

vi.mock('@/services/reportes', () => ({
  get reportesServicio() {
    return servicios.reportes
  },
}))

const octubre: ReporteCorteMes = {
  proyecto: { id: 1, codigo: 'PRY-001', nombre: 'Residencial Los Álamos' },
  anio: 2026,
  mes: 10,
  fecha_inicio: '2026-10-01',
  fecha_cierre: '2026-10-31',
  parcial: false,
  resumen: {
    guias_recibidas: 4,
    recibido_m3: 14,
    vaciado_m3: 12,
    facturas: 1,
    facturado_m3: 20,
    facturado_soles: 6850,
    guias_pendientes: 3,
    pendiente_m3: 7,
    pendiente_soles: 2300,
  },
  desperdicio: {
    sectores: 2,
    presupuestado_m3: 14,
    vaciado_m3: 16,
    diferencia_m3: 2,
    desperdicio_pct: 14.29,
    limite_m3: 15.4,
    supera_limite: true,
  },
  guias: [
    {
      id: 2,
      numero: 'G2',
      proveedor: 'Unicon',
      fecha: '2026-09-25',
      resistencia: "f'c 210",
      volumen_m3: 2,
      vaciado_m3: 2,
      factura: null,
      factura_fecha: null,
      estado: 'pendiente',
      precio_unitario: 310,
      importe_soles: 620,
      del_mes: false,
    },
    {
      id: 3,
      numero: 'G3',
      proveedor: 'Unicon',
      fecha: '2026-10-05',
      resistencia: "f'c 210",
      volumen_m3: 4,
      vaciado_m3: 4,
      factura: 'F001-1',
      factura_fecha: '2026-10-15',
      estado: 'facturada',
      precio_unitario: 310,
      importe_soles: 1240,
      del_mes: true,
    },
  ],
}

async function montar() {
  const { crearProyectosEnMemoria } = await import('@/services/proyectos')
  servicios.proyectos = crearProyectosEnMemoria(async () => [1, 2])
  vi.mocked(servicios.reportes.corteMes).mockResolvedValue({ reporte: octubre, mensaje: null })
  vi.mocked(servicios.reportes.descargarCorteMes).mockResolvedValue(new Blob(['%PDF']))
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().usuario = { rol: { clave: 'oficina_tecnica' } } as Usuario
  const vista = mount(ReporteCorteMesView, {
    attachTo: document.body,
    global: { plugins: [PrimeVue, pinia], stubs: { teleport: true } },
  })
  await asentar()
  return vista
}

const boton = (vista: VueWrapper, texto: string) =>
  vista.findAll('button').find((b) => b.text() === texto)!

const selectores = (vista: VueWrapper) => vista.findAllComponents({ name: 'Select' })

async function generar(vista: VueWrapper) {
  await boton(vista, 'Generar').trigger('click')
  await asentar()
}

enableAutoUnmount(afterEach)
beforeEach(() => {
  usarRelojFalso()
  vi.setSystemTime(new Date(2026, 10, 9, 10, 30))
  toast.add.mockClear()
  vi.mocked(servicios.reportes.corteMes).mockReset()
  vi.mocked(servicios.reportes.descargarCorteMes).mockReset()
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

describe('ReporteCorteMesView (CU16)', () => {
  it('propone el último mes cerrado y ofrece solo los meses entre el inicio del proyecto y hoy', async () => {
    const vista = await montar()
    const [mes, anio] = selectores(vista)

    expect(mes!.props('modelValue')).toBe(10)
    expect(anio!.props('modelValue')).toBe(2026)
    // El proyecto empezó en marzo de 2026: de marzo a noviembre.
    expect((mes!.props('options') as { valor: number }[]).map((m) => m.valor)).toEqual([
      3, 4, 5, 6, 7, 8, 9, 10, 11,
    ])
    expect(anio!.props('options')).toEqual([2026])
    expect(servicios.reportes.corteMes).not.toHaveBeenCalled()
    expect(boton(vista, 'Excel').attributes('disabled')).toBeDefined()
  })

  it('Generar pide el reporte y muestra los indicadores, el desperdicio y las guías', async () => {
    const vista = await montar()
    await generar(vista)

    expect(servicios.reportes.corteMes).toHaveBeenLastCalledWith(1, 2026, 10)
    expect(vista.get('[data-indicador="recibido"]').text()).toContain('14.00 m³')
    expect(vista.get('[data-indicador="facturado"]').text()).toContain('S/ 6,850.00')
    expect(vista.get('[data-indicador="pendiente"]').text()).toContain('S/ 2,300.00')
    expect(vista.get('[data-indicador="pendiente"]').text()).toContain('3 guías')
    expect(vista.get('[data-desperdicio]').text()).toContain('14.29 %')
    expect(vista.get('[data-desperdicio]').text()).toContain('Supera el desperdicio permitido')

    const filas = vista.findAll('[data-guia]')
    expect(filas).toHaveLength(2)
    expect(filas[0]!.text()).toContain('25/09/2026')
    expect(filas[0]!.find('[data-mes-anterior]').exists()).toBe(true)
    expect(filas[0]!.text()).toContain('Pendiente')
    expect(filas[1]!.text()).toContain('F001-1')
    expect(filas[1]!.text()).toContain('Facturada')
    expect(boton(vista, 'Excel').attributes('disabled')).toBeUndefined()
  })

  it('sin movimientos muestra el mensaje del flujo alternativo', async () => {
    const vista = await montar()
    vi.mocked(servicios.reportes.corteMes).mockResolvedValue({
      reporte: null,
      mensaje: 'No hay movimientos en el mes seleccionado',
    })
    await generar(vista)

    expect(vista.get('[data-sin-datos]').text()).toBe('No hay movimientos en el mes seleccionado')
    expect(vista.findAll('[data-guia]')).toHaveLength(0)
  })

  it('muestra el error de validación del mes', async () => {
    const vista = await montar()
    vi.mocked(servicios.reportes.corteMes).mockRejectedValue(
      errorDeValidacion({ mes: ['El mes no puede ser posterior al mes actual'] }),
    )
    await generar(vista)

    expect(vista.get('[data-error-mes]').text()).toBe('El mes no puede ser posterior al mes actual')
  })

  it('descarga el reporte a la vista con su mes', async () => {
    const vista = await montar()
    URL.createObjectURL = vi.fn<(b: Blob) => string>(() => 'blob:x')
    URL.revokeObjectURL = vi.fn<(u: string) => void>()
    await generar(vista)

    await boton(vista, 'PDF').trigger('click')
    await asentar()

    expect(servicios.reportes.descargarCorteMes).toHaveBeenLastCalledWith(1, 2026, 10, 'pdf')
  })
})
