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

export function crearPartidasEnMemoria(): PartidasServicio {
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
      return { ...Object.assign(partida, datos) }
    },
  }
}

// Hasta que exista el endpoint; la implementación con la API solo reemplaza esta línea.
export const partidasServicio: PartidasServicio = crearPartidasEnMemoria()
