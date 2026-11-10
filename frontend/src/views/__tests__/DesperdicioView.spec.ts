import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { asentar, usarRelojFalso } from './asentar'
import PrimeVue from 'primevue/config'
import { createPinia, setActivePinia } from 'pinia'
import type { ProyectosServicio } from '@/services/proyectos'
import type { DesperdicioServicio } from '@/services/desperdicio'
import type { ControlDesperdicio } from '@/types/desperdicio'
import DesperdicioView from '@/views/DesperdicioView.vue'
import { useAuthStore } from '@/stores/auth'
import type { Usuario } from '@/types/auth'

const { toast, servicios } = vi.hoisted(() => ({
  toast: { add: vi.fn<(mensaje: object) => void>() },
  servicios: {
    proyectos: null as ProyectosServicio | null,
    desperdicio: {
      consultar: vi.fn<DesperdicioServicio['consultar']>(),
    } satisfies DesperdicioServicio,
  },
}))

vi.mock('primevue/usetoast', () => ({ useToast: () => toast }))
// El gráfico necesita canvas, que jsdom no trae.
// El stub muestra los rótulos para comprobar que no se repiten.
vi.mock('primevue/chart', () => ({
  default: {
    name: 'Chart',
    props: ['data'],
    template: '<div data-grafico>{{ data.labels.join("|") }}</div>',
  },
}))

vi.mock('@/services/proyectos', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/proyectos')>()
  return {
    ...real,
    get proyectosServicio() {
      return servicios.proyectos
    },
  }
})

vi.mock('@/services/desperdicio', () => ({
  get desperdicioServicio() {
    return servicios.desperdicio
  },
}))

const fila = (
  etiqueta: string,
  presup: number,
  vaciado: number,
  supera: boolean,
  nivel = 'Piso 1',
) => ({
  id: `sector-${nivel}-${etiqueta}`,
  etiqueta,
  nivel,
  elemento: 'Columna · f’c 280',
  item: '03.01',
  descripcion: 'COLUMNAS',
  presupuestado_m3: presup,
  vaciado_m3: vaciado,
  diferencia_m3: vaciado - presup,
  desperdicio_pct: vaciado > 0 ? Math.max(0, ((vaciado - presup) / presup) * 100) : null,
  limite_m3: presup * 1.05,
  supera_limite: supera,
})

const conVaciados: ControlDesperdicio = {
  detalle: 'sector',
  hay_vaciados: true,
  filas: [fila('Sector A', 100, 106, true), fila('Sector B', 80, 60, false)],
}

async function montar(respuesta: ControlDesperdicio) {
  const { crearProyectosEnMemoria } = await import('@/services/proyectos')
  servicios.proyectos = crearProyectosEnMemoria(async () => [1, 2])
  vi.mocked(servicios.desperdicio.consultar).mockResolvedValue(respuesta)
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().usuario = { rol: { clave: 'gerencia' } } as Usuario
  const vista = mount(DesperdicioView, {
    global: { plugins: [PrimeVue, pinia], stubs: { teleport: true } },
  })
  await asentar()
  return vista
}

