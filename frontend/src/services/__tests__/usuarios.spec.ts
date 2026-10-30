import { afterEach, describe, expect, it, vi } from 'vitest'
import { proyectosServicio } from '@/services/proyectos'
import { crearUsuariosEnMemoria } from '@/services/usuarios'

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
