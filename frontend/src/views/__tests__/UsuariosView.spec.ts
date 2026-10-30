import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import PrimeVue from 'primevue/config'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import type { UsuariosServicio } from '@/services/usuarios'
import UsuariosView from '@/views/UsuariosView.vue'

const { toast, servicio } = vi.hoisted(() => ({
  toast: { add: vi.fn() },
  servicio: { actual: null as UsuariosServicio | null },
}))

vi.mock('primevue/usetoast', () => ({ useToast: () => toast }))

// Cada prueba arranca con los datos de ejemplo intactos.
vi.mock('@/services/usuarios', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/usuarios')>()
  return {
    ...real,
    get usuariosServicio() {
      return servicio.actual
    },
  }
})

async function montar() {
  const { crearUsuariosEnMemoria } = await import('@/services/usuarios')
  servicio.actual = crearUsuariosEnMemoria()
  const vista = mount(UsuariosView, {
    global: { plugins: [PrimeVue], stubs: { teleport: true } },
  })
  await flushPromises()
  return vista
}

function filas(vista: VueWrapper) {
  return vista.findAll('tbody tr').map((tr) => tr.findAll('td').map((td) => td.text()))
}

async function abrirNuevo(vista: VueWrapper) {
  await vista.get('button[aria-label="Nuevo usuario"]').trigger('click')
  await flushPromises()
}

async function llenar(vista: VueWrapper, email: string) {
  await vista.get('#nombres').setValue('Ana')
  await vista.get('#apellidos').setValue('Torres')
  await vista.get('#email').setValue(email)
  await vista.findComponent(Select).setValue('gerencia')
  await vista.findComponent(MultiSelect).setValue([2])
}

async function guardar(vista: VueWrapper) {
  await vista.get('form').trigger('submit')
  await flushPromises()
}

enableAutoUnmount(afterEach)

describe('UsuariosView', () => {
  beforeEach(() => {
    toast.add.mockClear()
    // Select y MultiSelect de PrimeVue lo usan al montarse; jsdom no lo trae.
    vi.stubGlobal(
      'matchMedia',
      vi.fn(() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() })),
    )
  })

  it('lista los usuarios con nombre, correo, rol y estado', async () => {
    const vista = await montar()

    expect(vista.findAll('th').map((th) => th.text())).toEqual([
      'Nombre',
      'Correo',
      'Rol',
      'Estado',
      'Acciones',
    ])
    expect(filas(vista)).toHaveLength(4)
    expect(filas(vista)[3]?.slice(0, 4)).toEqual([
      'Jorge Salas',
      'oficina_tecnica@sicco.test',
      'Oficina técnica',
      'Inactivo',
    ])
  })

  it('exige los cinco campos obligatorios y no registra nada', async () => {
    const vista = await montar()
    await abrirNuevo(vista)
    await guardar(vista)

    expect(vista.findAll('small.text-red-600').map((e) => e.text())).toEqual(
      Array(5).fill('Este dato es requerido'),
    )
    expect(await servicio.actual!.listar()).toHaveLength(4)
  })

  it('registra un usuario, cierra el diálogo y lo muestra en la lista', async () => {
    const vista = await montar()
    await abrirNuevo(vista)
    await llenar(vista, 'ana.torres@sicco.test')
    await guardar(vista)

    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ summary: 'Usuario registrado correctamente' }),
    )
    expect(vista.find('form').exists()).toBe(false)
    expect(filas(vista)).toHaveLength(5)
    expect(filas(vista)[4]?.slice(0, 4)).toEqual([
      'Ana Torres',
      'ana.torres@sicco.test',
      'Gerencia',
      'Activo',
    ])
  })

  it('rechaza un correo ya registrado', async () => {
    const vista = await montar()
    await abrirNuevo(vista)
    await llenar(vista, 'Almacenero@SICCO.test')
    await guardar(vista)

    expect(vista.get('form').text()).toContain('El correo ya está registrado')
    expect(toast.add).not.toHaveBeenCalled()
    expect(await servicio.actual!.listar()).toHaveLength(4)
  })

  it('desactiva un usuario', async () => {
    const vista = await montar()
    await vista.get('button[aria-label="Desactivar Carlos Quispe"]').trigger('click')
    await flushPromises()

    expect(filas(vista)[1]?.[3]).toBe('Inactivo')
    expect(vista.find('button[aria-label="Activar Carlos Quispe"]').exists()).toBe(true)
    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ summary: 'Carlos Quispe quedó inactivo.' }),
    )
  })

  it('no ofrece la acción de eliminar', async () => {
    const vista = await montar()

    expect(vista.text()).not.toMatch(/eliminar/i)
    expect(vista.find('[aria-label^="Eliminar"]').exists()).toBe(false)
    expect(vista.find('.pi-trash').exists()).toBe(false)
  })
})
