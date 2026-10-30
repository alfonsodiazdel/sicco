import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import BuscadorTabla from '@/components/moleculas/BuscadorTabla.vue'

function montar(largo?: number) {
  return mount(BuscadorTabla, {
    props: { placeholder: 'Buscar', etiqueta: 'Buscar', largo },
    global: { plugins: [PrimeVue] },
  })
}

describe('BuscadorTabla', () => {
  it('limita el texto a 100 caracteres si no recibe largo', () => {
    expect(montar().get('input').attributes('maxlength')).toBe('100')
  })

  it('usa el largo que recibe', () => {
    expect(montar(80).get('input').attributes('maxlength')).toBe('80')
  })
})
