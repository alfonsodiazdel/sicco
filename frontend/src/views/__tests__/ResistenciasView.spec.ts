import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { asentar, usarRelojFalso } from './asentar'
import PrimeVue from 'primevue/config'
import InputNumber from 'primevue/inputnumber'
import { createPinia, setActivePinia } from 'pinia'
import type { ResistenciasServicio } from '@/services/resistencias'
import ResistenciasView from '@/views/catalogos/ResistenciasView.vue'
import { useAuthStore } from '@/stores/auth'
import type { RolClave, Usuario } from '@/types/auth'

const { toast, confirm, servicios } = vi.hoisted(() => ({
  toast: { add: vi.fn() },
  confirm: { require: vi.fn() },
  servicios: { resistencias: null as ResistenciasServicio | null, enUso: [] as number[] },
}))

vi.mock('primevue/usetoast', () => ({ useToast: () => toast }))
vi.mock('primevue/useconfirm', () => ({ useConfirm: () => confirm }))

vi.mock('@/services/resistencias', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/resistencias')>()
  return {
    ...real,
    get resistenciasServicio() {
      return servicios.resistencias
    },
  }
})

async function montar(rol: RolClave = 'oficina_tecnica') {
  const { crearResistenciasEnMemoria } = await import('@/services/resistencias')
  servicios.resistencias = crearResistenciasEnMemoria(async (fc) => servicios.enUso.includes(fc))
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().usuario = { rol: { clave: rol } } as Usuario
  const vista = mount(ResistenciasView, {
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

async function guardarFc(vista: VueWrapper, fc: number | null) {
  await vista
    .findAllComponents(InputNumber)
    .find((n) => n.props('inputId') === 'fc_kg_cm2')!
    .setValue(fc)
  await vista.get('form').trigger('submit')
  await asentar()
}

enableAutoUnmount(afterEach)
beforeEach(usarRelojFalso)
afterEach(() => vi.useRealTimers())

describe('ResistenciasView', () => {
  beforeEach(() => {
    toast.add.mockClear()
    confirm.require.mockReset()
    servicios.enUso = []
  })

  it('lista las resistencias con su estado, de menor a mayor', async () => {
    const vista = await montar()

    expect(filas(vista)).toEqual([
      ["f'c 175 kg/cm²", 'Activo'],
      ["f'c 210 kg/cm²", 'Activo'],
      ["f'c 280 kg/cm²", 'Activo'],
      ["f'c 350 kg/cm²", 'Activo'],
    ])
  })

  it("registra una resistencia con el nombre «f'c N kg/cm²»", async () => {
    const vista = await montar()
    await pulsar(vista, 'Nueva resistencia')
    await guardarFc(vista, 420)

    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ summary: 'Resistencia registrada' }),
    )
    expect(filas(vista).slice(-1)[0]).toEqual(["f'c 420 kg/cm²", 'Activo'])
  })

  it("exige el f'c, un entero mayor a cero, y no lo repite", async () => {
    const vista = await montar()
    await pulsar(vista, 'Nueva resistencia')

    await guardarFc(vista, null)
    expect(vista.get('#fc_kg_cm2-error').text()).toBe('Este dato es requerido')
    await guardarFc(vista, 0)
    expect(vista.get('#fc_kg_cm2-error').text()).toBe('Ingrese un valor mayor a cero')
    await guardarFc(vista, 210)
    expect(vista.get('#fc_kg_cm2-error').text()).toBe('La resistencia ya existe')
    expect(toast.add).not.toHaveBeenCalled()
  })

  it('edita una libre y avisa que una en uso solo se desactiva', async () => {
    servicios.enUso = [210]
    const vista = await montar()

    await pulsar(vista, "Editar f'c 175 kg/cm²")
    await guardarFc(vista, 180)
    expect(filas(vista).map((f) => f[0])).toContain("f'c 180 kg/cm²")

    await pulsar(vista, "Editar f'c 210 kg/cm²")
    await guardarFc(vista, 215)
    expect(toast.add).toHaveBeenLastCalledWith(
      expect.objectContaining({
        severity: 'error',
        summary: 'Esta resistencia está en uso: solo se puede desactivar.',
      }),
    )
    expect(filas(vista).map((f) => f[0])).toContain("f'c 210 kg/cm²")
  })

  it('desactiva y activa, también una en uso', async () => {
    servicios.enUso = [210]
    const vista = await montar()

    await pulsar(vista, "Desactivar f'c 210 kg/cm²")
    expect(filas(vista)[1]).toEqual(["f'c 210 kg/cm²", 'Inactivo'])
    await pulsar(vista, "Activar f'c 210 kg/cm²")
    expect(filas(vista)[1]).toEqual(["f'c 210 kg/cm²", 'Activo'])
  })

  it('elimina una libre y avisa si está en uso', async () => {
    servicios.enUso = [210]
    const vista = await montar()
    confirm.require.mockImplementation((o: { accept: () => Promise<void> }) => o.accept())

    await pulsar(vista, "Eliminar f'c 175 kg/cm²")
    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ summary: 'Resistencia eliminada' }),
    )
    expect(vista.text()).not.toContain("f'c 175")

    await pulsar(vista, "Eliminar f'c 210 kg/cm²")
    expect(toast.add).toHaveBeenLastCalledWith(
      expect.objectContaining({
        severity: 'error',
        summary: 'Esta resistencia está en uso: solo se puede desactivar.',
      }),
    )
    expect(vista.text()).toContain("f'c 210")
  })

  it('al administrador le muestra el catálogo sin acciones de escritura', async () => {
    const vista = await montar('administrador')

    expect(filas(vista)).toHaveLength(4)
    expect(vista.find('button[aria-label="Nueva resistencia"]').exists()).toBe(false)
    expect(vista.find('button[aria-label^="Editar "]').exists()).toBe(false)
    expect(vista.find('button[aria-label^="Eliminar "]').exists()).toBe(false)
  })
})
