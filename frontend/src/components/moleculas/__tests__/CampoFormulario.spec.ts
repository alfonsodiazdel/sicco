import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import CampoFormulario from '@/components/moleculas/CampoFormulario.vue'

type Props = {
  etiqueta: string
  para: string
  error?: string
  obligatorio?: boolean
  ancho?: 'medio' | 'completo'
}

function montar(props: Props) {
  return mount(CampoFormulario, {
    props,
    slots: {
      default: `<template #default="{ idError }">
        <input id="nombres" :aria-describedby="idError" />
      </template>`,
    },
  })
}

describe('CampoFormulario', () => {
  it('asocia la etiqueta al control y lo muestra', () => {
    const campo = montar({ etiqueta: 'Nombres', para: 'nombres' })

    expect(campo.get('label').text()).toBe('Nombres')
    expect(campo.get('label').attributes('for')).toBe('nombres')
    expect(campo.find('input#nombres').exists()).toBe(true)
  })

  it('marca con * los campos obligatorios', () => {
    const campo = montar({ etiqueta: 'Nombres', para: 'nombres', obligatorio: true })

    expect(campo.get('label').text()).toBe('Nombres *')
  })

  it('muestra el error solo cuando lo hay y lo enlaza al control', () => {
    const sinError = montar({ etiqueta: 'Nombres', para: 'nombres' })
    expect(sinError.find('small').exists()).toBe(false)
    expect(sinError.get('input').attributes('aria-describedby')).toBeUndefined()

    const conError = montar({
      etiqueta: 'Nombres',
      para: 'nombres',
      error: 'Este dato es requerido',
    })
    const error = conError.get('small')
    expect(error.text()).toBe('Este dato es requerido')
    expect(error.attributes('id')).toBe('nombres-error')
    expect(conError.get('input').attributes('aria-describedby')).toBe('nombres-error')
  })

  it('ocupa la fila completa por defecto y media fila con ancho «medio»', () => {
    expect(montar({ etiqueta: 'Correo', para: 'email' }).classes()).toContain('sm:col-span-2')
    expect(
      montar({ etiqueta: 'Nombres', para: 'nombres', ancho: 'medio' }).classes(),
    ).not.toContain('sm:col-span-2')
  })
})
