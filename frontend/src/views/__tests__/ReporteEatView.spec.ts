import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { AxiosError, type AxiosResponse } from 'axios'
import { asentar, usarRelojFalso } from './asentar'
import PrimeVue from 'primevue/config'
import { createPinia, setActivePinia } from 'pinia'
import type { ProyectosServicio } from '@/services/proyectos'
import type { ReportesServicio } from '@/services/reportes'
import { errorDeValidacion } from '@/services/errores'
import type { MontosEat, ReporteEat, ResultadoEat } from '@/types/reportes'
import ReporteEatView from '@/views/reportes/ReporteEatView.vue'
import { useAuthStore } from '@/stores/auth'
import { useProyectoActualStore } from '@/stores/proyectoActual'
import type { Usuario } from '@/types/auth'

const { toast, servicios } = vi.hoisted(() => ({
  toast: { add: vi.fn<(mensaje: object) => void>() },
  servicios: {
    proyectos: null as ProyectosServicio | null,
    reportes: {
      eat: vi.fn<ReportesServicio['eat']>(),
      descargarEat: vi.fn<ReportesServicio['descargarEat']>(),
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

type Monto = number | null

// En el orden de MontosEat: presup. m³ / S/ · consumido m³ / S/ · saldo S/ · real m³ / S/ ·
// proyectado m³ / S/ · EAT m³ / S/ · variación S/. Los nulos son los de las filas aparte.
const m = (
  valores: [Monto, Monto, Monto, Monto, Monto, Monto, Monto, Monto, Monto, Monto, Monto, Monto],
  sobrecosto: boolean,
): MontosEat => {
  const claves = [
    'presupuesto_m3',
    'presupuesto_soles',
    'consumido_m3',
    'consumido_soles',
    'saldo_soles',
    'real_m3',
    'real_soles',
    'proyectado_m3',
    'proyectado_soles',
    'eat_m3',
    'eat_soles',
    'variacion_soles',
  ] as const
  return {
    ...(Object.fromEntries(claves.map((c, i) => [c, valores[i]])) as Omit<MontosEat, 'sobrecosto'>),
    sobrecosto,
  }
}

// Escenario E2: corte al 31/10/2026, cifras calculadas a mano en el documento de la tarea.
const e2: ReporteEat = {
  proyecto: { id: 1, codigo: 'PRY-001', nombre: 'Residencial Los Álamos' },
  fecha_corte: '2026-10-31',
  grupos: [
    {
      grupo_id: 1,
      nombre: 'Estructuras',
      filas: [
        // S1 abierto: proyecta 11 × 310 = 3,410 < 3,520.
        {
          partida_id: 1,
          item: '01.01',
          etiqueta: "Columnas · f'c 210",
          ...m([11, 3520, 7, 2170, 1350, 0, 0, 11, 3410, 11, 3410, 110], false),
        },
        // S2 culminado: real 9 × 360 = 3,240 > 3,080.
        {
          partida_id: 2,
          item: '01.02',
          etiqueta: "Vigas · f'c 280",
          ...m([8.8, 3080, 9, 3240, -160, 9, 3240, 0, 0, 9, 3240, -160], true),
        },
      ],
      subtotal: m([19.8, 6600, 16, 5410, 1190, 9, 3240, 11, 3410, 20, 6650, -50], true),
    },
    {
      grupo_id: 2,
      nombre: 'Losas',
      filas: [
        // S3 abierto: proyecta 5.5 × 310 = 1,705 > 1,430.
        {
          partida_id: 3,
          item: '02.01',
          etiqueta: "Losa aligerada · f'c 210",
          ...m([5.5, 1430, 3, 930, 500, 0, 0, 5.5, 1705, 5.5, 1705, -275], true),
        },
      ],
      subtotal: m([5.5, 1430, 3, 930, 500, 0, 0, 5.5, 1705, 5.5, 1705, -275], true),
    },
  ],
  filas_aparte: [
    {
      tipo: 'guias_sin_sector',
      concepto: 'Guías sin sector',
      aviso: 'Asigne un sector a estas guías: mientras no lo tengan, no suman al total.',
      ...m([0, 0, 1, 310, null, null, null, null, null, null, null, null], false),
    },
    {
      tipo: 'cargo',
      concepto: 'Bombeo',
      ...m([0, 0, null, 150, -150, null, 150, null, 0, null, 150, -150], false),
    },
  ],
  // La guía sin sector no suma: EAT 6,650 + 1,705 + 150 = 8,505.
  total: m([25.3, 8030, 19, 6490, 1540, 9, 3390, 16.5, 5115, 25.5, 8505, -475], true),
}

const sinDatos = { reporte: null, mensaje: 'No hay información para la fecha seleccionada' }

// Misma forma que un error de la API, con el cuerpo ya legible como JSON.
const errorApi = (status: number, message: string) =>
  new AxiosError(message, AxiosError.ERR_BAD_REQUEST, undefined, undefined, {
    status,
    data: { message },
  } as AxiosResponse)

const avisos = () => toast.add.mock.calls.map(([aviso]) => (aviso as { summary: string }).summary)

async function montar(asignados: number[] = [1, 2]) {
  const { crearProyectosEnMemoria } = await import('@/services/proyectos')
  servicios.proyectos = crearProyectosEnMemoria(async () => asignados)
  vi.mocked(servicios.reportes.eat).mockResolvedValue({ reporte: e2, mensaje: null })
  vi.mocked(servicios.reportes.descargarEat).mockResolvedValue(new Blob(['%PDF']))
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().usuario = { rol: { clave: 'gerencia' } } as Usuario
  // Conectada al documento: jsdom solo envía formularios conectados al pulsar un botón submit.
  const vista = mount(ReporteEatView, {
    attachTo: document.body,
    global: { plugins: [PrimeVue, pinia], stubs: { teleport: true } },
  })
  await asentar()
  return vista
}

const boton = (vista: VueWrapper, texto: string) =>
  vista.findAll('button').find((b) => b.text() === texto)!

async function generar(vista: VueWrapper, fecha?: Date) {
  if (fecha) {
    await vista.findComponent({ name: 'DatePicker' }).vm.$emit('update:modelValue', fecha)
  }
  await boton(vista, 'Generar').trigger('click')
  await asentar()
}

const celdas = (vista: VueWrapper, selector: string) =>
  vista.findAll(selector).map((tr) => tr.findAll('th, td').map((c) => c.text()))

enableAutoUnmount(afterEach)
beforeEach(() => {
  usarRelojFalso()
  vi.setSystemTime(new Date(2026, 10, 9, 10, 30))
  toast.add.mockClear()
  vi.mocked(servicios.reportes.eat).mockReset()
  vi.mocked(servicios.reportes.descargarEat).mockReset()
  // El DatePicker de PrimeVue lo usa al montarse; jsdom no lo trae.
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

describe('ReporteEatView (CU15)', () => {
  it('la fecha de corte es hoy por defecto, no admite fechas futuras y no genera sola', async () => {
    const vista = await montar()
    const selector = vista.findComponent({ name: 'DatePicker' })
    const { proyecto } = useProyectoActualStore()

    expect(proyecto?.id).toBe(1)
    expect(vista.text()).toContain(`Reporte EAT – ${proyecto!.nombre}`)
    expect(vista.get('label[for="fecha-corte"]').text()).toBe('Fecha de corte')

    expect(selector.props('modelValue')).toEqual(new Date(2026, 10, 9))
    expect(selector.props('maxDate')).toEqual(new Date(2026, 10, 9))
    expect(selector.props('dateFormat')).toBe('dd/mm/yy')
    expect(servicios.reportes.eat).not.toHaveBeenCalled()
    expect(boton(vista, 'PDF').attributes('disabled')).toBeDefined()
    expect(boton(vista, 'Excel').attributes('disabled')).toBeDefined()
  })

  it('Generar pide el reporte del proyecto actual con la fecha en ISO', async () => {
    const vista = await montar()

    await generar(vista)
    expect(servicios.reportes.eat).toHaveBeenLastCalledWith(1, '2026-11-09')

    await generar(vista, new Date(2026, 9, 31))
    expect(servicios.reportes.eat).toHaveBeenLastCalledWith(1, '2026-10-31')
  })

  it('muestra los grupos, los subtotales, las filas aparte con su aviso y el total en m³ y en S/', async () => {
    const vista = await montar()
    await generar(vista, new Date(2026, 9, 31))

    expect(vista.findAll('thead th').map((th) => th.text())).toEqual([
      'Elemento',
      'Presup. m³',
      'Presup. S/',
      'Consumido m³',
      'Consumido S/',
      'Saldo S/',
      'Real culminado S/',
      'Proyectado m³',
      'Proyectado S/',
      'EAT S/',
      'Variación S/',
    ])
    expect(vista.findAll('[data-grupo-nombre]').map((g) => g.text())).toEqual([
      'Estructuras',
      'Losas',
    ])
    const filas = celdas(vista, '[data-fila]')
    expect(filas[0]![0]).toContain("Columnas · f'c 210")
    expect(filas[0]![0]).toContain('01.01')
    expect(filas[0]!.slice(1)).toEqual([
      '11.00',
      'S/ 3,520.00',
      '7.00',
      'S/ 2,170.00',
      'S/ 1,350.00',
      'S/ 0.00',
      '11.00',
      'S/ 3,410.00',
      'S/ 3,410.00',
      'S/ 110.00',
    ])
    // Vigas: culminado, todo real; variación −160.
    expect(filas[1]!.slice(6)).toEqual([
      'S/ 3,240.00',
      '0.00',
      'S/ 0.00',
      'S/ 3,240.00',
      'S/ -160.00',
    ])
    expect(celdas(vista, '[data-subtotal]')).toEqual([
      [
        'Subtotal Estructuras',
        '19.80',
        'S/ 6,600.00',
        '16.00',
        'S/ 5,410.00',
        'S/ 1,190.00',
        'S/ 3,240.00',
        '11.00',
        'S/ 3,410.00',
        'S/ 6,650.00',
        'S/ -50.00',
      ],
      [
        'Subtotal Losas',
        '5.50',
        'S/ 1,430.00',
        '3.00',
        'S/ 930.00',
        'S/ 500.00',
        'S/ 0.00',
        '5.50',
        'S/ 1,705.00',
        'S/ 1,705.00',
        'S/ -275.00',
      ],
    ])
    expect(celdas(vista, '[data-fila-aparte]')).toEqual([
      [
        'Guías sin sectorAsigne un sector a estas guías: mientras no lo tengan, no suman al total.',
        '0.00',
        'S/ 0.00',
        '1.00',
        'S/ 310.00',
        '—',
        '—',
        '—',
        '—',
        '—',
        '—',
      ],
      [
        'Bombeo',
        '0.00',
        'S/ 0.00',
        '—',
        'S/ 150.00',
        'S/ -150.00',
        'S/ 150.00',
        '—',
        'S/ 0.00',
        'S/ 150.00',
        'S/ -150.00',
      ],
    ])
    expect(vista.findAll('[data-aviso]').map((a) => a.text())).toEqual([
      'Asigne un sector a estas guías: mientras no lo tengan, no suman al total.',
    ])
    // El total es el de la API, sin la guía sin sector.
    expect(celdas(vista, 'tfoot tr')).toEqual([
      [
        'Total',
        '25.30',
        'S/ 8,030.00',
        '19.00',
        'S/ 6,490.00',
        'S/ 1,540.00',
        'S/ 3,390.00',
        '16.50',
        'S/ 5,115.00',
        'S/ 8,505.00',
        'S/ -475.00',
      ],
    ])
  })

  it('resalta en rojo las filas, los subtotales y el total con sobrecosto', async () => {
    const vista = await montar()
    await generar(vista, new Date(2026, 9, 31))
    const rojas = (selector: string) =>
      vista.findAll(selector).map((tr) => tr.classes('fila-sobrecosto'))

    expect(rojas('[data-fila]')).toEqual([false, true, true])
    expect(rojas('[data-subtotal]')).toEqual([true, true])
    expect(rojas('[data-fila-aparte]')).toEqual([false, false])
    expect(rojas('tfoot tr')).toEqual([true])
  })

  it('sin datos muestra el mensaje del flujo alternativo y no deja descargar', async () => {
    const vista = await montar()
    vi.mocked(servicios.reportes.eat).mockResolvedValue(sinDatos)
    await generar(vista, new Date(2026, 9, 3))

    expect(vista.get('[data-sin-datos]').text()).toBe(
      'No hay información para la fecha seleccionada',
    )
    expect(vista.find('table').exists()).toBe(false)
    expect(boton(vista, 'PDF').attributes('disabled')).toBeDefined()
    expect(boton(vista, 'Excel').attributes('disabled')).toBeDefined()
  })

  it('descarga el PDF y el Excel del reporte mostrado con su nombre de archivo', async () => {
    const vista = await montar()
    await generar(vista, new Date(2026, 9, 31))
    URL.createObjectURL = vi.fn<() => string>(() => 'blob:reporte')
    URL.revokeObjectURL = vi.fn<() => void>()
    const nombres: string[] = []
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (
      this: HTMLAnchorElement,
    ) {
      nombres.push(this.download)
    })

    await boton(vista, 'PDF').trigger('click')
    await asentar()
    await boton(vista, 'Excel').trigger('click')
    await asentar()

    expect(vi.mocked(servicios.reportes.descargarEat).mock.calls).toEqual([
      [1, '2026-10-31', 'pdf'],
      [1, '2026-10-31', 'excel'],
    ])
    expect(nombres).toEqual([
      'reporte-eat-PRY-001-20261031.pdf',
      'reporte-eat-PRY-001-20261031.xlsx',
    ])
  })

  it('al cambiar la fecha o el proyecto, el reporte anterior deja de mostrarse', async () => {
    const vista = await montar()
    await generar(vista, new Date(2026, 9, 31))
    expect(vista.find('table').exists()).toBe(true)

    await vista
      .findComponent({ name: 'DatePicker' })
      .vm.$emit('update:modelValue', new Date(2026, 9, 30))
    await asentar()
    expect(vista.find('table').exists()).toBe(false)
    expect(boton(vista, 'PDF').attributes('disabled')).toBeDefined()

    await generar(vista)
    useProyectoActualStore().elegir(2)
    await asentar()
    expect(vista.find('table').exists()).toBe(false)
  })

  it('si la descarga responde 422, muestra el texto exacto del servidor', async () => {
    const vista = await montar()
    await generar(vista, new Date(2026, 9, 31))
    vi.mocked(servicios.reportes.descargarEat).mockRejectedValue(
      errorApi(422, 'No hay información para la fecha seleccionada'),
    )

    await boton(vista, 'Excel').trigger('click')
    await asentar()

    expect(servicios.reportes.descargarEat).toHaveBeenCalledWith(1, '2026-10-31', 'excel')
    expect(avisos()).toEqual(['No hay información para la fecha seleccionada'])
  })

  it('un 403 muestra el texto del servidor', async () => {
    const vista = await montar()
    await generar(vista, new Date(2026, 9, 31))
    vi.mocked(servicios.reportes.descargarEat).mockRejectedValue(
      errorApi(403, 'No tiene acceso a este proyecto.'),
    )

    await boton(vista, 'PDF').trigger('click')
    await asentar()

    expect(avisos()).toEqual(['No tiene acceso a este proyecto.'])
  })

  it('un 401 se avisa en español, nunca con «Unauthenticated.»', async () => {
    const vista = await montar()
    vi.mocked(servicios.reportes.eat).mockRejectedValue(errorApi(401, 'Unauthenticated.'))
    await generar(vista, new Date(2026, 9, 31))

    vi.mocked(servicios.reportes.eat).mockResolvedValue({ reporte: e2, mensaje: null })
    await generar(vista)
    vi.mocked(servicios.reportes.descargarEat).mockRejectedValue(errorApi(401, 'Unauthenticated.'))
    await boton(vista, 'PDF').trigger('click')
    await asentar()

    expect(avisos()).toEqual([
      'La sesión expiró. Vuelva a iniciar sesión.',
      'La sesión expiró. Vuelva a iniciar sesión.',
    ])
    expect(vista.text()).not.toContain('Unauthenticated')
  })

  it('sin proyectos asignados lo dice y no deja generar', async () => {
    const vista = await montar([])

    expect(vista.get('[data-sin-proyecto]').text()).toBe(
      'No tiene proyectos activos asignados para generar el reporte.',
    )
    expect(boton(vista, 'Generar').attributes('disabled')).toBeDefined()
    expect(vista.text()).not.toContain('pulse «Generar»')
  })

  describe('una respuesta atrasada no pisa la del proyecto elegido después', () => {
    const delOtro: ReporteEat = {
      ...e2,
      proyecto: { id: 2, codigo: 'PRY-002', nombre: 'Torre Central' },
      grupos: [],
      filas_aparte: [],
      total: m([1, 100, 1, 90, 10, 1, 90, 0, 0, 1, 90, 10], false),
    }

    // Primera consulta en espera; se cambia de proyecto y se vuelve a generar.
    async function dosConsultas() {
      let primera!: { resolver: (r: ResultadoEat) => void; rechazar: (e: unknown) => void }
      const vista = await montar()
      vi.mocked(servicios.reportes.eat).mockReturnValueOnce(
        new Promise((resolver, rechazar) => (primera = { resolver, rechazar })),
      )
      await generar(vista, new Date(2026, 9, 31))
      useProyectoActualStore().elegir(2)
      await asentar()
      vi.mocked(servicios.reportes.eat).mockResolvedValueOnce({ reporte: delOtro, mensaje: null })
      await generar(vista)
      return { vista, primera }
    }

    it('si la primera llega con datos al final, se sigue viendo el reporte del proyecto nuevo', async () => {
      const { vista, primera } = await dosConsultas()

      primera.resolver({ reporte: e2, mensaje: null })
      await asentar()

      expect(vi.mocked(servicios.reportes.eat).mock.calls).toEqual([
        [1, '2026-10-31'],
        [2, '2026-10-31'],
      ])
      expect(celdas(vista, 'tfoot tr')[0]!.slice(1, 3)).toEqual(['1.00', 'S/ 100.00'])
      expect(vista.findAll('[data-grupo-nombre]')).toHaveLength(0)
    })

    it('si la primera falla al final, no avisa el error', async () => {
      const { vista, primera } = await dosConsultas()

      primera.rechazar(errorApi(403, 'No tiene acceso a este proyecto.'))
      await asentar()

      expect(toast.add).not.toHaveBeenCalled()
      expect(vista.find('[data-error-fecha]').exists()).toBe(false)
      expect(celdas(vista, 'tfoot tr')[0]!.slice(1, 3)).toEqual(['1.00', 'S/ 100.00'])
    })
  })

  describe('fecha de corte escrita a mano', () => {
    const escribir = async (vista: VueWrapper, texto: string) => {
      await vista.get('#fecha-corte').setValue(texto)
      await asentar()
    }

    it('una fecha futura se borra y se explica por qué', async () => {
      const vista = await montar()

      await escribir(vista, '')
      await escribir(vista, '31/12/2030')

      expect(vista.get('[data-error-fecha]').text()).toBe(
        'La fecha de corte no puede ser posterior a hoy.',
      )
      expect(vista.findComponent({ name: 'DatePicker' }).props('modelValue')).toBeNull()
      expect(boton(vista, 'Generar').attributes('disabled')).toBeDefined()
      expect(servicios.reportes.eat).not.toHaveBeenCalled()
    })

    it('escrita sobre una fecha anterior, también la borra y lo explica', async () => {
      const vista = await montar()

      await escribir(vista, '31/12/2030')

      expect(vista.get('[data-error-fecha]').text()).toBe(
        'La fecha de corte no puede ser posterior a hoy.',
      )
      expect(vista.findComponent({ name: 'DatePicker' }).props('modelValue')).toBeNull()
      expect(boton(vista, 'Generar').attributes('disabled')).toBeDefined()
    })

    it('al corregirla por una fecha válida, el mensaje desaparece', async () => {
      const vista = await montar()
      await escribir(vista, '31/12/2030')

      await escribir(vista, '31/10/2026')

      expect(vista.find('[data-error-fecha]').exists()).toBe(false)
      expect(vista.findComponent({ name: 'DatePicker' }).props('modelValue')).toEqual(
        new Date(2026, 9, 31),
      )
      await generar(vista)
      expect(servicios.reportes.eat).toHaveBeenLastCalledWith(1, '2026-10-31')
    })

    it('hoy y las fechas pasadas no muestran el mensaje', async () => {
      const vista = await montar()

      await escribir(vista, '09/11/2026')

      expect(vista.find('[data-error-fecha]').exists()).toBe(false)
      expect(vista.findComponent({ name: 'DatePicker' }).props('modelValue')).toEqual(
        new Date(2026, 10, 9),
      )
    })
  })

  it('un 422 muestra el mensaje de la fecha de corte', async () => {
    const vista = await montar()
    const mensaje = 'La fecha de corte no puede ser anterior al inicio del proyecto.'
    vi.mocked(servicios.reportes.eat).mockRejectedValue(
      errorDeValidacion({ fecha_corte: [mensaje] }),
    )
    await generar(vista, new Date(2026, 8, 30))

    expect(vista.get('[data-error-fecha]').text()).toBe(mensaje)
    expect(vista.find('table').exists()).toBe(false)
    expect(toast.add).not.toHaveBeenCalled()
  })
})
