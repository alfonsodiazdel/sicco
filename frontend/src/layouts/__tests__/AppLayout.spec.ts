import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import PrimeVue from 'primevue/config'
import Select from 'primevue/select'
import AppLayout from '@/layouts/AppLayout.vue'
import type { ProyectosServicio } from '@/services/proyectos'
import { useAuthStore } from '@/stores/auth'
import { useProyectoActualStore } from '@/stores/proyectoActual'
import type { RolClave, Usuario } from '@/types/auth'

const { toast, servicios } = vi.hoisted(() => ({
  toast: { add: vi.fn() },
  servicios: {
    proyectos: null as ProyectosServicio | null,
    asignados: [] as number[],
    alertasPendientes: vi.fn(async () => 0),
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

vi.mock('@/services/alertas', () => ({
  alertasServicio: {
    pendientes: () => servicios.alertasPendientes(),
  },
}))

function simularPantalla(escritorio: boolean) {
  vi.stubGlobal(
    'matchMedia',
    vi.fn<typeof window.matchMedia>(
      () =>
        ({
          matches: escritorio,
          addEventListener: vi.fn(),
          removeEventListener: vi.fn(),
        }) as unknown as MediaQueryList,
    ),
  )
}

async function montar() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: { template: '<p>Inicio</p>' }, meta: { titulo: 'Inicio' } }],
  })
  await router.push('/')
  const wrapper = mount(AppLayout, {
    global: { plugins: [createPinia(), router, PrimeVue] },
  })
  await flushPromises()
  return wrapper
}

describe('AppLayout', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    simularPantalla(true)
  })

  afterEach(() => vi.unstubAllGlobals())

  it('contrae y expande el menú lateral y guarda la preferencia', async () => {
    const wrapper = await montar()
    const boton = wrapper.get('button[aria-label="Mostrar u ocultar menú"]')
    const lateral = wrapper.get('aside')

    expect(lateral.classes()).toContain('w-60')
    expect(boton.attributes('aria-expanded')).toBe('true')
    expect(wrapper.text()).toContain('Control de concreto premezclado')

    await boton.trigger('click')
    expect(lateral.classes()).toContain('w-16')
    expect(boton.attributes('aria-expanded')).toBe('false')
    expect(wrapper.text()).not.toContain('Control de concreto premezclado')
    expect(wrapper.get('aside a[title="Inicio"]').text()).toBe('Inicio')
    expect(localStorage.getItem('sicco-menu-contraido')).toBe('1')

    await boton.trigger('click')
    expect(lateral.classes()).toContain('w-60')
    expect(localStorage.getItem('sicco-menu-contraido')).toBe('0')
  })

  it('recuerda el menú contraído al volver a cargar', async () => {
    localStorage.setItem('sicco-menu-contraido', '1')
    const wrapper = await montar()

    expect(wrapper.get('aside').classes()).toContain('w-16')
  })

  it('en móvil el mismo botón abre el menú desplegable sin contraer el lateral', async () => {
    simularPantalla(false)
    const wrapper = await montar()
    const boton = wrapper.get('button[aria-label="Mostrar u ocultar menú"]')

    expect(boton.attributes('aria-expanded')).toBe('false')
    await boton.trigger('click')
    expect(boton.attributes('aria-expanded')).toBe('true')
    expect(localStorage.getItem('sicco-menu-contraido')).toBeNull()
  })
})

describe('AppLayout · menú por rol', () => {
  beforeEach(() => simularPantalla(true))

  afterEach(() => vi.unstubAllGlobals())

  async function montarComo(rol: RolClave) {
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().usuario = { nombres: 'Ana', apellidos: 'Paz', rol: { clave: rol } } as Usuario
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/:ruta(.*)*', component: { template: '<p>Vista</p>' } }],
    })
    await router.push('/')
    const wrapper = mount(AppLayout, { global: { plugins: [pinia, router, PrimeVue] } })
    await flushPromises()
    return wrapper
  }

  it.each<RolClave>(['almacenero', 'ingeniero_produccion', 'gerencia'])(
    'no muestra Proyectos a %s',
    async (rol) => {
      const wrapper = await montarComo(rol)

      expect(wrapper.find('nav a[href="/proyectos"]').exists()).toBe(false)
    },
  )

  it.each<RolClave>(['oficina_tecnica', 'administrador'])('muestra Proyectos a %s', async (rol) => {
    const wrapper = await montarComo(rol)

    expect(wrapper.find('nav a[href="/proyectos"]').exists()).toBe(true)
  })
})

