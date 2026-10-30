import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { asentar, usarRelojFalso } from './asentar'
import PrimeVue from 'primevue/config'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import type { ProyectosServicio } from '@/services/proyectos'
import type { PartidasServicio } from '@/services/partidas'
import type { NivelesServicio } from '@/services/niveles'
import NivelesView from '@/views/NivelesView.vue'

const { toast, servicios } = vi.hoisted(() => ({
  toast: { add: vi.fn() },
  servicios: {
    proyectos: null as ProyectosServicio | null,
    partidas: null as PartidasServicio | null,
    niveles: null as NivelesServicio | null,
  },
}))

vi.mock('primevue/usetoast', () => ({ useToast: () => toast }))

// Cada prueba arranca con los datos de ejemplo intactos.
vi.mock('@/services/proyectos', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/proyectos')>()
  return {
    ...real,
    get proyectosServicio() {
      return servicios.proyectos
    },
  }
})

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

type Servicios = { proyectos: ProyectosServicio; partidas: PartidasServicio }

async function montar(preparar?: (s: Servicios) => Promise<void>) {
  const { crearProyectosEnMemoria } = await import('@/services/proyectos')
  const { crearPartidasEnMemoria } = await import('@/services/partidas')
  const { crearNivelesEnMemoria } = await import('@/services/niveles')
  servicios.proyectos = crearProyectosEnMemoria()
  servicios.partidas = crearPartidasEnMemoria()
  servicios.niveles = crearNivelesEnMemoria(servicios.partidas)
  await preparar?.({ proyectos: servicios.proyectos, partidas: servicios.partidas })
  const vista = mount(NivelesView, {
    global: { plugins: [PrimeVue], stubs: { teleport: true } },
  })
  await asentar()
  return vista
}

// Sin la segunda línea que solo se ve en celular.
function filas(vista: VueWrapper) {
  return vista.findAll('tbody tr').map((tr) =>
    tr
      .findAll('td')
      .slice(0, 4)
      .map((td) => (td.find('.sm\\:hidden').exists() ? td.get('span').text() : td.text())),
  )
}

const niveles = (vista: VueWrapper) =>
  vista.findAll('ul[aria-label="Niveles"] button[aria-current]').map((b) => b.text())

const nivelElegido = (vista: VueWrapper) =>
  vista.find('ul[aria-label="Niveles"] button[aria-current="true"]').text()

const selectorProyecto = (vista: VueWrapper) =>
  vista.findAllComponents(Select).find((s) => s.props('ariaLabel') === 'Proyecto')!

const select = (vista: VueWrapper, id: string) =>
  vista.findAllComponents(Select).find((s) => s.props('labelId') === id)!

const numero = (vista: VueWrapper, id: string) =>
  vista.findAllComponents(InputNumber).find((n) => n.props('inputId') === id)!

const errores = (vista: VueWrapper) =>
  Object.fromEntries(vista.findAll('small.text-red-600').map((e) => [e.attributes('id'), e.text()]))

async function elegirProyecto(vista: VueWrapper, id: number) {
  await selectorProyecto(vista).setValue(id)
  await asentar()
}

async function elegirNivel(vista: VueWrapper, nombre: string) {
  const boton = vista
    .findAll('ul[aria-label="Niveles"] button[aria-current]')
    .find((b) => b.text() === nombre)!
  await boton.trigger('click')
  await asentar()
}

async function pulsar(vista: VueWrapper, etiqueta: string) {
  await vista.get(`button[aria-label="${etiqueta}"]`).trigger('click')
  await asentar()
}

async function guardar(vista: VueWrapper) {
  await vista.get('form').trigger('submit')
  await asentar()
}

async function llenarNivel(vista: VueWrapper, nombre: string, orden: number) {
  await vista.get('#nombre_nivel').setValue(nombre)
  await numero(vista, 'orden').setValue(orden)
}

async function llenarSector(
  vista: VueWrapper,
  valores: { nombre: string; partida: number; volumen: number },
) {
  await vista.get('#nombre_sector').setValue(valores.nombre)
  await select(vista, 'presupuesto_id').setValue(valores.partida)
  await numero(vista, 'volumen_presup_m3').setValue(valores.volumen)
}

