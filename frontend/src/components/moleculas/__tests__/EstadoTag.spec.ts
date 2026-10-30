import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import EstadoTag from '@/components/moleculas/EstadoTag.vue'

function montar(activo: boolean) {
  return mount(EstadoTag, { props: { activo }, global: { plugins: [PrimeVue] } })
}

describe('EstadoTag', () => {
  it('muestra «Activo» en verde para un registro activo', () => {
    const tag = montar(true)

    expect(tag.text()).toBe('Activo')
    expect(tag.classes()).toContain('p-tag-success')
  })

  it('muestra «Inactivo» en gris para un registro inactivo', () => {
    const tag = montar(false)

    expect(tag.text()).toBe('Inactivo')
    expect(tag.classes()).toContain('p-tag-secondary')
  })
})
