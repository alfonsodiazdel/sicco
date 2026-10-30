import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { http } from '@/api/http'
import { crearProyectosApi, crearProyectosEnMemoria } from '@/services/proyectos'
import type { ProyectoDatos, ProyectoFila } from '@/types/proyectos'
import { useAuthStore } from '@/stores/auth'
import type { Usuario } from '@/types/auth'

const sesion = (email: string) =>
  ({ email, rol: { clave: 'ingeniero_produccion' } }) as unknown as Usuario

describe('proyectos en memoria', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('asignados devuelve solo los proyectos del usuario de la sesión, por su correo', async () => {
    useAuthStore().usuario = sesion('Ingeniero_Produccion@SICCO.test')

    const asignados = await crearProyectosEnMemoria().asignados()

    expect(asignados.map((p) => p.id)).toEqual([1, 2])
  })

  it('sin sesión o con un correo sin asignaciones no devuelve ninguno', async () => {
    expect(await crearProyectosEnMemoria().asignados()).toEqual([])

    useAuthStore().usuario = sesion('gerencia@sicco.test')
    expect(await crearProyectosEnMemoria().asignados()).toEqual([])
  })
})

describe('proyectos por la API', () => {
  const fila: ProyectoFila = {
    id: 7,
    codigo: 'PRY-007',
    nombre: 'Edificio Miraflores',
    direccion: 'Av. Larco 345, Miraflores',
    fecha_inicio: '2026-11-02',
    fecha_fin: '2027-06-30',
    ingeniero_id: 3,
    estado: 'Activo',
  }
  const datos: ProyectoDatos = { ...fila, estado: 'Finalizado' }
  const respuesta = <T>(data: T) => Promise.resolve({ data })

  afterEach(() => vi.restoreAllMocks())

  it('lista, trae los asignados, las opciones y los ingenieros de las rutas de /proyectos', async () => {
    const get = vi
      .spyOn(http, 'get')
      .mockImplementation((url) =>
        respuesta(
          url.endsWith('ingenieros') || url.endsWith('opciones')
            ? [{ id: 3, nombre: 'Lucía Ramos' }]
            : [fila],
        ),
      )
    const api = crearProyectosApi()

    expect(await api.listar()).toStrictEqual([fila])
    expect(await api.asignados()).toStrictEqual([fila])
    expect(await api.opciones()).toStrictEqual([{ id: 3, nombre: 'Lucía Ramos' }])
    expect(await api.ingenieros()).toStrictEqual([{ id: 3, nombre: 'Lucía Ramos' }])
    await api.ingenieros(9)

    expect(get.mock.calls).toStrictEqual([
      ['/proyectos'],
      ['/proyectos/asignados'],
      ['/proyectos/opciones'],
      ['/proyectos/ingenieros', { params: { conservar: undefined } }],
      ['/proyectos/ingenieros', { params: { conservar: 9 } }],
    ])
  })

  it('registra con POST y actualiza con PUT, y devuelve la fila que responde el servidor', async () => {
    const post = vi.spyOn(http, 'post').mockReturnValue(respuesta(fila))
    const put = vi.spyOn(http, 'put').mockReturnValue(respuesta({ ...fila, estado: 'Finalizado' }))
    const api = crearProyectosApi()

    expect(await api.crear(datos)).toStrictEqual(fila)
    expect((await api.actualizar(7, datos)).estado).toBe('Finalizado')

    expect(post).toHaveBeenCalledWith('/proyectos', datos)
    expect(put).toHaveBeenCalledWith('/proyectos/7', datos)
  })

  it('deja pasar el 422 del servidor para que la vista muestre el mensaje en el campo', async () => {
    const { errorDeValidacion } = await import('@/services/errores')
    const error = errorDeValidacion({ codigo: ['El código de proyecto ya existe'] })
    vi.spyOn(http, 'post').mockRejectedValue(error)

    await expect(crearProyectosApi().crear(datos)).rejects.toBe(error)
  })
})
