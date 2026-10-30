import { describe, expect, it } from 'vitest'
import { erroresDeValidacion } from '@/api/http'
import { crearPartidasEnMemoria } from '@/services/partidas'
import { crearNivelesEnMemoria, type NivelesServicio } from '@/services/niveles'
import { volumenDisponible } from '@/services/presupuesto'
import type { PartidaDatos } from '@/types/partidas'

const zapata = (metrado_m3: number): PartidaDatos => ({
  elemento: 'Zapata',
  resistencia_fc: "f'c 210 kg/cm²",
  metrado_m3,
  factor_desperdicio: 5,
  precio_unitario: 385,
})

function crearServicios() {
  const enlace: { niveles?: NivelesServicio } = {}
  const partidas = crearPartidasEnMemoria(async () => enlace.niveles!)
  const niveles = (enlace.niveles = crearNivelesEnMemoria(partidas))
  return { partidas, niveles }
}

describe('partidas en memoria', () => {
  // Zapata (partida 1, 320 m³) ya tiene los sectores 4 y 5: 40 + 36 = 76 m³.
  it('rechaza bajar el metrado por debajo de lo ya repartido en sectores', async () => {
    const { partidas, niveles } = crearServicios()

    const error = await partidas.actualizar(1, zapata(50)).catch((e: unknown) => e)

    expect(erroresDeValidacion(error)).toEqual({
      metrado_m3: 'El metrado es menor al volumen ya repartido en sectores (76.00 m³)',
    })
    const [partida] = (await partidas.listar(1)).filter((p) => p.id === 1)
    expect(partida!.metrado_m3).toBe(320)
    expect(volumenDisponible(partida!, await niveles.listarSectores(1))).toBe(244)
  })

  it('acepta bajar el metrado hasta exactamente lo repartido', async () => {
    const { partidas, niveles } = crearServicios()

    await expect(partidas.actualizar(1, zapata(76))).resolves.toMatchObject({ metrado_m3: 76 })
    const [partida] = (await partidas.listar(1)).filter((p) => p.id === 1)
    expect(volumenDisponible(partida!, await niveles.listarSectores(1))).toBe(0)
  })

  it('una partida sin sectores se puede editar libremente', async () => {
    const { partidas } = crearServicios()
    const nueva = await partidas.crear(1, zapata(10))

    await expect(partidas.actualizar(nueva.id, zapata(0.01))).resolves.toMatchObject({
      metrado_m3: 0.01,
    })
  })
})