enableAutoUnmount(afterEach)
beforeEach(() => {
  usarRelojFalso()
  toast.add.mockClear()
  vi.mocked(servicios.desperdicio.consultar).mockReset()
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

describe('DesperdicioView (CU13)', () => {
  it('al entrar consulta por sector y muestra presupuestado, vaciado, diferencia y porcentaje', async () => {
    const vista = await montar(conVaciados)

    expect(servicios.desperdicio.consultar).toHaveBeenCalledWith(expect.any(Number), 'sector')
    const celdas = vista
      .findAll('tbody tr')[0]!
      .findAll('td')
      .map((td) => td.text())
    expect(celdas[0]).toContain('Sector A')
    expect(celdas).toEqual(expect.arrayContaining(['100.00', '106.00', '+6.00', '+6.00 %']))
  })

  it('al elegir otro detalle consulta sola y cambia la tabla cuando llega la respuesta', async () => {
    const vista = await montar(conVaciados)
    const encabezados = () => vista.findAll('thead th').map((th) => th.text())
    let responder!: (r: ControlDesperdicio) => void
    vi.mocked(servicios.desperdicio.consultar).mockReturnValueOnce(
      new Promise((resolver) => (responder = resolver)),
    )

    await vista.findComponent({ name: 'Select' }).vm.$emit('update:modelValue', 'nivel')
    await asentar()
    expect(servicios.desperdicio.consultar).toHaveBeenLastCalledWith(expect.any(Number), 'nivel')
    // Mientras carga, la tabla sigue entera con el detalle anterior.
    expect(encabezados()).toEqual(expect.arrayContaining(['Sector', 'Nivel']))

    responder({
      detalle: 'nivel',
      hay_vaciados: true,
      filas: [{ ...fila('Piso 1', 160, 114.5, false), id: 'nivel-1', nivel: 'Piso 1' }],
    })
    await asentar()
    expect(encabezados()[0]).toBe('Nivel')
    expect(encabezados()).not.toContain('Sector')
    // La tabla es de solo lectura: sin botones de acción por fila.
    expect(vista.find('table button').exists()).toBe(false)
  })

  it('a Gerencia le ofrece generar el reporte de corte de mes (CU16)', async () => {
    const vista = await montar(conVaciados)

    expect(vista.find('[data-corte-mes]').exists()).toBe(true)
  })

  it('distingue en el gráfico los sectores de igual nombre en distintos niveles', async () => {
    const vista = await montar({
      detalle: 'sector',
      hay_vaciados: true,
      filas: [fila('Sector A', 10, 5, false, 'Piso 1'), fila('Sector A', 10, 5, false, 'Piso 2')],
    })

    expect(vista.findAll('tbody tr')).toHaveLength(2)
    expect(vista.get('[data-grafico]').text()).toBe('Piso 1 · Sector A|Piso 2 · Sector A')
  })

  it('por elemento muestra el ítem y lo agrega al rótulo solo si el elemento se repite', async () => {
    const elemento = (id: string, etiqueta: string, item: string | null, descripcion: string) => ({
      ...fila(etiqueta, 10, 5, false),
      id,
      nivel: null,
      item,
      descripcion,
    })
    const vista = await montar({
      detalle: 'elemento',
      hay_vaciados: true,
      filas: [
        elemento('elemento-1', 'Columna · f’c 280', '03.01', 'COLUMNAS SÓTANO'),
        // El ítem es opcional: sin él, la descripción distingue a la partida.
        elemento('elemento-2', 'Columna · f’c 280', null, 'COLUMNAS PISOS'),
        elemento('elemento-3', 'Losa · f’c 210', null, 'LOSAS'),
      ],
    })
    const textos = vista.findAll('tbody tr').map((tr) => tr.text())

    expect(textos[0]).toContain('Ítem 03.01')
    expect(textos[2]).not.toContain('Ítem')
    expect(vista.get('[data-grafico]').text()).toBe(
      'Columna · f’c 280 (03.01)|Columna · f’c 280 (COLUMNAS PISOS)|Losa · f’c 210',
    )
  })

  it('resalta en rojo lo que supera el factor de desperdicio', async () => {
    const vista = await montar(conVaciados)

    expect(vista.findAll('tbody tr').map((tr) => tr.classes('fila-excedida'))).toEqual([
      true,
      false,
    ])
    expect(vista.find('[data-grafico]').exists()).toBe(true)
  })

  it('avisa cuando aún no hay vaciados y no muestra porcentaje', async () => {
    const vista = await montar({
      detalle: 'sector',
      hay_vaciados: false,
      filas: [fila('Sector A', 100, 0, false)],
    })

    expect(vista.get('[data-sin-vaciados]').text()).toBe('Aún no hay vaciados registrados')
    expect(vista.findAll('tbody tr')[0]!.text()).toContain('—')
  })

  it('si se cambia de detalle dos veces seguidas, se queda con la última respuesta', async () => {
    const vista = await montar(conVaciados)
    let responderNivel!: (r: ControlDesperdicio) => void
    vi.mocked(servicios.desperdicio.consultar)
      .mockReturnValueOnce(new Promise((resolver) => (responderNivel = resolver)))
      .mockResolvedValueOnce({
        detalle: 'elemento',
        hay_vaciados: true,
        filas: [{ ...fila('Columna · f’c 280', 180, 166, false), id: 'elemento-1' }],
      })
    const selector = vista.findComponent({ name: 'Select' })

    await selector.vm.$emit('update:modelValue', 'nivel')
    await selector.vm.$emit('update:modelValue', 'elemento')
    await asentar()
    // La respuesta por nivel llega tarde y no debe pisar la de elemento.
    responderNivel({ ...conVaciados, detalle: 'nivel' })
    await asentar()

    expect(vista.get('thead th').text()).toBe('Elemento estructural')
    expect(vista.findAll('tbody tr')).toHaveLength(1)
  })
})
