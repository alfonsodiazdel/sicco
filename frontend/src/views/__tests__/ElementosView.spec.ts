import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { asentar, usarRelojFalso } from './asentar'
import PrimeVue from 'primevue/config'
import InputText from 'primevue/inputtext'
import MultiSelect from 'primevue/multiselect'
import { createPinia, setActivePinia } from 'pinia'
import type { ElementosServicio } from '@/services/elementos'
import ElementosView from '@/views/catalogos/ElementosView.vue'
import { useAuthStore } from '@/stores/auth'
import type { RolClave, Usuario } from '@/types/auth'

const { toast, confirm, servicios } = vi.hoisted(() => ({
  toast: { add: vi.fn() },
  confirm: { require: vi.fn() },
  servicios: { elementos: null as ElementosServicio | null, enUso: [] as string[] },
}))

vi.mock('primevue/usetoast', () => ({ useToast: () => toast }))
vi.mock('primevue/useconfirm', () => ({ useConfirm: () => confirm }))

// Los grupos solo se leen (columna y combo): basta con los de ejemplo.
vi.mock('@/services/grupos', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/grupos')>()
  return { ...real, gruposServicio: real.crearGruposEnMemoria() }
})

vi.mock('@/services/elementos', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/elementos')>()
  return {
    ...real,
    get elementosServicio() {
      return servicios.elementos
    },
  }
})

async function montar(rol: RolClave = 'oficina_tecnica') {
  const { crearElementosEnMemoria } = await import('@/services/elementos')
  servicios.elementos = crearElementosEnMemoria(async (nombre) => servicios.enUso.includes(nombre))
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().usuario = { rol: { clave: rol } } as Usuario
  const vista = mount(ElementosView, {
    global: { plugins: [PrimeVue, pinia], stubs: { teleport: true } },
  })
  await asentar()
  return vista
}

// Nombre y estado; la columna de grupos se revisa aparte.
const filas = (vista: VueWrapper) =>
  vista.findAll('tbody tr').map((tr) => {
    const celdas = tr.findAll('td').map((td) => td.text())
    return [celdas[0], celdas[2]]
  })

const gruposDe = (vista: VueWrapper, nombre: string) =>
  vista
    .findAll('tbody tr')
    .map((tr) => tr.findAll('td').map((td) => td.text()))
    .find((celdas) => celdas[0] === nombre)?.[1]

async function pulsar(vista: VueWrapper, etiqueta: string) {
  await vista.get(`button[aria-label="${etiqueta}"]`).trigger('click')
  await asentar()
}

async function guardarNombre(vista: VueWrapper, nombre: string) {
  await vista
    .findAllComponents(InputText)
    .find((t) => t.attributes('id') === 'nombre')!
    .setValue(nombre)
  await vista.get('form').trigger('submit')
  await asentar()
}

enableAutoUnmount(afterEach)
beforeEach(usarRelojFalso)
afterEach(() => vi.useRealTimers())

describe('ElementosView', () => {
  beforeEach(() => {
    toast.add.mockClear()
    confirm.require.mockReset()
    servicios.enUso = []
  })

  it('lista los elementos con su estado', async () => {
    const vista = await montar()

    expect(filas(vista).slice(0, 3)).toEqual([
      ['Zapata', 'Activo'],
      ['Cimiento corrido', 'Activo'],
      ['Columna', 'Activo'],
    ])
    expect(filas(vista)).toHaveLength(8)
  })

  it('registra un elemento', async () => {
    const vista = await montar()
    await pulsar(vista, 'Nuevo elemento')
    await guardarNombre(vista, 'Muro de contención')

    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ summary: 'Elemento registrado' }),
    )
    expect(filas(vista).slice(-1)[0]).toEqual(['Muro de contención', 'Activo'])
  })

  it('muestra los grupos de cada elemento en el orden del presupuesto', async () => {
    const vista = await montar()

    expect(gruposDe(vista, 'Columna')).toBe('Verticales sótanos, Verticales superestructura')
  })

  it('registra un elemento ya asignado a grupos y conserva los grupos al desactivarlo', async () => {
    const vista = await montar()
    await pulsar(vista, 'Nuevo elemento')
    await vista.findComponent(MultiSelect).setValue([1, 4])
    await guardarNombre(vista, 'Muro de contención')

    expect(gruposDe(vista, 'Muro de contención')).toBe('Muros anclados, Verticales sótanos')

    await pulsar(vista, 'Desactivar Muro de contención')
    expect(gruposDe(vista, 'Muro de contención')).toBe('Muros anclados, Verticales sótanos')
  })

  it('exige el nombre, de 30 caracteres como máximo, y no lo repite', async () => {
    const vista = await montar()
    await pulsar(vista, 'Nuevo elemento')

    await guardarNombre(vista, '')
    expect(vista.get('#nombre-error').text()).toBe('Este dato es requerido')
    await guardarNombre(vista, 'a'.repeat(31))
    expect(vista.get('#nombre-error').text()).toBe('Debe tener como máximo 30 caracteres')
    await guardarNombre(vista, 'zapata')
    expect(vista.get('#nombre-error').text()).toBe('El elemento ya existe')
    expect(toast.add).not.toHaveBeenCalled()
  })

  it('renombra uno libre y avisa que uno en uso solo se desactiva', async () => {
    servicios.enUso = ['Zapata']
    const vista = await montar()

    await pulsar(vista, 'Editar Viga')
    await guardarNombre(vista, 'Viga peraltada')
    expect(filas(vista).map((f) => f[0])).toContain('Viga peraltada')

    await pulsar(vista, 'Editar Zapata')
    await guardarNombre(vista, 'Zapata aislada')
    expect(toast.add).toHaveBeenLastCalledWith(
      expect.objectContaining({
        severity: 'error',
        summary: 'Este elemento está en uso en partidas: solo se puede desactivar.',
      }),
    )
    expect(filas(vista).map((f) => f[0])).toContain('Zapata')
  })

  it('desactiva y activa, también uno en uso', async () => {
    servicios.enUso = ['Zapata']
    const vista = await montar()

    await pulsar(vista, 'Desactivar Zapata')
    expect(filas(vista)[0]).toEqual(['Zapata', 'Inactivo'])
    await pulsar(vista, 'Activar Zapata')
    expect(filas(vista)[0]).toEqual(['Zapata', 'Activo'])
  })

  it('elimina uno libre y avisa si está en uso', async () => {
    servicios.enUso = ['Zapata']
    const vista = await montar()
    confirm.require.mockImplementation((o: { accept: () => Promise<void> }) => o.accept())

    await pulsar(vista, 'Eliminar Viga')
    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ summary: 'Elemento eliminado' }),
    )
    expect(vista.text()).not.toContain('Viga')

    await pulsar(vista, 'Eliminar Zapata')
    expect(toast.add).toHaveBeenLastCalledWith(
      expect.objectContaining({
        severity: 'error',
        summary: 'Este elemento está en uso en partidas: solo se puede desactivar.',
      }),
    )
    expect(vista.text()).toContain('Zapata')
  })

  it('al administrador le muestra el catálogo sin acciones de escritura', async () => {
    const vista = await montar('administrador')

    expect(filas(vista)).toHaveLength(8)
    expect(vista.find('button[aria-label="Nuevo elemento"]').exists()).toBe(false)
    expect(vista.find('button[aria-label^="Editar "]').exists()).toBe(false)
    expect(vista.find('button[aria-label^="Desactivar "]').exists()).toBe(false)
    expect(vista.find('button[aria-label^="Eliminar "]').exists()).toBe(false)
  })
})
