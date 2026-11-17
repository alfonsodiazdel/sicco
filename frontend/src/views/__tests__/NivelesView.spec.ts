import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { asentar, usarRelojFalso } from './asentar'
import PrimeVue from 'primevue/config'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import type { ProyectosServicio } from '@/services/proyectos'
import type { PartidasServicio } from '@/services/partidas'
import type { NivelesServicio } from '@/services/niveles'
import { createPinia, setActivePinia } from 'pinia'
import NivelesView from '@/views/NivelesView.vue'
import { useAuthStore } from '@/stores/auth'
import { useProyectoActualStore } from '@/stores/proyectoActual'
import type { RolClave, Usuario } from '@/types/auth'

const { toast, confirm, servicios } = vi.hoisted(() => ({
  toast: { add: vi.fn() },
  confirm: { require: vi.fn() },
  servicios: {
    proyectos: null as ProyectosServicio | null,
    partidas: null as PartidasServicio | null,
    niveles: null as NivelesServicio | null,
    asignados: [] as number[],
  },
}))

vi.mock('primevue/usetoast', () => ({ useToast: () => toast }))
vi.mock('primevue/useconfirm', () => ({ useConfirm: () => confirm }))

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

async function montar(
  preparar?: (s: Servicios) => Promise<void>,
  rol: RolClave = 'ingeniero_produccion',
) {
  const { crearProyectosEnMemoria } = await import('@/services/proyectos')
  const { crearPartidasEnMemoria } = await import('@/services/partidas')
  const { crearNivelesEnMemoria } = await import('@/services/niveles')
  servicios.proyectos = crearProyectosEnMemoria(async () => servicios.asignados)
  // Partidas y niveles se consultan entre sí: el metrado no baja de lo repartido en sectores.
  servicios.partidas = crearPartidasEnMemoria(async () => servicios.niveles!)
  servicios.niveles = crearNivelesEnMemoria(servicios.partidas)
  await preparar?.({ proyectos: servicios.proyectos, partidas: servicios.partidas })
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().usuario = { rol: { clave: rol } } as Usuario
  const vista = mount(NivelesView, {
    global: { plugins: [PrimeVue, pinia], stubs: { teleport: true } },
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

// El proyecto se elige en el encabezado (SelectorProyecto); la vista lo toma del store.
const nombresOfrecidos = () => useProyectoActualStore().proyectos.map((p) => p.nombre)

const select = (vista: VueWrapper, id: string) =>
  vista.findAllComponents(Select).find((s) => s.props('labelId') === id)!

const numero = (vista: VueWrapper, id: string) =>
  vista.findAllComponents(InputNumber).find((n) => n.props('inputId') === id)!

const errores = (vista: VueWrapper) =>
  Object.fromEntries(vista.findAll('small.text-red-600').map((e) => [e.attributes('id'), e.text()]))

async function elegirProyecto(id: number) {
  useProyectoActualStore().elegir(id)
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
    servicios.asignados = [1, 2, 3]
    // Select de PrimeVue lo usa al montarse; jsdom no lo trae.
    vi.stubGlobal(
      'matchMedia',
      vi.fn(() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() })),
    )
  })

  it('lista los niveles por orden y los sectores del primer nivel con su elemento y f’c', async () => {
    const vista = await montar()

    expect(useProyectoActualStore().proyectoId).toBe(1)
    expect(vista.get('h2').text()).toBe('Niveles y sectores – Residencial Los Álamos')
    expect(niveles(vista)).toEqual(['Sótano 1', 'Piso 1', 'Piso 2'])
    expect(nivelElegido(vista)).toBe('Sótano 1')
    expect(vista.text()).toContain('Sectores de vaciado – Sótano 1')
    expect(vista.findAll('th').map((th) => th.text())).toEqual([
      'Sector',
      'Elemento',
      'Volumen presupuestado (m³)',
      'Vaciado (m³)',
      'Estado',
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

    expect(ocultas.map((th) => th.text())).toEqual(['Elemento', 'Vaciado (m³)', 'Estado'])
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
    await elegirProyecto(2)
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
        fecha_culminacion: null,
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

    expect(vista.find('[data-disponible]').exists()).toBe(false)
    await llenarSector(vista, { nombre: 'Columnas eje 1', partida: 2, volumen: 62.01 })
    // Columna: 180.50 − 60.00 − 58.50 de los sectores del Piso 1.
    expect(vista.get('[data-disponible]').text()).toBe(
      "Disponible para Columna · f'c 280 kg/cm²: 62.00 m³. Excede en 0.01 m³.",
    )
    await guardar(vista)

    expect(vista.get('#volumen_presup_m3-error').text()).toBe(
      'El volumen excede lo presupuestado para el elemento',
    )
    expect(crearSector).not.toHaveBeenCalled()

    await numero(vista, 'volumen_presup_m3').setValue(62)
    await guardar(vista)
    expect(crearSector).toHaveBeenCalledOnce()
    expect(filas(vista)[2]).toEqual(['Columnas eje 1', "Columna · f'c 280 kg/cm²", '62.00', '0.00'])

    // Con los 62.00 asignados, la Columna queda sin disponible.
    await pulsar(vista, 'Nuevo sector')
    expect(select(vista, 'presupuesto_id').props('options')).toContainEqual(
      expect.objectContaining({ etiqueta: "Columna · f'c 280 kg/cm²", sinDisponible: true }),
    )
    await llenarSector(vista, { nombre: 'Columnas eje 2', partida: 2, volumen: 1 })
    expect(vista.get('[data-disponible]').text()).toBe(
      "Columna · f'c 280 kg/cm² ya no tiene volumen disponible: sus 180.50 m³ están asignados " +
        'a otros sectores. Reduzca el volumen de alguno de ellos o elija otro elemento.',
    )
  })

  it('al editar un sector descuenta todo menos su propio volumen', async () => {
    const vista = await montar(async ({ partidas }) => {
      await partidas.actualizar(2, {
        grupo_id: 6,
        item: '03.02.03.01',
        descripcion: "CONCRETO PREMEZ. F'C=280 kg/cm2 - COLUMNAS",
        elemento: 'Columna',
        resistencia_id: 3,
        metrado_m3: 160.25,
        factor_desperdicio: 3,
        precio_unitario: 420,
      })
    })
    await elegirNivel(vista, 'Piso 1')
    await pulsar(vista, 'Editar sector Sector A')

    expect(vista.text()).toContain('Editar sector · Sector A')
    // 160.25 − 58.50 de Sector B; los 60.00 de Sector A vuelven a estar disponibles.
    expect(vista.get('[data-disponible]').text()).toBe(
      "Disponible para Columna · f'c 280 kg/cm²: 101.75 m³. " +
        'Quedarán 41.75 m³ después de este sector.',
    )
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

  it('el nuevo sector abre vacío después de editar otro', async () => {
    const vista = await montar()
    await elegirNivel(vista, 'Piso 1')
    await pulsar(vista, 'Editar sector Sector A')
    await pulsar(vista, 'Cancelar')
    await pulsar(vista, 'Nuevo sector')

    expect(vista.text()).toContain('Nuevo sector – Piso 1')
    expect((vista.get('#nombre_sector').element as HTMLInputElement).value).toBe('')
    expect(select(vista, 'presupuesto_id').props('modelValue')).toBeUndefined()
    expect(numero(vista, 'volumen_presup_m3').props('modelValue')).toBeUndefined()
  })

  it('muestra lo que queda del elemento mientras se escribe el volumen', async () => {
    const vista = await montar()
    await pulsar(vista, 'Nuevo sector')
    await select(vista, 'presupuesto_id').setValue(2)
    expect(vista.find('[data-restante]').exists()).toBe(false)

    // InputNumber emite input en cada tecla y solo actualiza el v-model al salir del campo.
    numero(vista, 'volumen_presup_m3').vm.$emit('input', { value: 10 })
    await flushPromises()
    expect(vista.get('[data-restante]').text()).toBe('Quedarán 52.00 m³ después de este sector.')

    numero(vista, 'volumen_presup_m3').vm.$emit('input', { value: 70.5 })
    await flushPromises()
    expect(vista.get('[data-restante]').text()).toBe('Excede en 8.50 m³.')

    numero(vista, 'volumen_presup_m3').vm.$emit('input', { value: null })
    await flushPromises()
    expect(vista.find('[data-restante]').exists()).toBe(false)
  })

  it('actualizar la nota al escribir no borra lo escrito en el volumen', async () => {
    const vista = await montar()
    await pulsar(vista, 'Nuevo sector')
    await select(vista, 'presupuesto_id').setValue(2)

    const campo = vista.get('#volumen_presup_m3')
    for (const key of ['6', '0', '.', '5']) {
      const code = key === '.' ? 'Period' : `Digit${key}`
      await campo.trigger('keydown', { key, code })
      await campo.trigger('keypress', { key, code })
      await flushPromises()
    }

    expect((campo.element as HTMLInputElement).value).toBe('60.5')
    expect(vista.get('[data-restante]').text()).toBe('Quedarán 1.50 m³ después de este sector.')
  })

  it('cada elemento de la lista muestra la descripción de su partida', async () => {
    const vista = await montar()
    await pulsar(vista, 'Nuevo sector')

    expect(select(vista, 'presupuesto_id').props('options')).toContainEqual(
      expect.objectContaining({
        etiqueta: "Columna · f'c 280 kg/cm²",
        descripcion: "CONCRETO PREMEZ. F'C=280 kg/cm2 - COLUMNAS",
        sinDisponible: false,
      }),
    )
  })

  it('ofrece solo los proyectos activos y al cambiar de proyecto elige su primer nivel', async () => {
    const vista = await montar(async ({ proyectos }) => {
      const [primero] = await proyectos.listar()
      await proyectos.actualizar(1, { ...primero!, estado: 'Finalizado' })
    })

    expect(nombresOfrecidos()).toEqual([
      'Torre Empresarial Javier Prado',
      'Condominio Las Palmeras',
    ])
    expect(niveles(vista)).toEqual(['Piso 1'])
  })

  it('al cambiar de proyecto deja de lado el nivel elegido', async () => {
    const vista = await montar()
    await elegirNivel(vista, 'Piso 2')
    await elegirProyecto(2)

    expect(nivelElegido(vista)).toBe('Piso 1')
    expect(vista.text()).toContain('Sectores de vaciado – Piso 1')
    expect(vista.text()).toContain('No hay sectores que coincidan.')
  })

  it('sin presupuesto avisa y bloquea los dos botones', async () => {
    const vista = await montar()
    await elegirProyecto(3)

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
        grupo_id: 2,
        item: null,
        descripcion: "CONCRETO F'C=210 kg/cm2 - ZAPATAS",
        elemento: 'Zapata',
        resistencia_id: 2,
        metrado_m3: 50,
        factor_desperdicio: 5,
        precio_unitario: 385,
      })
    })
    await elegirProyecto(3)

    expect(vista.text()).not.toContain('aún no tiene presupuesto registrado')
    expect(vista.get('button[aria-label="Nuevo nivel"]').attributes('disabled')).toBeUndefined()
    await pulsar(vista, 'Nuevo sector')

    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ summary: 'Primero registre un nivel con «Nuevo nivel».' }),
    )
    expect(vista.find('form').exists()).toBe(false)
  })

  it('ofrece solo los proyectos asignados al usuario', async () => {
    servicios.asignados = [2, 3]
    const vista = await montar()

    expect(nombresOfrecidos()).toEqual([
      'Torre Empresarial Javier Prado',
      'Condominio Las Palmeras',
    ])
    expect(useProyectoActualStore().proyectoId).toBe(2)
    expect(vista.get('h2').text()).toBe('Niveles y sectores – Torre Empresarial Javier Prado')
  })

  it('al administrador le muestra niveles y sectores sin ninguna acción de escritura', async () => {
    const vista = await montar(undefined, 'administrador')

    expect(vista.findAll('ul[aria-label="Niveles"] li').length).toBeGreaterThan(0)
    expect(filas(vista).length).toBeGreaterThan(0)
    for (const etiqueta of ['Nuevo nivel', 'Nuevo sector']) {
      expect(vista.find(`button[aria-label="${etiqueta}"]`).exists()).toBe(false)
    }
    expect(vista.find('button[aria-label^="Editar nivel "]').exists()).toBe(false)
    expect(vista.find('button[aria-label^="Editar sector "]').exists()).toBe(false)
  })

  describe('culminación de sectores', () => {
    const estado = (vista: VueWrapper, i: number) =>
      vista.findAll('tbody tr')[i]!.findAll('td')[4]!.text()

    it('muestra el estado de cada sector y, en el culminado, solo reabrir', async () => {
      const vista = await montar()

      // Zapatas eje 1-3 viene culminado en los datos de ejemplo.
      expect(estado(vista, 0)).toBe('Culminado 20/10/2026')
      expect(estado(vista, 1)).toBe('Abierto')
      expect(vista.find('button[aria-label="Editar sector Zapatas eje 1-3"]').exists()).toBe(false)
      expect(vista.find('button[aria-label="Culminar sector Zapatas eje 1-3"]').exists()).toBe(
        false,
      )
      expect(vista.find('button[aria-label="Reabrir sector Zapatas eje 1-3"]').exists()).toBe(true)
      expect(vista.find('button[aria-label="Editar sector Zapatas eje 4-6"]').exists()).toBe(true)
      expect(vista.find('button[aria-label="Culminar sector Zapatas eje 4-6"]').exists()).toBe(true)
    })

    it('culmina un sector con la fecha de hoy por defecto', async () => {
      vi.setSystemTime(new Date(2026, 10, 9, 10))
      const vista = await montar()
      const culminar = vi.spyOn(servicios.niveles!, 'culminarSector')

      await pulsar(vista, 'Culminar sector Zapatas eje 4-6')
      expect(vista.text()).toContain('Culminar sector · Zapatas eje 4-6')
      expect(vista.get('[data-nota-culminar]').text()).toContain('cuenta como costo real')
      await guardar(vista)

      expect(culminar).toHaveBeenCalledWith(5, '2026-11-09')
      expect(toast.add).toHaveBeenCalledWith(
        expect.objectContaining({ summary: 'Sector culminado correctamente' }),
      )
      expect(estado(vista, 1)).toBe('Culminado 09/11/2026')
    })

    it('muestra bajo la fecha el error de validación del backend', async () => {
      const vista = await montar()
      const { errorDeValidacion } = await import('@/services/errores')
      vi.spyOn(servicios.niveles!, 'culminarSector').mockRejectedValue(
        errorDeValidacion({
          fecha_culminacion: [
            'La fecha de culminación no puede ser anterior al inicio del proyecto ni a la última guía del sector',
          ],
        }),
      )

      await pulsar(vista, 'Culminar sector Zapatas eje 4-6')
      await guardar(vista)

      expect(vista.text()).toContain('no puede ser anterior al inicio del proyecto')
      expect(estado(vista, 1)).toBe('Abierto')
    })

    it('reabre un sector culminado después de confirmar', async () => {
      const vista = await montar()
      const reabrir = vi.spyOn(servicios.niveles!, 'reabrirSector')

      await pulsar(vista, 'Reabrir sector Zapatas eje 1-3')
      expect(confirm.require).toHaveBeenCalledWith(
        expect.objectContaining({ header: 'Reabrir sector' }),
      )
      expect(reabrir).not.toHaveBeenCalled()
      const [opciones] = confirm.require.mock.calls[confirm.require.mock.calls.length - 1]!
      await opciones.accept()
      await asentar()

      expect(reabrir).toHaveBeenCalledWith(4)
      expect(estado(vista, 0)).toBe('Abierto')
    })
  })

  describe('eliminación de niveles y sectores', () => {
    async function aceptar() {
      const [opciones] = confirm.require.mock.calls[confirm.require.mock.calls.length - 1]!
      await opciones.accept()
      await asentar()
    }

    it('ofrece eliminar solo niveles sin sectores y sectores abiertos sin vaciado', async () => {
      const vista = await montar()

      expect(vista.find('button[aria-label="Eliminar nivel Sótano 1"]').exists()).toBe(false)
      expect(vista.find('button[aria-label="Eliminar nivel Piso 1"]').exists()).toBe(false)
      expect(vista.find('button[aria-label="Eliminar nivel Piso 2"]').exists()).toBe(true)
      // Zapatas eje 1-3 está culminado; Zapatas eje 4-6 sigue abierto y sin guías.
      expect(vista.find('button[aria-label="Eliminar sector Zapatas eje 1-3"]').exists()).toBe(
        false,
      )
      expect(vista.find('button[aria-label="Eliminar sector Zapatas eje 4-6"]').exists()).toBe(true)

      await elegirNivel(vista, 'Piso 1')
      // Con concreto vaciado: tiene guías asignadas.
      expect(vista.find('button[aria-label^="Eliminar sector "]').exists()).toBe(false)
    })

    it('elimina un sector después de confirmar', async () => {
      const vista = await montar()
      const eliminar = vi.spyOn(servicios.niveles!, 'eliminarSector')

      await pulsar(vista, 'Eliminar sector Zapatas eje 4-6')
      expect(confirm.require).toHaveBeenCalledWith(
        expect.objectContaining({ header: 'Eliminar sector' }),
      )
      expect(eliminar).not.toHaveBeenCalled()
      await aceptar()

      expect(eliminar).toHaveBeenCalledWith(5)
      expect(toast.add).toHaveBeenCalledWith(
        expect.objectContaining({ summary: 'Sector eliminado correctamente' }),
      )
      expect(filas(vista).map((f) => f[0])).toEqual(['Zapatas eje 1-3'])
    })

    it('al eliminar el nivel elegido pasa al primero que queda', async () => {
      const vista = await montar()
      await elegirNivel(vista, 'Piso 2')

      await pulsar(vista, 'Eliminar nivel Piso 2')
      await aceptar()

      expect(toast.add).toHaveBeenCalledWith(
        expect.objectContaining({ summary: 'Nivel eliminado correctamente' }),
      )
      expect(niveles(vista)).toEqual(['Sótano 1', 'Piso 1'])
      expect(nivelElegido(vista)).toBe('Sótano 1')
    })

    it('avisa el rechazo del backend y deja el sector', async () => {
      const vista = await montar()
      const { errorDeValidacion } = await import('@/services/errores')
      vi.spyOn(servicios.niveles!, 'eliminarSector').mockRejectedValue(
        errorDeValidacion({
          sector: ['El sector tiene guías asignadas; reasígnelas a otro sector para eliminarlo'],
        }),
      )

      await pulsar(vista, 'Eliminar sector Zapatas eje 4-6')
      await aceptar()

      expect(toast.add).toHaveBeenCalledWith(expect.objectContaining({ severity: 'error' }))
      expect(filas(vista)).toHaveLength(2)
    })

    it('al administrador no le ofrece eliminar', async () => {
      const vista = await montar(undefined, 'administrador')

      expect(vista.find('button[aria-label^="Eliminar "]').exists()).toBe(false)
    })
  })
})
