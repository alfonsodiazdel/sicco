import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, mount, type VueWrapper } from '@vue/test-utils'
import { asentar, usarRelojFalso } from './asentar'
import PrimeVue from 'primevue/config'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import { AxiosError, AxiosHeaders } from 'axios'
import { errorDeValidacion } from '@/services/errores'
import type { ProyectosServicio } from '@/services/proyectos'
import type { UsuariosServicio } from '@/services/usuarios'
import UsuariosView from '@/views/UsuariosView.vue'

const { toast, servicio } = vi.hoisted(() => ({
  toast: { add: vi.fn() },
  servicio: {
    actual: null as UsuariosServicio | null,
    proyectos: null as ProyectosServicio | null,
  },
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

// Las opciones de proyectos salen del servicio de proyectos; aquí, sin la API.
vi.mock('@/services/proyectos', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/proyectos')>()
  return {
    ...real,
    get proyectosServicio() {
      return servicio.proyectos
    },
  }
})

async function montar() {
  const { crearUsuariosEnMemoria } = await import('@/services/usuarios')
  const { crearProyectosEnMemoria } = await import('@/services/proyectos')
  servicio.actual = crearUsuariosEnMemoria()
  servicio.proyectos = crearProyectosEnMemoria()
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

function errorHttp(status: number, data: { message: string; code?: string }) {
  return new AxiosError(data.message, String(status), undefined, undefined, {
    status,
    statusText: '',
    data,
    headers: {},
    config: { headers: new AxiosHeaders() },
  })
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

  it('muestra junto a cada campo los errores 422 del servidor, también los de proyecto_ids.*', async () => {
    const vista = await montar()
    vi.spyOn(servicio.actual!, 'crear').mockRejectedValue(
      errorDeValidacion({
        nombres: ['Los nombres no deben superar los 100 caracteres.'],
        apellidos: ['Los apellidos no deben superar los 100 caracteres.'],
        rol: ['El rol seleccionado no es válido.'],
        'proyecto_ids.0': ['El proyecto seleccionado no existe.'],
      }),
    )
    await abrirNuevo(vista)
    await llenar(vista, 'ana.torres@sicco.test')
    await guardar(vista)

    expect(vista.get('#nombres-error').text()).toBe('Los nombres no deben superar los 100 caracteres.')
    expect(vista.get('#apellidos-error').text()).toBe(
      'Los apellidos no deben superar los 100 caracteres.',
    )
    expect(vista.get('#rol-error').text()).toBe('El rol seleccionado no es válido.')
    expect(vista.get('#proyecto_ids-error').text()).toBe('El proyecto seleccionado no existe.')
    expect(toast.add).not.toHaveBeenCalled()
  })

  it('muestra junto al rol que no puede cambiar su propio rol', async () => {
    const vista = await montar()
    vi.spyOn(servicio.actual!, 'actualizar').mockRejectedValue(
      errorDeValidacion({ rol: ['No puede cambiar su propio rol.'] }),
    )
    await vista.get('button[aria-label="Editar Administrador Demo"]').trigger('click')
    await asentar()
    await guardar(vista)

    expect(vista.get('#rol-error').text()).toBe('No puede cambiar su propio rol.')
    expect(vista.findAll('small.text-red-600')).toHaveLength(1)
    expect(vista.find('form').exists()).toBe(true)
    expect(toast.add).not.toHaveBeenCalled()
  })

  it('avisa con el mensaje del servidor si el error 422 no es de un campo del formulario', async () => {
    const vista = await montar()
    vi.spyOn(servicio.actual!, 'actualizar').mockRejectedValue(
      errorDeValidacion({ activo: ['Debe quedar al menos un administrador activo.'] }),
    )
    await vista.get('button[aria-label="Editar Administrador Demo"]').trigger('click')
    await asentar()
    await guardar(vista)

    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({
        severity: 'error',
        summary: 'Debe quedar al menos un administrador activo.',
      }),
    )
    expect(vista.find('form').exists()).toBe(true)
  })

  it('al desactivarse a sí mismo muestra el mensaje del servidor y no cambia el estado', async () => {
    const vista = await montar()
    vi.spyOn(servicio.actual!, 'cambiarEstado').mockRejectedValue(
      errorDeValidacion({ activo: ['No puede desactivar su propio usuario.'] }),
    )
    await vista.get('button[aria-label="Desactivar Administrador Demo"]').trigger('click')
    await asentar()

    expect(toast.add).toHaveBeenCalledExactlyOnceWith(
      expect.objectContaining({ severity: 'error', summary: 'No puede desactivar su propio usuario.' }),
    )
    expect(filas(vista)[0]?.[3]).toBe('Activo')
  })

  it('si no se pudo enviar el correo, muestra el aviso del servidor y conserva el formulario', async () => {
    const mensaje =
      'No se pudo enviar la contraseña temporal al correo. El usuario no se registró; inténtelo de nuevo.'
    const vista = await montar()
    vi.spyOn(servicio.actual!, 'crear').mockRejectedValue(
      errorHttp(503, { message: mensaje, code: 'correo_no_enviado' }),
    )
    await abrirNuevo(vista)
    await llenar(vista, 'ana.torres@sicco.test')
    await guardar(vista)

    expect(toast.add).toHaveBeenCalledExactlyOnceWith(
      expect.objectContaining({ severity: 'error', summary: mensaje }),
    )
    expect(vista.find('form').exists()).toBe(true)
    expect((vista.get('#nombres').element as HTMLInputElement).value).toBe('Ana')
    expect((vista.get('#email').element as HTMLInputElement).value).toBe('ana.torres@sicco.test')
    expect(vista.get('form').text()).toContain('contraseña temporal al correo')
    expect(filas(vista)).toHaveLength(4)
  })

  it('no muestra el detalle de otros errores 5xx del servidor', async () => {
    const vista = await montar()
    vi.spyOn(servicio.actual!, 'crear').mockRejectedValue(
      errorHttp(500, { message: 'SQLSTATE[HY000] Connection refused' }),
    )
    await abrirNuevo(vista)
    await llenar(vista, 'ana.torres@sicco.test')
    await guardar(vista)

    expect(toast.add).toHaveBeenCalledExactlyOnceWith(
      expect.objectContaining({ severity: 'error', summary: 'Ocurrió un error inesperado.' }),
    )
  })
})
