import { afterEach, describe, expect, it } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import DialogoFormulario from '@/components/organismos/DialogoFormulario.vue'

let dialogo: VueWrapper | undefined

async function montar() {
  dialogo = mount(DialogoFormulario, {
    props: { visible: true, titulo: 'Nuevo usuario' },
    slots: { default: '<input name="nombre" />' },
    global: { plugins: [PrimeVue] },
    attachTo: document.body,
  })
  await flushPromises()
  return dialogo
}

function boton(etiqueta: string) {
  const botones = [...document.body.querySelectorAll<HTMLButtonElement>('.p-dialog button')]
  const encontrado = botones.find((b) => b.textContent?.trim() === etiqueta)
  if (!encontrado) throw new Error(`No se encontró el botón ${etiqueta}`)
  return encontrado
}

afterEach(() => {
  dialogo?.unmount()
  dialogo = undefined
})

describe('DialogoFormulario', () => {
  it('fija Cancelar y Guardar al pie, fuera del contenido que se desplaza', async () => {
    await montar()

    for (const etiqueta of ['Cancelar', 'Guardar']) {
      expect(boton(etiqueta).closest('.p-dialog-footer')).not.toBeNull()
      expect(boton(etiqueta).closest('.p-dialog-content')).toBeNull()
    }
  })

  it('Guardar envía el formulario desde el pie', async () => {
    const componente = await montar()
    const guardar = boton('Guardar')

    expect(guardar.type).toBe('submit')
    expect(guardar.form).toBe(document.body.querySelector('.p-dialog-content form'))

    guardar.click()
    await componente.vm.$nextTick()

    expect(componente.emitted('guardar')).toHaveLength(1)
  })

  it('Cancelar cierra el diálogo sin guardar', async () => {
    const componente = await montar()

    boton('Cancelar').click()
    await componente.vm.$nextTick()

    expect(componente.emitted('cancelar')).toHaveLength(1)
    expect(componente.emitted('update:visible')).toEqual([[false]])
    expect(componente.emitted('guardar')).toBeUndefined()
  })
})
