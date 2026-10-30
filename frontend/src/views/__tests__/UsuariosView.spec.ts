import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { asentar, usarRelojFalso } from './asentar'
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
  await asentar()
  return vista
}

function filas(vista: VueWrapper) {
  return vista.findAll('tbody tr').map((tr) => tr.findAll('td').map((td) => td.text()))
}

const esFiltroRol = (s: VueWrapper) =>
  (s.props() as { ariaLabel?: string }).ariaLabel === 'Filtrar por rol'

async function abrirNuevo(vista: VueWrapper) {
  await vista.get('button[aria-label="Nuevo usuario"]').trigger('click')
  await asentar()
}

async function llenar(vista: VueWrapper, email: string) {
  await vista.get('#nombres').setValue('Ana')
  await vista.get('#apellidos').setValue('Torres')
  await vista.get('#email').setValue(email)
  await vista
    .findAllComponents(Select)
    .find((s) => !esFiltroRol(s))!
    .setValue('gerencia')
  await vista.findComponent(MultiSelect).setValue([2])
}

async function filtrarPorRol(vista: VueWrapper, rol: string) {
  await vista.findAllComponents(Select).find(esFiltroRol)!.setValue(rol)
}

async function guardar(vista: VueWrapper) {
  await vista.get('form').trigger('submit')
  await asentar()
}

enableAutoUnmount(afterEach)
beforeEach(usarRelojFalso)
afterEach(() => vi.useRealTimers())

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
    expect(filas(vista)[3]?.slice(1, 4)).toEqual([
      'oficina_tecnica@sicco.test',
      'Oficina técnica',
      'Inactivo',
    ])
    expect(vista.get('tbody tr:nth-child(4) td span').text()).toBe('Jorge Salas')
  })

  it('filtra por rol y vuelve a todos con «Todos los roles»', async () => {
    const vista = await montar()
    const filtro = vista.findAllComponents(Select).find(esFiltroRol)

    expect(filtro?.text()).toBe('Todos los roles')
    await filtrarPorRol(vista, 'oficina_tecnica')
    expect(filas(vista).map((f) => f[1])).toEqual(['oficina_tecnica@sicco.test'])

    await vista.get('input[type=search]').setValue('lucia')
    expect(vista.text()).toContain('No hay usuarios que coincidan con la búsqueda.')

    await vista.get('input[type=search]').setValue('')
    await filtrarPorRol(vista, '')
    expect(filas(vista)).toHaveLength(4)
  })

  it('en celular oculta correo y rol, y muestra el rol bajo el nombre', async () => {
    const vista = await montar()
    const encabezados = vista.findAll('th')
    const rolBajoNombre = vista.get('tbody tr:nth-child(4) td:first-child .sm\\:hidden')

    expect(encabezados[1]!.classes()).toContain('hidden')
    expect(encabezados[2]!.classes()).toContain('hidden')
    expect(encabezados[3]!.classes()).not.toContain('hidden')
    expect(rolBajoNombre.text()).toBe('Oficina técnica')
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
    expect(filas(vista)[4]?.slice(1, 4)).toEqual(['ana.torres@sicco.test', 'Gerencia', 'Activo'])
    expect(vista.get('tbody tr:nth-child(5) td span').text()).toBe('Ana Torres')
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
    await asentar()

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
