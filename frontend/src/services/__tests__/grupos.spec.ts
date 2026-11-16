import { describe, expect, it } from 'vitest'
import { erroresDeValidacion, mensajeDeError } from '@/api/http'
import { crearGruposEnMemoria } from '@/services/grupos'

const conPartidasEn = (...ids: number[]) => crearGruposEnMemoria(async (id) => ids.includes(id))

describe('grupos en memoria', () => {
  it('lista los grupos por orden', async () => {
    const grupos = conPartidasEn()
    await grupos.crear({
      nombre: 'Obras exteriores',
      partida_control: '2.02.02',
      orden: 0,
      elementos: [],
    })

    const nombres = (await grupos.listar()).map((g) => g.nombre)
    expect(nombres.slice(0, 3)).toEqual(['Obras exteriores', 'Muros anclados', 'Cimentación'])
  })

  it('no repite el nombre de un grupo, sin importar mayúsculas', async () => {
    const grupos = conPartidasEn()

    const error = await grupos
      .crear({ nombre: 'CISTERNA', partida_control: '2.02.02', orden: 9, elementos: [] })
      .catch((e: unknown) => e)
    expect(erroresDeValidacion(error)).toEqual({ nombre: 'El grupo ya existe' })
    // Al editar, el propio nombre no cuenta como repetido.
    await expect(
      grupos.actualizar(3, {
        nombre: 'Cisterna',
        partida_control: '2.02.02',
        orden: 8,
        elementos: [],
      }),
    ).resolves.toMatchObject({
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

  it('cada grupo trae su partida de control', async () => {
    const grupos = conPartidasEn()

    expect(new Set((await grupos.listar()).map((g) => g.partida_control))).toEqual(
      new Set(['2.02.02']),
    )
  })
})
