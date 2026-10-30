import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { asentar, usarRelojFalso } from './asentar'
import { createMemoryHistory, createRouter, type LocationQueryRaw } from 'vue-router'
import PrimeVue from 'primevue/config'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import type { ProyectosServicio } from '@/services/proyectos'
import type { PartidasServicio } from '@/services/partidas'
import PartidasView from '@/views/PartidasView.vue'

const { toast, confirm, servicios } = vi.hoisted(() => ({
  toast: { add: vi.fn() },
  confirm: { require: vi.fn() },
  servicios: {
    proyectos: null as ProyectosServicio | null,
    partidas: null as PartidasServicio | null,
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

async function montar(
  preparar?: (proyectos: ProyectosServicio) => Promise<void>,
  consulta: LocationQueryRaw = {},
) {
  const { crearProyectosEnMemoria } = await import('@/services/proyectos')
  const { crearPartidasEnMemoria } = await import('@/services/partidas')
  const { crearNivelesEnMemoria } = await import('@/services/niveles')
  const niveles = crearNivelesEnMemoria()
  servicios.proyectos = crearProyectosEnMemoria()
  servicios.partidas = crearPartidasEnMemoria(async () => niveles)
  await preparar?.(servicios.proyectos)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/partidas', component: PartidasView }],
  })
  await router.push({ path: '/partidas', query: consulta })
  const vista = mount(PartidasView, {
    global: { plugins: [PrimeVue, router], stubs: { teleport: true } },
  })
  await asentar()
  return vista
}

// Sin la segunda línea que solo se ve en celular.
function filas(vista: VueWrapper) {
  return vista
    .findAll('tbody tr')
    .map((tr) =>
      tr
        .findAll('td')
        .map((td) => (td.find('.sm\\:hidden').exists() ? td.get('span').text() : td.text())),
    )
}

const selectorProyecto = (vista: VueWrapper) =>
  vista.findAllComponents(Select).find((s) => s.props('ariaLabel') === 'Proyecto')!

const totalPresupuestado = (vista: VueWrapper) => vista.get('[data-total-presupuesto]').text()

async function elegirProyecto(vista: VueWrapper, id: number) {
  await selectorProyecto(vista).setValue(id)
  await asentar()
}

async function abrirNuevo(vista: VueWrapper) {
  await vista.get('button[aria-label="Agregar partida"]').trigger('click')
  await asentar()
}

async function llenar(
  vista: VueWrapper,
  valores: { metrado: number; desperdicio: number; precio: number },
) {
  const select = (id: string) =>
    vista.findAllComponents(Select).find((s) => s.props('labelId') === id)!
  const numero = (id: string) =>
    vista.findAllComponents(InputNumber).find((n) => n.props('inputId') === id)!
  await select('elemento').setValue('Columna')
  await select('resistencia_fc').setValue("f'c 280 kg/cm²")
  await numero('metrado_m3').setValue(valores.metrado)
  await numero('factor_desperdicio').setValue(valores.desperdicio)
  await numero('precio_unitario').setValue(valores.precio)
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
    confirm.require.mockReset()
    // Select de PrimeVue lo usa al montarse; jsdom no lo trae.
    vi.stubGlobal(
      'matchMedia',
      vi.fn(() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() })),
    )
  })

  it('lista las partidas del primer proyecto con su total y el total presupuestado', async () => {
    const vista = await montar()

    expect(selectorProyecto(vista).props('modelValue')).toBe(1)
    expect(vista.findAll('th').map((th) => th.text())).toEqual([
      'Elemento',
      "f'c",
      'Metrado m³',
      '% Desp.',
      'P.U. S/',
      'Total S/',
      'Acciones',
    ])
    expect(filas(vista).map((f) => f.slice(0, 6))).toEqual([
      ['Zapata', "f'c 210 kg/cm²", '320.00', '5.00 %', '385.00', '129,360.00'],
      ['Columna', "f'c 280 kg/cm²", '180.50', '3.00 %', '420.00', '78,084.30'],
      ['Losa aligerada', "f'c 210 kg/cm²", '245.00', '5.00 %', '390.00', '100,327.50'],
    ])
    expect(totalPresupuestado(vista)).toBe('S/ 307,771.80')
  })

  it("en celular muestra el f'c bajo el elemento y oculta las columnas del cálculo", async () => {
    const vista = await montar()
    const ocultas = vista.findAll('th').filter((th) => th.classes().includes('hidden'))

    expect(ocultas.map((th) => th.text())).toEqual(["f'c", 'Metrado m³', '% Desp.', 'P.U. S/'])
    expect(vista.get('tbody tr:first-child td:first-child .sm\\:hidden').text()).toBe(
      "f'c 210 kg/cm²",
    )
  })

  it('ofrece solo los proyectos activos', async () => {
    const vista = await montar(async (proyectos) => {
      const [primero] = await proyectos.listar()
      await proyectos.actualizar(1, { ...primero!, estado: 'Finalizado' })
    })

    const opciones = selectorProyecto(vista).props('options') as { nombre: string }[]
    expect(opciones.map((o) => o.nombre)).toEqual([
      'Torre Empresarial Javier Prado',
      'Condominio Las Palmeras',
    ])
    expect(filas(vista).map((f) => f[0])).toEqual(['Placa'])
  })

  it('preselecciona el proyecto que llega desde la lista de proyectos', async () => {
    const vista = await montar(undefined, { proyecto: '2' })

    expect(selectorProyecto(vista).props('modelValue')).toBe(2)
    expect(filas(vista).map((f) => f[0])).toEqual(['Placa'])
  })

  it.each(['99', 'abc'])(
    'con un proyecto inexistente (%s) elige el primer proyecto activo',
    async (proyecto) => {
      const vista = await montar(undefined, { proyecto })

      expect(selectorProyecto(vista).props('modelValue')).toBe(1)
      expect(filas(vista).map((f) => f[0])).toEqual(['Zapata', 'Columna', 'Losa aligerada'])
    },
  )

  it('no preselecciona un proyecto finalizado', async () => {
    const vista = await montar(
      async (proyectos) => {
        const segundo = (await proyectos.listar()).find((p) => p.id === 2)
        await proyectos.actualizar(2, { ...segundo!, estado: 'Finalizado' })
      },
      { proyecto: '2' },
    )

    expect(selectorProyecto(vista).props('modelValue')).toBe(1)
    expect(filas(vista).map((f) => f[0])).toEqual(['Zapata', 'Columna', 'Losa aligerada'])
  })

  it('al elegir otro proyecto muestra solo sus partidas y su total', async () => {
    const vista = await montar()
    await elegirProyecto(vista, 3)

    expect(vista.text()).toContain('Este proyecto aún no tiene partidas.')
    expect(totalPresupuestado(vista)).toBe('S/ 0.00')
  })

  it('registra partidas con decimales y actualiza el total presupuestado', async () => {
    const vista = await montar()
    await elegirProyecto(vista, 3)

    await abrirNuevo(vista)
    await llenar(vista, { metrado: 12.5, desperdicio: 5, precio: 33.33 })
    await guardar(vista)

    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ summary: 'Partida registrada' }),
    )
    expect(vista.find('form').exists()).toBe(false)
    expect(filas(vista).map((f) => f.slice(0, 6))).toEqual([
      ['Columna', "f'c 280 kg/cm²", '12.50', '5.00 %', '33.33', '437.46'],
    ])
    expect(totalPresupuestado(vista)).toBe('S/ 437.46')

    await abrirNuevo(vista)
    await llenar(vista, { metrado: 0.5, desperdicio: 0, precio: 2.01 })
    await guardar(vista)

    expect(filas(vista)[1]?.slice(2, 6)).toEqual(['0.50', '0.00 %', '2.01', '1.01'])
    expect(totalPresupuestado(vista)).toBe('S/ 438.47')
    // La partida guarda solo el id del proyecto, sin copiar sus datos.
    expect(await servicios.partidas!.listar(3)).toEqual([
      {
        id: 5,
        proyecto_id: 3,
        elemento: 'Columna',
        resistencia_fc: "f'c 280 kg/cm²",
        metrado_m3: 12.5,
        factor_desperdicio: 5,
        precio_unitario: 33.33,
      },
      expect.objectContaining({ id: 6, proyecto_id: 3, factor_desperdicio: 0 }),
    ])
  })

  it('exige los cinco campos obligatorios y no registra nada', async () => {
    const vista = await montar()
    const crear = vi.spyOn(servicios.partidas!, 'crear')
    await abrirNuevo(vista)
    await guardar(vista)

    const errores = Object.fromEntries(
      vista.findAll('small.text-red-600').map((e) => [e.attributes('id'), e.text()]),
    )
    expect(errores).toEqual({
      'elemento-error': 'Este dato es requerido',
      'resistencia_fc-error': 'Este dato es requerido',
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
    await llenar(vista, { metrado: 10, desperdicio: 15.5, precio: 400 })

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
    expect(crear).toHaveBeenCalledOnce()
    expect(filas(vista)[3]?.[5]).toBe('4,620.00')
  })

  it('edita una partida y recalcula los totales', async () => {
    const vista = await montar()
    await vista.get('button[aria-label="Editar partida Zapata"]').trigger('click')
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
    expect(filas(vista)[0]?.slice(2, 6)).toEqual(['300.00', '5.00 %', '385.00', '121,275.00'])
    expect(totalPresupuestado(vista)).toBe('S/ 299,686.80')
  })

  it('no deja bajar el metrado por debajo de lo ya repartido en sectores', async () => {
    const vista = await montar()
    await vista.get('button[aria-label="Editar partida Zapata"]').trigger('click')
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
    expect(filas(vista)[0]?.slice(2, 6)).toEqual(['320.00', '5.00 %', '385.00', '129,360.00'])
  })
})
