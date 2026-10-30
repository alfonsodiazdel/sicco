import { afterEach, describe, expect, it, vi } from 'vitest'
import { http } from '@/api/http'
import { proyectosServicio } from '@/services/proyectos'
import { crearUsuariosApi, crearUsuariosEnMemoria, usuariosServicio } from '@/services/usuarios'
import type { UsuarioDatos, UsuarioFila } from '@/types/usuarios'

describe('usuariosServicio.proyectos', () => {
  afterEach(() => vi.restoreAllMocks())

  it('ofrece las opciones de proyectos que da el servicio de proyectos (id y nombre)', async () => {
    const opciones = vi
      .spyOn(proyectosServicio, 'opciones')
      .mockResolvedValue([{ id: 4, nombre: 'Edificio Miraflores' }])

    expect(await crearUsuariosEnMemoria().proyectos()).toStrictEqual([
      { id: 4, nombre: 'Edificio Miraflores' },
    ])
    expect(opciones).toHaveBeenCalledOnce()
  })
})

describe('usuariosServicio.ingenieros', () => {
  it('devuelve solo id y nombre de los ingenieros de producción activos', async () => {
    const servicio = crearUsuariosEnMemoria()
    const base = { proyecto_ids: [1], apellidos: 'Vega' }
    const inactivo = await servicio.crear({
      ...base,
      nombres: 'Pedro',
      email: 'pedro@sicco.test',
      rol: 'ingeniero_produccion',
    })
    await servicio.cambiarEstado(inactivo.id, false)
    await servicio.crear({ ...base, nombres: 'Marta', email: 'marta@sicco.test', rol: 'gerencia' })
    const activo = await servicio.crear({
      ...base,
      nombres: 'Rosa',
      email: 'rosa@sicco.test',
      rol: 'ingeniero_produccion',
    })

    expect(await servicio.ingenieros()).toStrictEqual([
      { id: 3, nombre: 'Lucía Ramos' },
      { id: activo.id, nombre: 'Rosa Vega' },
    ])
  })
})

describe('usuarios por la API', () => {
  const cuerpo: UsuarioDatos = {
    nombres: 'Ana',
    apellidos: 'Torres',
    email: 'ana.torres@sicco.test',
    rol: 'ingeniero_produccion',
    proyecto_ids: [1, 2],
  }
  const fila: UsuarioFila = { ...cuerpo, id: 5, activo: true }
  const respuesta = <T>(data: T) => Promise.resolve({ data })

  afterEach(() => vi.restoreAllMocks())

  it('lista con GET /usuarios y es el servicio que usa la pantalla', async () => {
    const get = vi.spyOn(http, 'get').mockReturnValue(respuesta([fila]))

    expect(await crearUsuariosApi().listar()).toStrictEqual([fila])
    expect(await usuariosServicio.listar()).toStrictEqual([fila])

    expect(get.mock.calls).toStrictEqual([['/usuarios'], ['/usuarios']])
  })

  it('registra con POST, actualiza con PUT y cambia el estado con PATCH', async () => {
    const post = vi.spyOn(http, 'post').mockReturnValue(respuesta(fila))
    const put = vi.spyOn(http, 'put').mockReturnValue(respuesta({ ...fila, nombres: 'Ana María' }))
    const patch = vi.spyOn(http, 'patch').mockReturnValue(respuesta({ ...fila, activo: false }))
    const api = crearUsuariosApi()

    expect(await api.crear(cuerpo)).toStrictEqual(fila)
    expect((await api.actualizar(5, cuerpo)).nombres).toBe('Ana María')
    expect((await api.cambiarEstado(5, false)).activo).toBe(false)

    expect(post.mock.calls).toStrictEqual([['/usuarios', cuerpo]])
    expect(put.mock.calls).toStrictEqual([['/usuarios/5', cuerpo]])
    expect(patch.mock.calls).toStrictEqual([['/usuarios/5/estado', { activo: false }]])
  })

  it('toma las opciones de proyectos y los ingenieros del servicio de proyectos', async () => {
    const opciones = vi
      .spyOn(proyectosServicio, 'opciones')
      .mockResolvedValue([{ id: 4, nombre: 'Edificio Miraflores' }])
    const ingenieros = vi
      .spyOn(proyectosServicio, 'ingenieros')
      .mockResolvedValue([{ id: 3, nombre: 'Lucía Ramos' }])
    const api = crearUsuariosApi()

    expect(await api.proyectos()).toStrictEqual([{ id: 4, nombre: 'Edificio Miraflores' }])
    expect(await api.ingenieros(9)).toStrictEqual([{ id: 3, nombre: 'Lucía Ramos' }])
    await api.ingenieros()

    expect(opciones).toHaveBeenCalledOnce()
    expect(ingenieros.mock.calls).toStrictEqual([[9], [undefined]])
  })

  it('deja pasar el 422 del servidor para que la vista muestre el mensaje en el campo', async () => {
    const { errorDeValidacion } = await import('@/services/errores')
    const error = errorDeValidacion({ email: ['El correo ya está registrado'] })
    vi.spyOn(http, 'post').mockRejectedValue(error)

    await expect(crearUsuariosApi().crear(cuerpo)).rejects.toBe(error)
  })
})
