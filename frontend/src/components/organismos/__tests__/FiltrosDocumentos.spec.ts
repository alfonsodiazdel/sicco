import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import FiltrosDocumentos from '@/components/organismos/FiltrosDocumentos.vue'

function montar(tipo: 'guias' | 'facturas', rangoInvalido = false) {
  return mount(FiltrosDocumentos, {
    props: {
      tipo,
      desde: null,
      hasta: null,
      proveedorId: null,
      estado: 'parcial',
      proveedores: [{ id: 1, ruc: '20100030595', razon_social: 'UNICON S.A.' }],
      estados: [{ valor: 'parcial', etiqueta: 'Parcial' }],
      rangoInvalido,
    },
    global: { plugins: [PrimeVue] },
  })
}

beforeEach(() => {
  vi.stubGlobal(
    'matchMedia',
    vi.fn<() => object>(() => ({
      matches: false,
      addEventListener: vi.fn<() => void>(),
      removeEventListener: vi.fn<() => void>(),
    })),
  )
})

describe('FiltrosDocumentos', () => {
  it('emite buscar al enviar el formulario', async () => {
    const filtros = montar('guias')

    await filtros.get('form').trigger('submit')

    expect(filtros.emitted('buscar')).toHaveLength(1)
  })

  it('muestra el estado solo en guías', () => {
    expect(montar('guias').find('#estado').exists()).toBe(true)
    expect(montar('facturas').find('#estado').exists()).toBe(false)
  })

  it('al cambiar de tipo limpia el estado', async () => {
    const filtros = montar('guias')

    await filtros.findComponent({ name: 'SelectButton' }).vm.$emit('update:modelValue', 'facturas')

    expect(filtros.emitted('update:tipo')).toEqual([['facturas']])
    expect(filtros.emitted('update:estado')).toEqual([[null]])
  })

  it('con un rango invertido avisa y no deja buscar', () => {
    const filtros = montar('guias', true)

    expect(filtros.get('#hasta-error').text()).toBe(
      'La fecha final debe ser igual o posterior a la inicial',
    )
    expect(filtros.get('button[type="submit"]').attributes('disabled')).toBeDefined()
  })
})
