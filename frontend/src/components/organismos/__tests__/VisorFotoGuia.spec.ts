import { afterEach, describe, expect, it } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import VisorFotoGuia from '@/components/organismos/VisorFotoGuia.vue'

function montar(props: { etiqueta?: string } = {}) {
  return mount(VisorFotoGuia, {
    props: { guiaId: 7, numero: 'T001-00012345', ...props },
    global: { plugins: [PrimeVue], stubs: { teleport: true } },
  })
}

async function abrir(vista: ReturnType<typeof montar>) {
  await vista.get('button[aria-label="Ver foto de la guía T001-00012345"]').trigger('click')
  await flushPromises()
}

enableAutoUnmount(afterEach)

describe('VisorFotoGuia', () => {
  it('no descarga la foto hasta que se abre', () => {
    const vista = montar()

    expect(vista.find('img').exists()).toBe(false)
  })

  it('abre la foto de la guía desde la API', async () => {
    const vista = montar()
    await abrir(vista)

    const img = vista.get('img')
    expect(img.attributes('src')).toBe('/api/v1/guias/7/foto')
    expect(img.attributes('alt')).toBe('Foto de la guía T001-00012345')
    expect(vista.find('a[target="_blank"]').exists()).toBe(false)

    await img.trigger('load')
    expect(vista.get('a[target="_blank"]').attributes('href')).toBe('/api/v1/guias/7/foto')
  })

  it('avisa si la foto no se puede cargar', async () => {
    const vista = montar()
    await abrir(vista)
    await vista.get('img').trigger('error')

    expect(vista.get('[role="alert"]').text()).toBe('No se pudo cargar la foto de la guía.')
    expect(vista.find('img').exists()).toBe(false)
  })

  it('muestra un texto en el botón si se le da', () => {
    const vista = montar({ etiqueta: 'Ver foto' })

    expect(vista.get('button').text()).toContain('Ver foto')
  })
})