describe('AppLayout · selector de proyecto', () => {
  beforeEach(async () => {
    simularPantalla(true)
    toast.add.mockClear()
    servicios.asignados = [1, 2, 3]
    const { crearProyectosEnMemoria } = await import('@/services/proyectos')
    servicios.proyectos = crearProyectosEnMemoria(async () => servicios.asignados)
  })

  afterEach(() => vi.unstubAllGlobals())

  async function montarEn(ruta: string) {
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().usuario = {
      nombres: 'Ana',
      apellidos: 'Paz',
      rol: { clave: 'oficina_tecnica' },
    } as Usuario
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/partidas', component: { template: '<p>Vista</p>' }, meta: { porProyecto: true } },
        { path: '/proveedores', component: { template: '<p>Vista</p>' } },
      ],
    })
    await router.push(ruta)
    const wrapper = mount(AppLayout, { global: { plugins: [pinia, router, PrimeVue] } })
    await flushPromises()
    return { wrapper, router }
  }

  const selector = (wrapper: Awaited<ReturnType<typeof montarEn>>['wrapper']) =>
    wrapper.findAllComponents(Select).find((s) => s.props('ariaLabel') === 'Proyecto')

  it('en una vista por proyecto ofrece los proyectos junto al perfil y cambia el elegido', async () => {
    const { wrapper } = await montarEn('/partidas')
    const select = selector(wrapper)!

    expect(wrapper.get('header').text()).toContain('Residencial Los Álamos')
    expect((select.props('options') as { nombre: string }[]).map((o) => o.nombre)).toEqual([
      'Residencial Los Álamos',
      'Torre Empresarial Javier Prado',
      'Condominio Las Palmeras',
    ])

    await select.setValue(2)
    expect(useProyectoActualStore().proyectoId).toBe(2)
    expect(wrapper.get('header').text()).toContain('Torre Empresarial Javier Prado')
  })

  it('no aparece en las vistas que no dependen del proyecto', async () => {
    const { wrapper } = await montarEn('/proveedores')

    expect(selector(wrapper)).toBeUndefined()
    expect(useProyectoActualStore().cargado).toBe(false)
  })

  it('con un solo proyecto lo muestra sin nada que elegir', async () => {
    servicios.asignados = [2]
    const { wrapper } = await montarEn('/partidas')

    expect(selector(wrapper)).toBeUndefined()
    expect(wrapper.get('[data-proyecto-actual]').attributes('title')).toBe(
      'Torre Empresarial Javier Prado',
    )
  })

  it('sin proyectos asignados lo dice', async () => {
    servicios.asignados = []
    const { wrapper } = await montarEn('/partidas')

    expect(wrapper.get('header').text()).toContain('Sin proyectos asignados')
  })

  it('un enlace con ?proyecto= elige ese proyecto y lo quita de la URL', async () => {
    const { router } = await montarEn('/partidas?proyecto=3')

    expect(useProyectoActualStore().proyectoId).toBe(3)
    expect(router.currentRoute.value.fullPath).toBe('/partidas')
    expect(toast.add).not.toHaveBeenCalled()
  })

  it.each(['1', '99', 'abc'])(
    'con ?proyecto=%s que no le corresponde avisa en vez de cambiar en silencio',
    async (proyecto) => {
      servicios.asignados = [2, 3]
      const { router } = await montarEn(`/partidas?proyecto=${proyecto}`)

      expect(useProyectoActualStore().proyectoId).toBe(2)
      expect(toast.add).toHaveBeenCalledWith(
        expect.objectContaining({
          severity: 'warn',
          summary: 'No tiene acceso a ese proyecto o ya no está activo.',
          detail: 'Se muestra Torre Empresarial Javier Prado.',
        }),
      )
      expect(router.currentRoute.value.fullPath).toBe('/partidas')
    },
  )
})

describe('AppLayout · ícono de alertas (CU14 paso 4)', () => {
  beforeEach(() => simularPantalla(true))

  afterEach(() => {
    vi.unstubAllGlobals()
    servicios.alertasPendientes.mockReset().mockResolvedValue(0)
  })

  async function montarComo(rol: RolClave) {
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().usuario = { nombres: 'Ana', apellidos: 'Paz', rol: { clave: rol } } as Usuario
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/:ruta(.*)*', component: { template: '<p>Vista</p>' } }],
    })
    await router.push('/')
    const wrapper = mount(AppLayout, { global: { plugins: [pinia, router, PrimeVue] } })
    await flushPromises()
    return wrapper
  }

  it.each<RolClave>(['ingeniero_produccion', 'gerencia'])(
    'muestra a %s cuántas alertas siguen pendientes y lleva a la pantalla',
    async (rol) => {
      servicios.alertasPendientes.mockResolvedValue(3)
      const wrapper = await montarComo(rol)

      const campana = wrapper.get('[data-campana]')
      expect(campana.attributes('href')).toBe('/alertas')
      expect(campana.attributes('aria-label')).toBe('Alertas de consumo: 3 alertas pendientes')
      expect(campana.text()).toContain('3')
    },
  )

  it('sin pendientes no muestra número', async () => {
    const wrapper = await montarComo('gerencia')

    expect(wrapper.get('[data-campana]').text()).toBe('')
  })

  it.each<RolClave>(['almacenero', 'oficina_tecnica'])('no aparece a %s', async (rol) => {
    const wrapper = await montarComo(rol)

    expect(wrapper.find('[data-campana]').exists()).toBe(false)
    expect(servicios.alertasPendientes).not.toHaveBeenCalled()
  })
})
