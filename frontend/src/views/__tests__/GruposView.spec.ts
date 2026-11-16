import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { asentar, usarRelojFalso } from './asentar'
import PrimeVue from 'primevue/config'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import MultiSelect from 'primevue/multiselect'
import { errorDeValidacion } from '@/services/errores'
import { createPinia, setActivePinia } from 'pinia'
import type { GruposServicio } from '@/services/grupos'
import GruposView from '@/views/GruposView.vue'
import { useAuthStore } from '@/stores/auth'
import type { RolClave, Usuario } from '@/types/auth'

const { toast, confirm, servicios } = vi.hoisted(() => ({
  toast: { add: vi.fn() },
  confirm: { require: vi.fn() },
  servicios: { grupos: null as GruposServicio | null, conPartidas: [] as number[] },
}))

vi.mock('primevue/usetoast', () => ({ useToast: () => toast }))
vi.mock('primevue/useconfirm', () => ({ useConfirm: () => confirm }))

// Cada prueba arranca con los datos de ejemplo intactos.
// Los elementos solo se leen (columna y combo): basta con los de ejemplo.
vi.mock('@/services/elementos', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/elementos')>()
  return { ...real, elementosServicio: real.crearElementosEnMemoria() }
})

vi.mock('@/services/grupos', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/grupos')>()
  return {
    ...real,
    get gruposServicio() {
      return servicios.grupos
    },
  }
})

async function montar(rol: RolClave = 'oficina_tecnica') {
  const { crearGruposEnMemoria } = await import('@/services/grupos')
  servicios.grupos = crearGruposEnMemoria(async (id) => servicios.conPartidas.includes(id))
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().usuario = { rol: { clave: rol } } as Usuario
  const vista = mount(GruposView, {
    global: { plugins: [PrimeVue, pinia], stubs: { teleport: true } },
  })
  await asentar()
  return vista
}

const filas = (vista: VueWrapper) =>
  vista.findAll('tbody tr').map((tr) =>
    tr
      .findAll('td')
      .map((td) => td.text())
      .slice(0, 2),
  )

async function pulsar(vista: VueWrapper, etiqueta: string) {
  await vista.get(`button[aria-label="${etiqueta}"]`).trigger('click')
  await asentar()
}

async function llenar(vista: VueWrapper, nombre: string, orden?: number) {
  await vista
    .findAllComponents(InputText)
    .find((t) => t.attributes('id') === 'nombre')!
    .setValue(nombre)
  if (orden !== undefined)
    await vista
      .findAllComponents(InputNumber)
      .find((n) => n.props('inputId') === 'orden')!
      .setValue(orden)
}

async function guardar(vista: VueWrapper) {
  await vista.get('form').trigger('submit')
  await asentar()
}

enableAutoUnmount(afterEach)
beforeEach(usarRelojFalso)
afterEach(() => vi.useRealTimers())

