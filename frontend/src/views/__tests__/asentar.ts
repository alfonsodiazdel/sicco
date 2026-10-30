import { flushPromises } from '@vue/test-utils'
import { vi } from 'vitest'

// vee-validate valida el esquema tras un setTimeout de 5 ms, también al enviar el formulario, y
// flushPromises solo espera una vuelta de setImmediate. Con setTimeout falso, cada paso corre ese
// temporizador y las promesas que encadena sin depender de cuánta CPU tenga la corrida.
// Date también: Vue descarta un clic cuyo Date.now() (lo marca test-utils) es anterior al momento en
// que se puso el listener, y en WSL2 el reloj del sistema a veces retrocede más de un segundo.
export const usarRelojFalso = () =>
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] })

export async function asentar() {
  await flushPromises()
  await vi.runAllTimersAsync()
  await flushPromises()
}
