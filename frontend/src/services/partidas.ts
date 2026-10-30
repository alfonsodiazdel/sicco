import { errorDeValidacion } from '@/services/errores'
import type { NivelesServicio } from '@/services/niveles'
import { decimales, volumenDisponible } from '@/services/presupuesto'
import type { PartidaDatos, PartidaFila } from '@/types/partidas'

export interface PartidasServicio {
  listar(proyectoId: number): Promise<PartidaFila[]>
  crear(proyectoId: number, datos: PartidaDatos): Promise<PartidaFila>
  actualizar(id: number, datos: PartidaDatos): Promise<PartidaFila>
}

const partidasEjemplo: PartidaFila[] = [
  {
    id: 1,
    proyecto_id: 1,
    elemento: 'Zapata',
    resistencia_fc: "f'c 210 kg/cm²",
    metrado_m3: 320,
    factor_desperdicio: 5,
    precio_unitario: 385,
  },
  {
    id: 2,
    proyecto_id: 1,
    elemento: 'Columna',
    resistencia_fc: "f'c 280 kg/cm²",
    metrado_m3: 180.5,
    factor_desperdicio: 3,
    precio_unitario: 420,
  },
  {
    id: 3,
    proyecto_id: 1,
    elemento: 'Losa aligerada',
    resistencia_fc: "f'c 210 kg/cm²",
    metrado_m3: 245,
    factor_desperdicio: 5,
    precio_unitario: 390,
  },
  {
    id: 4,
    proyecto_id: 2,
    elemento: 'Placa',
    resistencia_fc: "f'c 350 kg/cm²",
    metrado_m3: 3850,
    factor_desperdicio: 3,
    precio_unitario: 465,
  },
]

// Import diferido: niveles depende de partidas para validar el volumen de cada sector.
const nivelesPorDefecto = async () => (await import('@/services/niveles')).nivelesServicio

export function crearPartidasEnMemoria(
  niveles: () => Promise<Pick<NivelesServicio, 'listarSectores'>> = nivelesPorDefecto,
): PartidasServicio {
  const partidas = partidasEjemplo.map((p) => ({ ...p }))
  let siguienteId = partidas.length + 1

  return {
    async listar(proyectoId) {
      return partidas.filter((p) => p.proyecto_id === proyectoId).map((p) => ({ ...p }))
    },
    async crear(proyectoId, datos) {
      const partida: PartidaFila = { id: siguienteId++, proyecto_id: proyectoId, ...datos }
      partidas.push({ ...partida })
      return partida
    },
    async actualizar(id, datos) {
      const partida = partidas.find((p) => p.id === id)
      if (!partida) throw new Error('Partida no encontrada.')
      // El metrado no puede quedar debajo de lo ya repartido: el disponible de CU05 quedaría negativo.
      const sectores = await (await niveles()).listarSectores(partida.proyecto_id)
      const repartido = -volumenDisponible({ id, metrado_m3: 0 }, sectores)
      if (datos.metrado_m3 < repartido) {
        throw errorDeValidacion({
          metrado_m3: [
            `El metrado es menor al volumen ya repartido en sectores (${decimales(repartido)} m³)`,
          ],
        })
      }
      return { ...Object.assign(partida, datos) }
    },
  }
}

// Hasta que exista el endpoint; la implementación con la API solo reemplaza esta línea.
export const partidasServicio: PartidasServicio = crearPartidasEnMemoria()