describe('GruposView', () => {
  beforeEach(() => {
    toast.add.mockClear()
    confirm.require.mockReset()
    servicios.conPartidas = []
  })

  it('lista el catálogo por orden', async () => {
    const vista = await montar()

    expect(filas(vista).slice(0, 3)).toEqual([
      ['1', 'Muros anclados'],
      ['2', 'Cimentación'],
      ['3', 'Cisterna'],
    ])
  })

  it('registra un grupo al final por defecto', async () => {
    const vista = await montar()
    await pulsar(vista, 'Nuevo grupo')
    await llenar(vista, 'Obras exteriores')
    await guardar(vista)

    expect(toast.add).toHaveBeenCalledWith(expect.objectContaining({ summary: 'Grupo registrado' }))
    expect(filas(vista).slice(-1)[0]).toEqual(['8', 'Obras exteriores'])
  })

  it('muestra los elementos que admite cada grupo', async () => {
    const vista = await montar()
    const cimentacion = vista
      .findAll('tbody tr')
      .map((tr) => tr.findAll('td').map((td) => td.text()))
      .find((c) => c[1] === 'Cimentación')!

    expect(cimentacion[2]).toBe('Zapata, Cimiento corrido, Viga, Losa maciza')
  })

  it('registra un grupo con los elementos que admite', async () => {
    const vista = await montar()
    const crear = vi.spyOn(servicios.grupos!, 'crear')
    await pulsar(vista, 'Nuevo grupo')

    expect(vista.get('[data-aviso]').text()).toBe(
      'Sin elementos, no se podrán registrar partidas en este grupo.',
    )
    await llenar(vista, 'Obras exteriores')
    await vista.findComponent(MultiSelect).setValue([1, 7])
    await guardar(vista)

    expect(crear).toHaveBeenCalledWith(expect.objectContaining({ elementos: [1, 7] }))
    expect((await servicios.grupos!.listar()).slice(-1)[0]?.elementos).toEqual([1, 7])
  })

  it('muestra bajo el campo el elemento que no se puede quitar', async () => {
    const vista = await montar()
    vi.spyOn(servicios.grupos!, 'actualizar').mockRejectedValueOnce(
      errorDeValidacion({
        elementos: ['No se puede quitar Zapata: hay partidas de este grupo que lo usan'],
      }),
    )
    await pulsar(vista, 'Editar Cimentación')
    await vista.findComponent(MultiSelect).setValue([2])
    await guardar(vista)

    expect(vista.get('#elementos-error').text()).toBe(
      'No se puede quitar Zapata: hay partidas de este grupo que lo usan',
    )
    expect(toast.add).not.toHaveBeenCalled()
  })

  it('propone la partida de control 2.02.02 y exige números separados por puntos', async () => {
    const vista = await montar()
    await pulsar(vista, 'Nuevo grupo')
    const partidaControl = () => vista.get<HTMLInputElement>('#partida_control')

    expect(partidaControl().element.value).toBe('2.02.02')
    await partidaControl().setValue('2.02-A')
    await llenar(vista, 'Obras exteriores')
    await guardar(vista)
    expect(vista.get('#partida_control-error').text()).toBe('Use solo números separados por puntos')

    await partidaControl().setValue('2.03.01')
    await guardar(vista)
    expect((await servicios.grupos!.listar()).slice(-1)[0]).toMatchObject({
      nombre: 'Obras exteriores',
      partida_control: '2.03.01',
    })
  })

  it('no repite el nombre de un grupo', async () => {
    const vista = await montar()
    await pulsar(vista, 'Nuevo grupo')
    await llenar(vista, 'cisterna', 9)
    await guardar(vista)

    expect(vista.get('#nombre-error').text()).toBe('El grupo ya existe')
    expect(toast.add).not.toHaveBeenCalled()
  })

  it('edita el orden de un grupo y reordena la lista', async () => {
    const vista = await montar()
    await pulsar(vista, 'Editar Muros anclados')
    await llenar(vista, 'Muros anclados', 10)
    await guardar(vista)

    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ summary: 'Cambios guardados.' }),
    )
    expect(filas(vista)[0]).toEqual(['2', 'Cimentación'])
    expect(filas(vista).slice(-1)[0]).toEqual(['10', 'Muros anclados'])
  })

  it('elimina un grupo sin partidas y avisa si tiene partidas', async () => {
    servicios.conPartidas = [2]
    const vista = await montar()
    confirm.require.mockImplementation((o: { accept: () => Promise<void> }) => o.accept())

    await pulsar(vista, 'Eliminar Cisterna')
    expect(toast.add).toHaveBeenCalledWith(expect.objectContaining({ summary: 'Grupo eliminado' }))
    expect(vista.text()).not.toContain('Cisterna')

    await pulsar(vista, 'Eliminar Cimentación')
    expect(toast.add).toHaveBeenLastCalledWith(
      expect.objectContaining({
        severity: 'error',
        summary: 'El grupo tiene partidas registradas; reasígnelas antes de eliminarlo.',
      }),
    )
    expect(vista.text()).toContain('Cimentación')
  })

  it('al administrador le muestra el catálogo sin acciones de escritura', async () => {
    const vista = await montar('administrador')

    expect(filas(vista).length).toBeGreaterThan(0)
    expect(vista.find('button[aria-label="Nuevo grupo"]').exists()).toBe(false)
    expect(vista.find('button[aria-label^="Editar "]').exists()).toBe(false)
    expect(vista.find('button[aria-label^="Eliminar "]').exists()).toBe(false)
  })
})
