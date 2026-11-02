import { describe, expect, it } from 'vitest'
import { erroresDeValidacion, mensajeDeError } from '@/api/http'
import { crearGruposEnMemoria } from '@/services/grupos'

const conPartidasEn = (...ids: number[]) =>
  crearGruposEnMemoria(async () => ({ usanGrupo: async (id) => ids.includes(id) }))

describe('grupos en memoria', () => {
  it('lista los grupos por orden', async () => {
    const grupos = conPartidasEn()
    await grupos.crear({ nombre: 'Obras exteriores', orden: 0 })

    const nombres = (await grupos.listar()).map((g) => g.nombre)
    expect(nombres.slice(0, 3)).toEqual(['Obras exteriores', 'Muros anclados', 'Cimentación'])
  })

  it('no repite el nombre de un grupo, sin importar mayúsculas', async () => {
    const grupos = conPartidasEn()

    const error = await grupos.crear({ nombre: 'CISTERNA', orden: 9 }).catch((e: unknown) => e)
    expect(erroresDeValidacion(error)).toEqual({ nombre: 'El grupo ya existe' })
    // Al editar, el propio nombre no cuenta como repetido.
    await expect(grupos.actualizar(3, { nombre: 'Cisterna', orden: 8 })).resolves.toMatchObject({
      orden: 8,
    })
  })

  it('elimina un grupo sin partidas y rechaza uno que las tiene', async () => {
    const grupos = conPartidasEn(2)

    await grupos.eliminar(3)
    const error = await grupos.eliminar(2).catch((e: unknown) => e)

    expect(mensajeDeError(error)).toBe(
      'El grupo tiene partidas registradas; reasígnelas antes de eliminarlo.',
    )
    const ids = (await grupos.listar()).map((g) => g.id)
    expect(ids).toContain(2)
    expect(ids).not.toContain(3)
  })
})
