import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { asentar, usarRelojFalso } from './asentar'
import PrimeVue from 'primevue/config'
import { createPinia, setActivePinia } from 'pinia'
import ProveedoresView from '@/views/proveedores/ProveedoresView.vue'
import { useAuthStore } from '@/stores/auth'
import type { RolClave, Usuario } from '@/types/auth'
import type { Proveedor } from '@/types/proveedor'

const { toast, api } = vi.hoisted(() => ({
  toast: { add: vi.fn() },
  api: { listar: vi.fn(), crear: vi.fn(), actualizar: vi.fn(), eliminar: vi.fn() },
}))

vi.mock('primevue/usetoast', () => ({ useToast: () => toast }))
vi.mock('primevue/useconfirm', () => ({ useConfirm: () => ({ require: vi.fn() }) }))
vi.mock('@/api/proveedores', () => ({ proveedoresApi: api }))

enableAutoUnmount(beforeEach)

const unicon: Proveedor = {
  id: 1,
  ruc: '20100030595',
  razon_social: 'UNICON S.A.',
  activo: true,
  contacto_nombre: 'Rosa Quispe',
  contacto_telefono: '987654321',
}
const inactivo: Proveedor = {
  id: 2,
  ruc: '20512345678',
  razon_social: 'Concremax S.A.',
  activo: false,
}

async function montar(rol: RolClave = 'oficina_tecnica') {
  api.listar.mockResolvedValue({ data: [unicon, inactivo], meta: { total: 2 } })
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().usuario = { rol: { clave: rol } } as Usuario
  const vista = mount(ProveedoresView, {
    global: { plugins: [PrimeVue, pinia], stubs: { teleport: true } },
  })
  await asentar()
  return vista
}

afterEach(() => vi.useRealTimers())

describe('ProveedoresView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    usarRelojFalso()
    // Select de PrimeVue lo usa al montarse; jsdom no lo trae.
    vi.stubGlobal(
      'matchMedia',
      vi.fn(() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() })),
    )
  })

  it('lista contacto, teléfono y estado', async () => {
    const vista = await montar()
    const texto = vista.get('tbody').text()
    expect(texto).toContain('Rosa Quispe')
    expect(texto).toContain('987654321')
    expect(texto).toContain('Activo')
    expect(texto).toContain('Inactivo')
  })

  it('no muestra el contacto a los roles sin permiso sobre proveedores', async () => {
    const vista = await montar('almacenero')
    expect(vista.text()).not.toContain('Teléfono')
    expect(vista.get('tbody').text()).not.toContain('987654321')
  })

  it('agrupa el formulario en Contacto y Condiciones comerciales', async () => {
    const vista = await montar()
    await vista.get('button[aria-label="Editar UNICON S.A."]').trigger('click')
    await asentar()
    const grupos = vista.findAll('legend').map((l) => l.text())
    expect(grupos).toEqual(['Contacto', 'Condiciones comerciales'])
  })

  it('valida el teléfono con el mensaje en español', async () => {
    const vista = await montar()
    await vista
      .findAll('button')
      .find((b) => b.text() === 'Nuevo proveedor')!
      .trigger('click')
    await asentar()
    await vista.get('#ruc').setValue('20100030595')
    await vista.get('#razon_social').setValue('UNICON S.A.')
    await vista.get('#contacto_telefono').setValue('887654321')
    await vista.get('form').trigger('submit')
    await asentar()
    expect(vista.text()).toContain('El teléfono debe tener 9 dígitos y empezar con 9')
    expect(api.crear).not.toHaveBeenCalled()
  })

  it('crea un proveedor con solo RUC y razón social', async () => {
    api.crear.mockResolvedValue(unicon)
    const vista = await montar()
    await vista
      .findAll('button')
      .find((b) => b.text() === 'Nuevo proveedor')!
      .trigger('click')
    await asentar()
    await vista.get('#ruc').setValue('20100030595')
    await vista.get('#razon_social').setValue('UNICON S.A.')
    await vista.get('form').trigger('submit')
    await asentar()
    expect(api.crear).toHaveBeenCalledWith(
      expect.objectContaining({
        ruc: '20100030595',
        razon_social: 'UNICON S.A.',
        contacto_telefono: null,
      }),
    )
  })
})
