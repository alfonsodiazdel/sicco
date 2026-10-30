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
    })
  })
})