enableAutoUnmount(afterEach)
beforeEach(usarRelojFalso)
afterEach(() => vi.useRealTimers())

describe('NivelesView', () => {
  beforeEach(() => {
    toast.add.mockClear()
    // Select de PrimeVue lo usa al montarse; jsdom no lo trae.
    vi.stubGlobal(
      'matchMedia',
      vi.fn(() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() })),
    )
  })

  it('lista los niveles por orden y los sectores del primer nivel con su elemento y f’c', async () => {
    const vista = await montar()

    expect(selectorProyecto(vista).props('modelValue')).toBe(1)
    expect(vista.get('h2').text()).toBe('Niveles y sectores – Residencial Los Álamos')
    expect(niveles(vista)).toEqual(['Sótano 1', 'Piso 1', 'Piso 2'])
    expect(nivelElegido(vista)).toBe('Sótano 1')
    expect(vista.text()).toContain('Sectores de vaciado – Sótano 1')
    expect(vista.findAll('th').map((th) => th.text())).toEqual([
      'Sector',
      'Elemento',
      'Volumen presupuestado (m³)',
      'Vaciado (m³)',
      'Acciones',
    ])
    expect(filas(vista)).toEqual([
      ['Zapatas eje 1-3', "Zapata · f'c 210 kg/cm²", '40.00', '32.00'],
      ['Zapatas eje 4-6', "Zapata · f'c 210 kg/cm²", '36.00', '0.00'],
    ])

    await elegirNivel(vista, 'Piso 1')
    expect(vista.text()).toContain('Sectores de vaciado – Piso 1')
    expect(filas(vista).map((f) => f[0])).toEqual(['Sector A', 'Sector B', 'Sector C'])
  })

  it('en celular muestra el elemento bajo el sector y lo vaciado bajo el volumen', async () => {
    const vista = await montar()
    const ocultas = vista.findAll('th').filter((th) => th.classes().includes('hidden'))

    expect(ocultas.map((th) => th.text())).toEqual(['Elemento', 'Vaciado (m³)'])
    expect(vista.get('tbody tr:first-child td:first-child .sm\\:hidden').text()).toBe(
      "Zapata · f'c 210 kg/cm²",
    )
    expect(vista.get('tbody tr:first-child td:nth-child(3) .sm\\:hidden').text()).toBe(
      'Vaciado 32.00',
    )
  })

  it('busca sectores por nombre o elemento', async () => {
    const vista = await montar()
    await elegirNivel(vista, 'Piso 1')
    const buscador = vista.get('input[aria-label="Buscar sectores"]')

    expect(buscador.attributes('maxlength')).toBe('80')
    expect(buscador.attributes('placeholder')).toBe('Buscar por nombre o elemento')
    await buscador.setValue('columna')
    expect(filas(vista).map((f) => f[0])).toEqual(['Sector A', 'Sector B'])
    await buscador.setValue('sector c')
    expect(filas(vista).map((f) => f[0])).toEqual(['Sector C'])
    await buscador.setValue('placa')
    expect(vista.text()).toContain('No hay sectores que coincidan.')
  })

  it('registra niveles ordenados por orden y un sector que guarda solo los ids', async () => {
    const vista = await montar()
    await elegirProyecto(vista, 2)
    const crearSector = vi.spyOn(servicios.niveles!, 'crearSector')

    await pulsar(vista, 'Nuevo nivel')
    expect(vista.text()).toContain('Nuevo nivel')
    await llenarNivel(vista, 'Piso 3', 3)
    await guardar(vista)
    await pulsar(vista, 'Nuevo nivel')
    await llenarNivel(vista, 'Piso 2', 2)
    await guardar(vista)

    expect(toast.add).toHaveBeenCalledWith(expect.objectContaining({ summary: 'Registro creado.' }))
    expect(niveles(vista)).toEqual(['Piso 1', 'Piso 2', 'Piso 3'])
    expect(nivelElegido(vista)).toBe('Piso 2')

    await pulsar(vista, 'Nuevo sector')
    expect(vista.text()).toContain('Nuevo sector – Piso 2')
    await llenarSector(vista, { nombre: 'Placa eje A', partida: 4, volumen: 60.5 })
    await guardar(vista)

    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ summary: 'Sector registrado' }),
    )
    expect(vista.find('form').exists()).toBe(false)
    expect(filas(vista)).toEqual([['Placa eje A', "Placa · f'c 350 kg/cm²", '60.50', '0.00']])
    // El sector guarda solo los ids del nivel y de la partida; el elemento y el f'c no se copian.
    expect(crearSector).toHaveBeenCalledWith(6, {
      nombre: 'Placa eje A',
      presupuesto_id: 4,
      volumen_presup_m3: 60.5,
    })
    expect(await servicios.niveles!.listarSectores(2)).toEqual([
      {
        id: 6,
        nivel_id: 6,
        presupuesto_id: 4,
        nombre: 'Placa eje A',
        volumen_presup_m3: 60.5,
        volumen_vaciado_m3: 0,
      },
    ])
  })

  it('edita un nivel y lo reordena', async () => {
    const vista = await montar()
    await pulsar(vista, 'Editar nivel Sótano 1')

    expect(vista.text()).toContain('Editar nivel · Sótano 1')
    expect(numero(vista, 'orden').props('modelValue')).toBe(1)
    await numero(vista, 'orden').setValue(5)
    await guardar(vista)

    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ summary: 'Cambios guardados.' }),
    )
    expect(niveles(vista)).toEqual(['Piso 1', 'Piso 2', 'Sótano 1'])
  })

  it('exige nombre y orden mayor a cero del nivel y no registra nada', async () => {
    const vista = await montar()
    const crearNivel = vi.spyOn(servicios.niveles!, 'crearNivel')
    await pulsar(vista, 'Nuevo nivel')
    await guardar(vista)

    expect(errores(vista)).toEqual({
      'nombre_nivel-error': 'Este dato es requerido',
      'orden-error': 'Este dato es requerido',
    })

    await llenarNivel(vista, 'Piso 3', 0)
    await guardar(vista)
    expect(vista.get('#orden-error').text()).toBe('Ingrese un valor mayor a cero')
    expect(crearNivel).not.toHaveBeenCalled()
    expect(niveles(vista)).toEqual(['Sótano 1', 'Piso 1', 'Piso 2'])
  })

  it('exige los tres datos del sector y no registra nada', async () => {
    const vista = await montar()
    const crearSector = vi.spyOn(servicios.niveles!, 'crearSector')
    await pulsar(vista, 'Nuevo sector')
    await guardar(vista)

    expect(errores(vista)).toEqual({
      'nombre_sector-error': 'Este dato es requerido',
      'presupuesto_id-error': 'Este dato es requerido',
      'volumen_presup_m3-error': 'Este dato es requerido',
    })
    expect(crearSector).not.toHaveBeenCalled()
    expect(filas(vista)).toHaveLength(2)
  })

  it('no deja asignar más volumen que el disponible del elemento en todos los niveles', async () => {
    const vista = await montar()
    const crearSector = vi.spyOn(servicios.niveles!, 'crearSector')
    await pulsar(vista, 'Nuevo sector')

    // Columna: 180.50 − 60.00 − 58.50 de los sectores del Piso 1.
    expect(vista.get('[data-disponible]').text()).toBe(
      "Disponible por elemento: Zapata · f'c 210 kg/cm², 244.00 m³; " +
        "Columna · f'c 280 kg/cm², 62.00 m³; Losa aligerada · f'c 210 kg/cm², 122.50 m³.",
    )
    await llenarSector(vista, { nombre: 'Columnas eje 1', partida: 2, volumen: 62.01 })
    await guardar(vista)

    expect(vista.get('#volumen_presup_m3-error').text()).toBe(
      'El volumen excede lo presupuestado para el elemento',
    )
    expect(crearSector).not.toHaveBeenCalled()

    await numero(vista, 'volumen_presup_m3').setValue(62)
    await guardar(vista)
    expect(crearSector).toHaveBeenCalledOnce()
    expect(filas(vista)[2]).toEqual(['Columnas eje 1', "Columna · f'c 280 kg/cm²", '62.00', '0.00'])
  })

  it('al editar un sector descuenta todo menos su propio volumen', async () => {
    const vista = await montar(async ({ partidas }) => {
      await partidas.actualizar(2, {
        elemento: 'Columna',
        resistencia_fc: "f'c 280 kg/cm²",
        metrado_m3: 160.25,
        factor_desperdicio: 3,
        precio_unitario: 420,
      })
    })
    await elegirNivel(vista, 'Piso 1')
    await pulsar(vista, 'Editar sector Sector A')

    expect(vista.text()).toContain('Editar sector · Sector A')
    // 160.25 − 58.50 de Sector B; los 60.00 de Sector A vuelven a estar disponibles.
    expect(vista.get('[data-disponible]').text()).toContain("Columna · f'c 280 kg/cm², 101.75 m³")
    await numero(vista, 'volumen_presup_m3').setValue(101.76)
    await guardar(vista)
    expect(vista.get('#volumen_presup_m3-error').text()).toBe(
      'El volumen excede lo presupuestado para el elemento',
    )

    await numero(vista, 'volumen_presup_m3').setValue(101.75)
    await guardar(vista)
    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ summary: 'Cambios guardados.' }),
    )
    expect(filas(vista)[0]).toEqual(['Sector A', "Columna · f'c 280 kg/cm²", '101.75', '12.00'])
  })

  it('ofrece solo los proyectos activos y al cambiar de proyecto elige su primer nivel', async () => {
    const vista = await montar(async ({ proyectos }) => {
      const [primero] = await proyectos.listar()
      await proyectos.actualizar(1, { ...primero!, estado: 'Finalizado' })
    })

    const opciones = selectorProyecto(vista).props('options') as { nombre: string }[]
    expect(opciones.map((o) => o.nombre)).toEqual([
      'Torre Empresarial Javier Prado',
      'Condominio Las Palmeras',
    ])
    expect(niveles(vista)).toEqual(['Piso 1'])
  })

  it('al cambiar de proyecto deja de lado el nivel elegido', async () => {
    const vista = await montar()
    await elegirNivel(vista, 'Piso 2')
    await elegirProyecto(vista, 2)

    expect(nivelElegido(vista)).toBe('Piso 1')
    expect(vista.text()).toContain('Sectores de vaciado – Piso 1')
    expect(vista.text()).toContain('No hay sectores que coincidan.')
  })

  it('sin presupuesto avisa y bloquea los dos botones', async () => {
    const vista = await montar()
    await elegirProyecto(vista, 3)

    expect(vista.text()).toContain(
      'Este proyecto aún no tiene presupuesto registrado. Oficina técnica debe registrar sus partidas antes de definir los sectores.',
    )
    expect(vista.get('button[aria-label="Nuevo nivel"]').attributes('disabled')).toBeDefined()
    expect(vista.get('button[aria-label="Nuevo sector"]').attributes('disabled')).toBeDefined()
    expect(vista.text()).toContain('Aún no hay niveles. Registre uno con «Nuevo nivel».')
    expect(vista.text()).toContain('Sectores de vaciado')
    expect(vista.text()).toContain('Registre un nivel para ver sus sectores.')
  })

  it('sin niveles pide registrar primero un nivel antes de un sector', async () => {
    const vista = await montar(async ({ partidas }) => {
      await partidas.crear(3, {
        elemento: 'Zapata',
        resistencia_fc: "f'c 210 kg/cm²",
        metrado_m3: 50,
        factor_desperdicio: 5,
        precio_unitario: 385,
      })
    })
    await elegirProyecto(vista, 3)

    expect(vista.text()).not.toContain('aún no tiene presupuesto registrado')
    expect(vista.get('button[aria-label="Nuevo nivel"]').attributes('disabled')).toBeUndefined()
    await pulsar(vista, 'Nuevo sector')

    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ summary: 'Primero registre un nivel con «Nuevo nivel».' }),
    )
    expect(vista.find('form').exists()).toBe(false)
  })
})
