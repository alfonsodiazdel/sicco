import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import PrimeVue from 'primevue/config'
import AppLayout from '@/layouts/AppLayout.vue'

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
