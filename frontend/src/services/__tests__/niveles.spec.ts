import { describe, expect, it } from 'vitest'
import { erroresDeValidacion } from '@/api/http'
import { crearPartidasEnMemoria } from '@/services/partidas'
import { crearNivelesEnMemoria } from '@/services/niveles'

describe('niveles en memoria', () => {
  it('rechaza como la API un sector que excede lo presupuestado para el elemento', async () => {
    const niveles = crearNivelesEnMemoria(crearPartidasEnMemoria())
    // Columna del proyecto 1: 180.5 − 60 − 58.5 = 62 disponibles.
    const intento = niveles.crearSector(1, {
      nombre: 'Columnas eje 1',
      presupuesto_id: 2,
      volumen_presup_m3: 62.01,
    })

    const error = await intento.catch((e: unknown) => e)
    expect(erroresDeValidacion(error)).toEqual({
      volumen_presup_m3: 'El volumen excede lo presupuestado para el elemento',
    })
    expect(await niveles.listarSectores(1)).toHaveLength(5)
  })

  it('un sector nuevo nace sin volumen vaciado', async () => {
    const niveles = crearNivelesEnMemoria(crearPartidasEnMemoria())
    const sector = await niveles.crearSector(1, {
      nombre: 'Columnas eje 1',
      presupuesto_id: 2,
      volumen_presup_m3: 62,
    })

    expect(sector).toEqual({
      id: 6,
      nivel_id: 1,
      presupuesto_id: 2,
      nombre: 'Columnas eje 1',
      volumen_presup_m3: 62,
      volumen_vaciado_m3: 0,
      fecha_culminacion: null,
    })
  })

  it('elimina como la API: nivel sin sectores, sector abierto y sin vaciado', async () => {
    const niveles = crearNivelesEnMemoria(crearPartidasEnMemoria())
    const rechazo = async (p: Promise<unknown>) => erroresDeValidacion(await p.catch((e) => e))

    expect(await rechazo(niveles.eliminarNivel(1))).toEqual({
      nivel: 'El nivel tiene sectores; elimínelos para eliminarlo',
    })
    expect(await rechazo(niveles.eliminarSector(4))).toEqual({
      sector: 'El sector está culminado; reábralo para eliminarlo',
    })
    expect(await rechazo(niveles.eliminarSector(1))).toEqual({
      sector: 'El sector tiene guías asignadas; reasígnelas a otro sector para eliminarlo',
    })

    await niveles.eliminarSector(5)
    await niveles.eliminarNivel(3)
    expect((await niveles.listarSectores(1)).map((s) => s.id)).toEqual([1, 2, 3, 4])
    expect((await niveles.listarNiveles(1)).map((n) => n.id)).toEqual([1, 2])
  })
})
