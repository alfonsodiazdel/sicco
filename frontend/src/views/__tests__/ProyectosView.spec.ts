import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { asentar, usarRelojFalso } from './asentar'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter, type Router } from 'vue-router'
import PrimeVue from 'primevue/config'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import type { ProyectosServicio } from '@/services/proyectos'
import type { UsuariosServicio } from '@/services/usuarios'
import ProyectosView from '@/views/ProyectosView.vue'
import { useAuthStore } from '@/stores/auth'
import type { RolClave, Usuario } from '@/types/auth'

const { toast, servicios } = vi.hoisted(() => ({
  toast: { add: vi.fn() },
  servicios: {
    proyectos: null as ProyectosServicio | null,
    usuarios: null as UsuariosServicio | null,
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

vi.mock('@/services/usuarios', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/usuarios')>()
  return {
    ...real,
    get usuariosServicio() {
      return servicios.usuarios
    },
  }
})

let router: Router

async function montar(
  preparar?: (usuarios: UsuariosServicio) => Promise<void>,
  rol: RolClave = 'oficina_tecnica',
) {
  const { crearProyectosEnMemoria } = await import('@/services/proyectos')
  const { crearUsuariosEnMemoria } = await import('@/services/usuarios')
  servicios.proyectos = crearProyectosEnMemoria()
  servicios.usuarios = crearUsuariosEnMemoria()
  await preparar?.(servicios.usuarios)
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().usuario = { rol: { clave: rol } } as Usuario
  router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/proyectos', component: ProyectosView },
      { path: '/partidas', component: { template: '<p>Presupuesto</p>' } },
    ],
  })
  await router.push('/proyectos')
  const vista = mount(ProyectosView, {
    global: { plugins: [PrimeVue, pinia, router], stubs: { teleport: true } },
  })
  await asentar()
  return vista
}

function filas(vista: VueWrapper) {
  return vista.findAll('tbody tr').map((tr) => tr.findAll('td').map((td) => td.text()))
}

async function abrirNuevo(vista: VueWrapper) {
  await vista.get('button[aria-label="Nuevo proyecto"]').trigger('click')
  await asentar()
}

async function llenar(vista: VueWrapper, codigo: string, inicio: Date, fin: Date) {
  await vista.get('#codigo').setValue(codigo)
  await vista.get('#nombre').setValue('Edificio Miraflores')
  await vista.get('#direccion').setValue('Av. Larco 345, Miraflores')
  const [fechaInicio, fechaFin] = vista.findAllComponents(DatePicker)
  await fechaInicio!.setValue(inicio)
  await fechaFin!.setValue(fin)
  await vista.findComponent(Select).setValue(3)
}

async function guardar(vista: VueWrapper) {
  await vista.get('form').trigger('submit')
  await asentar()
}

const etiquetas = (vista: VueWrapper) => vista.findAll('form label').map((l) => l.text())

enableAutoUnmount(afterEach)
beforeEach(usarRelojFalso)
afterEach(() => vi.useRealTimers())

