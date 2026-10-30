import { describe, expect, it } from 'vitest'
import { crearUsuariosEnMemoria } from '@/services/usuarios'

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
