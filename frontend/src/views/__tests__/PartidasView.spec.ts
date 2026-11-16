import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { asentar, usarRelojFalso } from './asentar'
import PrimeVue from 'primevue/config'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import type { ProyectosServicio } from '@/services/proyectos'
import type { PartidasServicio } from '@/services/partidas'
import type { GruposServicio } from '@/services/grupos'
import type { ElementosServicio } from '@/services/elementos'
import { createPinia, getActivePinia, setActivePinia } from 'pinia'
import PartidasView from '@/views/PartidasView.vue'
import { useAuthStore } from '@/stores/auth'
import { useProyectoActualStore } from '@/stores/proyectoActual'
import type { RolClave, Usuario } from '@/types/auth'

const { toast, confirm, servicios } = vi.hoisted(() => ({
  toast: { add: vi.fn() },
  confirm: { require: vi.fn() },
  servicios: {
    proyectos: null as ProyectosServicio | null,
    partidas: null as PartidasServicio | null,
    grupos: null as GruposServicio | null,
    elementos: null as ElementosServicio | null,
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

vi.mock('@/services/grupos', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/grupos')>()
  return {
    ...real,
    get gruposServicio() {
      return servicios.grupos
    },
  }
})

vi.mock('@/services/elementos', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/elementos')>()
  return {
    ...real,
    get elementosServicio() {
      return servicios.elementos
    },
  }
})

vi.mock('@/services/resistencias', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/resistencias')>()
  return { ...real, resistenciasServicio: real.crearResistenciasEnMemoria() }
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

async function montar(
  preparar?: (proyectos: ProyectosServicio) => Promise<void>,
  rol: RolClave = 'oficina_tecnica',
) {
  const { crearProyectosEnMemoria } = await import('@/services/proyectos')
  const { crearPartidasEnMemoria } = await import('@/services/partidas')
  const { crearNivelesEnMemoria } = await import('@/services/niveles')
  const niveles = crearNivelesEnMemoria()
  servicios.proyectos = crearProyectosEnMemoria(async () => servicios.asignados)
  servicios.partidas = crearPartidasEnMemoria(async () => niveles)
  servicios.grupos = (await import('@/services/grupos')).crearGruposEnMemoria()
  servicios.elementos = (await import('@/services/elementos')).crearElementosEnMemoria()
  await preparar?.(servicios.proyectos)
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().usuario = { rol: { clave: rol } } as Usuario
  const vista = mount(PartidasView, {
    global: { plugins: [PrimeVue, pinia], stubs: { teleport: true } },
  })
  await asentar()
  return vista
}

// Descripción, f'c, m³ neto, factor de desperdicio, m³ presupuestados, P.U. y parcial.
function filas(vista: VueWrapper) {
  return vista.findAll('[data-partida]').map((tr) => {
    const celdas = tr.findAll('td')
    return [celdas[2]!.get('span').text(), ...celdas.slice(4, 10).map((td) => td.text())]
  })
}

// El elemento estructural va bajo la descripción.
const elementos = (vista: VueWrapper) =>
  vista.findAll('[data-partida] td:nth-child(3) span:last-child').map((s) => s.text())

const grupos = (vista: VueWrapper) => vista.findAll('[data-grupo-nombre]').map((g) => g.text())

const zapata = "CONCRETO F'C=210 kg/cm2 - ZAPATAS"
const editarZapata = `button[aria-label="Editar partida ${zapata}"]`

// El proyecto se elige en el encabezado (SelectorProyecto); la vista lo toma del store.
const proyectoActual = () => useProyectoActualStore()
const nombresOfrecidos = () => proyectoActual().proyectos.map((p) => p.nombre)

const totalPresupuestado = (vista: VueWrapper) => vista.get('[data-total-presupuesto]').text()

async function elegirProyecto(id: number) {
  proyectoActual().elegir(id)
  await asentar()
}

async function abrirNuevo(vista: VueWrapper) {
  await vista.get('button[aria-label="Agregar partida"]').trigger('click')
  await asentar()
}

async function llenar(
  vista: VueWrapper,
  valores: { metrado: number; desperdicio: number; precio: number },
  resistencia = 3,
) {
  const select = (id: string) =>
    vista.findAllComponents(Select).find((s) => s.props('labelId') === id)!
  const numero = (id: string) =>
    vista.findAllComponents(InputNumber).find((n) => n.props('inputId') === id)!
  const texto = (id: string) =>
    vista.findAllComponents(InputText).find((t) => t.attributes('id') === id)!
  await select('grupo_id').setValue(3)
  await texto('descripcion').setValue("CONCRETO PREMEZ. F'C=280 kg/cm2 CISTERNA")
  await select('elemento').setValue('Placa')
  await select('resistencia_id').setValue(resistencia)
  await numero('metrado_m3').setValue(valores.metrado)
  await numero('factor_desperdicio').setValue(valores.desperdicio)
  await numero('precio_unitario').setValue(valores.precio)
}

const selectDe = (vista: VueWrapper, id: string) =>
  vista.findAllComponents(Select).find((s) => s.props('labelId') === id)!

async function elegirGrupo(vista: VueWrapper, id: number) {
  await selectDe(vista, 'grupo_id').setValue(id)
  await asentar()
}

async function guardar(vista: VueWrapper) {
  await vista.get('form').trigger('submit')
  await asentar()
}

enableAutoUnmount(afterEach)
beforeEach(usarRelojFalso)
afterEach(() => vi.useRealTimers())

describe('PartidasView', () => {
  beforeEach(() => {
    toast.add.mockClear()
    servicios.asignados = [1, 2, 3]
    confirm.require.mockReset()
    // Select de PrimeVue lo usa al montarse; jsdom no lo trae.
    vi.stubGlobal(
      'matchMedia',
      vi.fn(() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() })),
    )
  })

  it('lista las partidas del primer proyecto agrupadas, con su parcial y el total', async () => {
    const vista = await montar()

    expect(vista.get('h2').text()).toBe('Presupuesto – Residencial Los Álamos')
    expect(proyectoActual().proyectoId).toBe(1)
    expect(vista.findAll('thead th').map((th) => th.text())).toEqual([
      'P. control',
      'Ítem',
      'Descripción',
      'Und',
      "f'c",
      'm³ neto',
      'Desp.',
      'm³ presup.',
      'P.U.',
      'Parcial',
      'Acciones',
    ])
    expect(grupos(vista)).toEqual([
      'Cimentación',
      'Verticales superestructura',
      'Horizontales superestructura',
    ])
    expect(filas(vista)).toEqual([
      [zapata, '210', '320.00', '1.050', '336.00', '385.00', '129,360.00'],
      [
        "CONCRETO PREMEZ. F'C=280 kg/cm2 - COLUMNAS",
        '280',
        '180.50',
        '1.030',
        '185.92',
        '420.00',
        '78,084.30',
      ],
      [
        "CONCRETO F'C=210 kg/cm2 - LOSA ALIGERADA H=20cm",
        '210',
        '245.00',
        '1.050',
        '257.25',
        // Mismo concreto que las zapatas: el mismo precio.
        '385.00',
        '99,041.25',
      ],
    ])
    expect(elementos(vista)).toEqual(['Zapata', 'Columna', 'Losa aligerada'])
    expect(totalPresupuestado(vista)).toBe('S/ 306,485.55')
  })

  it('resume cada grupo en su encabezado y lo subtotaliza debajo de sus partidas', async () => {
    const vista = await montar()
    const [cimentacion] = vista.findAll('[data-grupo]')

    expect(cimentacion!.get('th').text()).toBe(
      'Cimentación · 1 partida · 320.00 m³ neto · S/ 129,360',
    )
    expect(
      cimentacion!
        .get('[data-subtotal]')
        .findAll('td')
        .map((td) => td.text())
        .filter(Boolean),
    ).toEqual(['Subtotal Cimentación:', '320.00', '336.00', 'S/ 129,360.00'])
    // El total suma los volúmenes de todos los grupos.
    expect(vista.get('tfoot').text()).toContain('745.50')
    expect(vista.get('tfoot').text()).toContain('779.17')
  })

  it('en celular deja solo la descripción, el metrado neto y el parcial', async () => {
    const vista = await montar()
    const ocultas = vista.findAll('thead th').filter((th) => th.classes().includes('hidden'))

    expect(ocultas.map((th) => th.text())).toEqual([
      'P. control',
      'Ítem',
      'Und',
      "f'c",
      'Desp.',
      'm³ presup.',
      'P.U.',
    ])
  })

  it('ofrece solo los proyectos activos', async () => {
    const vista = await montar(async (proyectos) => {
      const [primero] = await proyectos.listar()
      await proyectos.actualizar(1, { ...primero!, estado: 'Finalizado' })
    })

    expect(nombresOfrecidos()).toEqual([
      'Torre Empresarial Javier Prado',
      'Condominio Las Palmeras',
    ])
    expect(elementos(vista)).toEqual(['Placa'])
  })

  it('muestra el proyecto ya elegido en otra vista sin volver a pedir los proyectos', async () => {
    await montar()
    await elegirProyecto(2)
    const asignados = vi.spyOn(servicios.proyectos!, 'asignados')
    const otra = mount(PartidasView, {
      global: { plugins: [PrimeVue, getActivePinia()!], stubs: { teleport: true } },
    })
    await asentar()

    expect(asignados).not.toHaveBeenCalled()
    expect(elementos(otra)).toEqual(['Placa'])
  })

  it('descarta la respuesta de un proyecto que se dejó de lado mientras cargaba', async () => {
    const vista = await montar()
    const real = servicios.partidas!.listar
    let soltar!: () => void
    vi.spyOn(servicios.partidas!, 'listar').mockImplementation(async (id) => {
      if (id === 2) await new Promise<void>((r) => (soltar = r))
      return real(id)
    })
    proyectoActual().elegir(2)
    await asentar()
    await elegirProyecto(3)
    soltar()
    await asentar()

    expect(vista.text()).toContain('Este proyecto aún no tiene partidas.')
    expect(totalPresupuestado(vista)).toBe('S/ 0.00')
  })

  it('sin proyectos activos asignados lo dice en vez de hablar de partidas', async () => {
    servicios.asignados = []
    const vista = await montar()

    expect(vista.get('h2').text()).toBe('Presupuesto del proyecto')
    expect(vista.text()).toContain('No tiene proyectos activos asignados.')
    expect(vista.get('button[aria-label="Agregar partida"]').attributes('disabled')).toBeDefined()
  })

  it('al elegir otro proyecto muestra solo sus partidas y su total', async () => {
    const vista = await montar()
    await elegirProyecto(3)

    expect(vista.text()).toContain('Este proyecto aún no tiene partidas.')
    expect(totalPresupuestado(vista)).toBe('S/ 0.00')
  })

  it('registra partidas con decimales y actualiza el total presupuestado', async () => {
    const vista = await montar()
    await elegirProyecto(3)

    await abrirNuevo(vista)
    await llenar(vista, { metrado: 12.5, desperdicio: 5, precio: 33.33 })
    await guardar(vista)

    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ summary: 'Partida registrada' }),
    )
    expect(vista.find('form').exists()).toBe(false)
    expect(grupos(vista)).toEqual(['Cisterna'])
    expect(filas(vista)).toEqual([
      [
        "CONCRETO PREMEZ. F'C=280 kg/cm2 CISTERNA",
        '280',
        '12.50',
        '1.050',
        '13.13',
        '33.33',
        '437.46',
      ],
    ])
    expect(totalPresupuestado(vista)).toBe('S/ 437.46')

    await abrirNuevo(vista)
    // Otra resistencia: con la misma, el precio sería el del concreto ya registrado.
    await llenar(vista, { metrado: 0.5, desperdicio: 0, precio: 2.01 }, 4)
    await guardar(vista)

    expect(filas(vista)[1]?.slice(2)).toEqual(['0.50', '1.000', '0.50', '2.01', '1.01'])
    expect(totalPresupuestado(vista)).toBe('S/ 438.47')
    // La partida guarda solo el id del proyecto, sin copiar sus datos.
    expect(await servicios.partidas!.listar(3)).toEqual([
      {
        id: 5,
        proyecto_id: 3,
        concreto_id: 4,
        grupo_id: 3,
        // El ítem quedó vacío: se guarda como null.
        item: null,
        descripcion: "CONCRETO PREMEZ. F'C=280 kg/cm2 CISTERNA",
        elemento: 'Placa',
        resistencia_id: 3,
        resistencia_fc: "f'c 280 kg/cm²",
        metrado_m3: 12.5,
        factor_desperdicio: 5,
        precio_unitario: 33.33,
      },
      expect.objectContaining({ id: 6, proyecto_id: 3, factor_desperdicio: 0 }),
    ])
  })

  it('exige los siete campos obligatorios y no registra nada', async () => {
    const vista = await montar()
    const crear = vi.spyOn(servicios.partidas!, 'crear')
    await abrirNuevo(vista)
    await guardar(vista)

    const errores = Object.fromEntries(
      vista.findAll('small.text-red-600').map((e) => [e.attributes('id'), e.text()]),
    )
    expect(errores).toEqual({
      'grupo_id-error': 'Este dato es requerido',
      'descripcion-error': 'Este dato es requerido',
      'elemento-error': 'Este dato es requerido',
      'resistencia_id-error': 'Este dato es requerido',
      'metrado_m3-error': 'Este dato es requerido',
      'factor_desperdicio-error': 'Este dato es requerido',
      'precio_unitario-error': 'Este dato es requerido',
    })
    expect(crear).not.toHaveBeenCalled()
  })

  it('pide metrado y precio unitario mayores a cero', async () => {
    const vista = await montar()
    const crear = vi.spyOn(servicios.partidas!, 'crear')
    await abrirNuevo(vista)
    await llenar(vista, { metrado: 0, desperdicio: 0, precio: 0 })
    await guardar(vista)

    expect(vista.get('#metrado_m3-error').text()).toBe('Ingrese un valor mayor a cero')
    expect(vista.get('#precio_unitario-error').text()).toBe('Ingrese un valor mayor a cero')
    expect(vista.find('#factor_desperdicio-error').exists()).toBe(false)
    expect(crear).not.toHaveBeenCalled()
  })

  it('pide confirmar un desperdicio mayor a 15 % antes de registrar', async () => {
    const vista = await montar()
    const crear = vi.spyOn(servicios.partidas!, 'crear')
    await abrirNuevo(vista)
    // f'c 175: el proyecto aún no usa ese concreto, así que solo se confirma el desperdicio.
    await llenar(vista, { metrado: 10, desperdicio: 15.5, precio: 400 }, 1)

    confirm.require.mockImplementationOnce((o: { reject: () => void }) => o.reject())
    await guardar(vista)
    expect(confirm.require).toHaveBeenCalledWith(
      expect.objectContaining({
        message: 'El factor de desperdicio es 15.50 %, mayor a 15 %. ¿Confirma el valor?',
      }),
    )
    expect(crear).not.toHaveBeenCalled()
    expect(vista.find('form').exists()).toBe(true)

    confirm.require.mockImplementationOnce((o: { accept: () => void }) => o.accept())
    await guardar(vista)
    // La confirmación viaja a la API, que sin ella rechaza el desperdicio.
    expect(crear).toHaveBeenCalledWith(
      1,
      expect.objectContaining({ factor_desperdicio: 15.5 }),
      true,
      false,
    )
    // Cisterna va entre Cimentación y Verticales superestructura.
    expect(filas(vista)[1]?.[6]).toBe('4,620.00')
  })

  it('llena el precio con el del concreto del proyecto y avisa que cambiarlo cambia todas sus partidas', async () => {
    const vista = await montar()
    const crear = vi.spyOn(servicios.partidas!, 'crear')
    await abrirNuevo(vista)
    const precio = () =>
      vista.findAllComponents(InputNumber).find((n) => n.props('inputId') === 'precio_unitario')!

    // f'c 210 ya está en el proyecto (zapatas y losa) a S/ 385: se llena solo y sin aviso.
    await llenar(vista, { metrado: 10, desperdicio: 5, precio: 385 }, 2)
    await vista
      .findAllComponents(Select)
      .find((c) => c.props('labelId') === 'resistencia_id')!
      .setValue(3)
    await asentar()
    expect(precio().props('modelValue')).toBe(420)
    await vista
      .findAllComponents(Select)
      .find((c) => c.props('labelId') === 'resistencia_id')!
      .setValue(2)
    await asentar()
    expect(precio().props('modelValue')).toBe(385)
    expect(vista.find('[data-aviso]').exists()).toBe(false)

    const aviso =
      "El precio de f'c 210 kg/cm² (S/ 385.00) lo usan otras 2 partidas del proyecto; al guardar se actualizará en todas."
    await precio().setValue(400)
    await asentar()
    expect(vista.get('[data-aviso]').text()).toBe(aviso)

    confirm.require.mockImplementationOnce((o: { reject: () => void }) => o.reject())
    await guardar(vista)
    expect(confirm.require).toHaveBeenCalledWith(
      expect.objectContaining({ header: 'Confirmar precio', message: aviso }),
    )
    expect(crear).not.toHaveBeenCalled()

    confirm.require.mockImplementationOnce((o: { accept: () => void }) => o.accept())
    await guardar(vista)
    expect(crear).toHaveBeenCalledWith(
      1,
      expect.objectContaining({ resistencia_id: 2, precio_unitario: 400 }),
      false,
      true,
    )
    // Zapatas, losa y la nueva quedan con el precio nuevo.
    const precios210 = filas(vista)
      .filter((f) => f[1] === '210')
      .map((f) => f[5])
    expect(precios210).toEqual(['400.00', '400.00', '400.00'])
  })

  it('al editar avisa en singular si el concreto lo usa una sola partida más', async () => {
    const vista = await montar()
    await vista.get(editarZapata).trigger('click')
    await asentar()
    const precio = vista
      .findAllComponents(InputNumber)
      .find((n) => n.props('inputId') === 'precio_unitario')!
    expect(precio.props('modelValue')).toBe(385)

    await precio.setValue(390)
    await asentar()
    expect(vista.get('[data-aviso]').text()).toBe(
      "El precio de f'c 210 kg/cm² (S/ 385.00) lo usa otra partida del proyecto; al guardar se actualizará también en ella.",
    )
  })

  it('edita una partida y recalcula los totales', async () => {
    const vista = await montar()
    await vista.get(editarZapata).trigger('click')
    await asentar()

    const metrado = vista
      .findAllComponents(InputNumber)
      .find((n) => n.props('inputId') === 'metrado_m3')!
    expect(metrado.props('modelValue')).toBe(320)
    await metrado.setValue(300)
    await guardar(vista)

    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ summary: 'Cambios guardados.' }),
    )
    expect(filas(vista)[0]?.slice(2)).toEqual(['300.00', '1.050', '315.00', '385.00', '121,275.00'])
    expect(totalPresupuestado(vista)).toBe('S/ 298,400.55')
  })

  it('no deja bajar el metrado por debajo de lo ya repartido en sectores', async () => {
    const vista = await montar()
    await vista.get(editarZapata).trigger('click')
    await asentar()

    await vista
      .findAllComponents(InputNumber)
      .find((n) => n.props('inputId') === 'metrado_m3')!
      .setValue(50)
    await guardar(vista)

    expect(vista.get('form').text()).toContain(
      'El metrado es menor al volumen ya repartido en sectores (76.00 m³)',
    )
    expect(toast.add).not.toHaveBeenCalled()
    expect(filas(vista)[0]?.slice(2)).toEqual(['320.00', '1.050', '336.00', '385.00', '129,360.00'])
  })

  it('ofrece solo los proyectos asignados al usuario', async () => {
    servicios.asignados = [2]
    const vista = await montar()

    expect(nombresOfrecidos()).toEqual(['Torre Empresarial Javier Prado'])
    expect(elementos(vista)).toEqual(['Placa'])
  })

  it('al administrador le muestra el presupuesto sin ninguna acción de escritura', async () => {
    const vista = await montar(undefined, 'administrador')

    expect(filas(vista).length).toBeGreaterThan(0)
    expect(vista.find('button[aria-label="Agregar partida"]').exists()).toBe(false)
    expect(vista.find('button[aria-label^="Editar partida "]').exists()).toBe(false)
  })

  describe('elemento según el grupo', () => {
    const elementoSelect = (vista: VueWrapper) => selectDe(vista, 'elemento')

    it('pide primero el grupo y luego ofrece solo los elementos que este admite', async () => {
      const vista = await montar()
      await abrirNuevo(vista)

      expect(elementoSelect(vista).props('disabled')).toBe(true)
      expect(elementoSelect(vista).props('placeholder')).toBe('Elija primero el grupo')

      await elegirGrupo(vista, 3)

      expect(elementoSelect(vista).props('disabled')).toBe(false)
      expect(elementoSelect(vista).props('options')).toEqual(['Placa', 'Viga', 'Losa maciza'])
    })

    it('al cambiar de grupo conserva el elemento si el nuevo lo admite y si no, lo descarta', async () => {
      const vista = await montar()
      await abrirNuevo(vista)
      await elegirGrupo(vista, 3)
      await elementoSelect(vista).setValue('Placa')
      await asentar()
      // Verticales sótanos también admite Placa.
      await elegirGrupo(vista, 4)
      expect(elementoSelect(vista).props('modelValue')).toBe('Placa')

      // Horizontales sótanos no: se vacía y pide elegir otro.
      await elegirGrupo(vista, 5)
      expect(elementoSelect(vista).props('modelValue')).toBeUndefined()
      expect(vista.get('#elemento-error').text()).toBe('Este dato es requerido')
    })

    it('avisa si el grupo no tiene elementos asignados', async () => {
      const vista = await montar(async () => {
        await servicios.grupos!.actualizar(1, {
          nombre: 'Muros anclados',
          partida_control: '2.02.02',
          orden: 1,
          elementos: [],
        })
      })
      await abrirNuevo(vista)
      await elegirGrupo(vista, 1)

      expect(elementoSelect(vista).props('options')).toEqual([])
      expect(vista.get('[data-aviso]').text()).toBe(
        'Este grupo no tiene elementos asignados; agréguelos en Catálogos.',
      )
    })

    it('muestra el error del backend si el elemento no corresponde al grupo', async () => {
      const vista = await montar()
      await elegirProyecto(3)
      const { errorDeValidacion } = await import('@/services/errores')
      vi.spyOn(servicios.partidas!, 'crear').mockRejectedValueOnce(
        errorDeValidacion({ elemento: ['El elemento no corresponde al grupo elegido'] }),
      )
      await abrirNuevo(vista)
      await llenar(vista, { metrado: 1, desperdicio: 0, precio: 1 })
      await guardar(vista)

      expect(vista.get('#elemento-error').text()).toBe(
        'El elemento no corresponde al grupo elegido',
      )
    })
  })

  describe('catálogos con valores inactivos', () => {
    const opciones = (vista: VueWrapper, id: string) =>
      vista
        .findAllComponents(Select)
        .find((s) => s.props('labelId') === id)!
        .props('options') as { id: number; fc_kg_cm2: number }[]

    it('ofrece solo los elementos y resistencias activos al registrar una partida', async () => {
      const vista = await montar(async () => {
        const viga = (await servicios.elementos!.todos()).find((e) => e.nombre === 'Viga')!
        await servicios.elementos!.actualizar(viga.id, { nombre: 'Viga', activo: false })
        const { resistenciasServicio } = await import('@/services/resistencias')
        await resistenciasServicio.actualizar(1, {
          fc_kg_cm2: 175,
          nombre: "f'c 175 kg/cm²",
          activo: false,
        })
      })
      await abrirNuevo(vista)
      // Horizontales sótanos admite Viga, Losa aligerada, Losa maciza y Escalera.
      await elegirGrupo(vista, 5)

      expect(opciones(vista, 'elemento')).toEqual(['Losa aligerada', 'Losa maciza', 'Escalera'])
      expect(
        opciones(vista, 'resistencia_id').map((r: { fc_kg_cm2: number }) => r.fc_kg_cm2),
      ).toEqual([210, 280, 350])
    })

    it('al editar sigue mostrando el elemento y la resistencia inactivos de la partida', async () => {
      const vista = await montar(async () => {
        const zapata = (await servicios.elementos!.todos()).find((e) => e.nombre === 'Zapata')!
        await servicios.elementos!.actualizar(zapata.id, { nombre: 'Zapata', activo: false })
        const { resistenciasServicio } = await import('@/services/resistencias')
        await resistenciasServicio.actualizar(2, {
          fc_kg_cm2: 210,
          nombre: "f'c 210 kg/cm²",
          activo: false,
        })
      })
      await vista.get(editarZapata).trigger('click')
      await asentar()

      expect(opciones(vista, 'elemento')).toContain('Zapata')
      expect(opciones(vista, 'resistencia_id').map((r: { id: number }) => r.id)).toContain(2)
    })
  })
})
