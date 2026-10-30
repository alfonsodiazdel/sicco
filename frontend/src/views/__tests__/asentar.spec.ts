import { afterEach, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h, ref } from 'vue'
import { usarRelojFalso } from './asentar'

const RelojDelSistema = globalThis.Date

const Contador = defineComponent(() => {
  const clics = ref(0)
  return () => h('button', { onClick: () => clics.value++ }, String(clics.value))
})

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

it('no pierde un clic si el reloj del sistema retrocede', async () => {
  usarRelojFalso()
  const vista = mount(Contador)
  // WSL2 resincroniza la hora y Date.now() puede volver atrás más de un segundo.
  const ahora = RelojDelSistema.now()
  vi.spyOn(RelojDelSistema, 'now').mockReturnValue(ahora - 1500)

  await vista.get('button').trigger('click')

  expect(vista.text()).toBe('1')
})