describe('ProyectosView', () => {
  beforeEach(() => {
    toast.add.mockClear()
    // Select y DatePicker de PrimeVue lo usan al montarse; jsdom no lo trae.
    vi.stubGlobal(
      'matchMedia',
      vi.fn(() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() })),
    )
  })

  it('lista los proyectos con las columnas de la maqueta y fechas dd/mm/aaaa', async () => {
    const vista = await montar()

    expect(vista.findAll('th').map((th) => th.text())).toEqual([
      'Código',
      'Proyecto',
      'Dirección',
      'Inicio',
      'Fin estimado',
      'Estado',
      'Acciones',
    ])
    expect(filas(vista)).toHaveLength(3)
    expect(filas(vista)[0]?.slice(0, 6)).toEqual([
      'PRY-001',
      'Residencial Los Álamos',
      'Av. Primavera 1250, Santiago de Surco',
      '02/03/2026',
      '26/02/2027',
      'Activo',
    ])
  })

  it('registra un proyecto válido que nace Activo', async () => {
    const vista = await montar()
    await abrirNuevo(vista)
    await llenar(vista, 'PRY-004', new Date(2026, 0, 10), new Date(2026, 11, 20))
    await guardar(vista)

    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ summary: 'Proyecto registrado correctamente' }),
    )
    expect(vista.find('form').exists()).toBe(false)
    expect(filas(vista)).toHaveLength(4)
    expect(filas(vista)[3]?.slice(0, 6)).toEqual([
      'PRY-004',
      'Edificio Miraflores',
      'Av. Larco 345, Miraflores',
      '10/01/2026',
      '20/12/2026',
      'Activo',
    ])
    expect((await servicios.proyectos!.listar())[3]).toMatchObject({
      fecha_inicio: '2026-01-10',
      fecha_fin: '2026-12-20',
      ingeniero_id: 3,
      estado: 'Activo',
    })
  })

  it('exige los seis campos obligatorios y no registra nada', async () => {
    const vista = await montar()
    const crear = vi.spyOn(servicios.proyectos!, 'crear')
    await abrirNuevo(vista)
    await guardar(vista)

    const errores = Object.fromEntries(
      vista.findAll('small.text-red-600').map((e) => [e.attributes('id'), e.text()]),
    )
    expect(errores).toEqual({
      'codigo-error': 'Este dato es requerido',
      'nombre-error': 'Este dato es requerido',
      'direccion-error': 'Este dato es requerido',
      'fecha_inicio-error': 'Este dato es requerido',
      'fecha_fin-error': 'Este dato es requerido',
      'ingeniero_id-error': 'Este dato es requerido',
    })
    expect(crear).not.toHaveBeenCalled()
  })

  it('ofrece como responsables solo a los ingenieros de producción activos', async () => {
    let listar: ReturnType<typeof vi.spyOn> | undefined
    const vista = await montar(async (usuarios) => {
      listar = vi.spyOn(usuarios, 'listar')
      const base = { proyecto_ids: [1] }
      const inactivo = await usuarios.crear({
        ...base,
        nombres: 'Pedro',
        apellidos: 'Vega',
        email: 'pedro.vega@sicco.test',
        rol: 'ingeniero_produccion',
      })
      await usuarios.cambiarEstado(inactivo.id, false)
      await usuarios.crear({
        ...base,
        nombres: 'Marta',
        apellidos: 'Flores',
        email: 'marta.flores@sicco.test',
        rol: 'gerencia',
      })
      await usuarios.crear({
        ...base,
        nombres: 'Rosa',
        apellidos: 'Díaz',
        email: 'rosa.diaz@sicco.test',
        rol: 'ingeniero_produccion',
      })
    })
    await abrirNuevo(vista)

    // Solo id y nombre: sin correo, rol ni proyectos de nadie.
    expect(vista.findComponent(Select).props('options')).toStrictEqual([
      { id: 3, nombre: 'Lucía Ramos' },
      { id: 7, nombre: 'Rosa Díaz' },
    ])
    expect(listar).not.toHaveBeenCalled()
  })

  it('al editar conserva al ingeniero que quedó inactivo, sin ofrecerlo en un proyecto nuevo', async () => {
    const vista = await montar(async (usuarios) => {
      await usuarios.cambiarEstado(3, false)
    })

    await vista.get('button[aria-label="Editar Residencial Los Álamos"]').trigger('click')
    await asentar()
    const ingeniero = vista.findComponent(Select)
    expect(ingeniero.props('modelValue')).toBe(3)
    expect(ingeniero.props('options')).toStrictEqual([{ id: 3, nombre: 'Lucía Ramos (inactivo)' }])

    await vista.get('#nombre').setValue('Residencial Los Álamos II')
    await guardar(vista)
    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ summary: 'Cambios guardados.' }),
    )
    const guardado = (await servicios.proyectos!.listar()).find((p) => p.id === 1)
    expect(guardado).toMatchObject({ nombre: 'Residencial Los Álamos II', ingeniero_id: 3 })

    await abrirNuevo(vista)
    expect(vista.findComponent(Select).props('options')).toStrictEqual([])
  })

  it('muestra el estado solo al editar y permite finalizar el proyecto', async () => {
    const vista = await montar()
    await abrirNuevo(vista)
    expect(etiquetas(vista)).not.toContain('Estado *')

    await vista.get('button[aria-label="Editar Residencial Los Álamos"]').trigger('click')
    await asentar()
    expect(etiquetas(vista)).toContain('Estado *')
    const selects = vista.findAllComponents(Select)
    const estado = selects[selects.length - 1]!
    expect(estado.props('options')).toEqual(['Activo', 'Finalizado'])
    expect(estado.props('modelValue')).toBe('Activo')

    await estado.setValue('Finalizado')
    await guardar(vista)

    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ summary: 'Cambios guardados.' }),
    )
    const tag = vista.get('tbody tr:first-child .p-tag')
    expect(tag.text()).toBe('Finalizado')
    expect(tag.classes()).toContain('p-tag-secondary')
  })

  it('rechaza una fecha de fin igual o anterior a la de inicio', async () => {
    const vista = await montar()
    const crear = vi.spyOn(servicios.proyectos!, 'crear')
    await abrirNuevo(vista)
    await llenar(vista, 'PRY-004', new Date(2026, 4, 10), new Date(2026, 4, 10))
    await guardar(vista)

    expect(vista.get('#fecha_fin-error').text()).toBe(
      'La fecha de fin debe ser posterior a la de inicio',
    )
    expect(crear).not.toHaveBeenCalled()
  })

  it('rechaza un código ya registrado sin distinguir mayúsculas', async () => {
    const vista = await montar()
    await abrirNuevo(vista)
    await llenar(vista, 'pry-001', new Date(2026, 0, 10), new Date(2026, 11, 20))
    await guardar(vista)

    expect(vista.get('#codigo-error').text()).toBe('El código de proyecto ya existe')
    expect(toast.add).not.toHaveBeenCalled()
    expect(await servicios.proyectos!.listar()).toHaveLength(3)
  })

  it('filtra por código, nombre o dirección y no ofrece eliminar', async () => {
    const vista = await montar()
    const buscador = vista.get('input[aria-label="Buscar proyecto"]')

    await buscador.setValue('la molina')
    expect(filas(vista).map((f) => f[0])).toEqual(['PRY-003'])
    await buscador.setValue('pry-002')
    expect(filas(vista).map((f) => f[0])).toEqual(['PRY-002'])
    await buscador.setValue('xyz')
    expect(vista.text()).toContain('No hay proyectos que coincidan con la búsqueda.')

    expect(vista.find('[aria-label^="Eliminar"]').exists()).toBe(false)
    expect(vista.find('.pi-trash').exists()).toBe(false)
  })

  it('en cada fila abre el presupuesto con ese proyecto elegido', async () => {
    const vista = await montar()
    const botones = vista.findAll('button[aria-label^="Presupuesto de "]')

    expect(botones.map((b) => b.attributes('aria-label'))).toEqual([
      'Presupuesto de Residencial Los Álamos',
      'Presupuesto de Torre Empresarial Javier Prado',
      'Presupuesto de Condominio Las Palmeras',
    ])
    expect(botones[1]!.text()).toBe('Presupuesto')
    // En celular queda solo el ícono, para que la tabla no se desplace a lo ancho.
    expect(botones[1]!.get('.p-button-label').classes()).toEqual(
      expect.arrayContaining(['hidden', 'sm:inline']),
    )

    await botones[1]!.trigger('click')
    await asentar()
    expect(router.currentRoute.value.fullPath).toBe('/partidas?proyecto=2')
  })

  it.each<RolClave>(['ingeniero_produccion', 'gerencia'])(
    'no muestra la acción de presupuesto a %s',
    async (rol) => {
      const vista = await montar(undefined, rol)

      expect(filas(vista)).toHaveLength(3)
      expect(vista.find('button[aria-label^="Presupuesto de "]').exists()).toBe(false)
    },
  )
})
