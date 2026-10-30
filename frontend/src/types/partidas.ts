import type { elementosEstructurales, resistenciasFc } from '@/catalogos/partidas'

export type ElementoEstructural = (typeof elementosEstructurales)[number]

export type ResistenciaFc = (typeof resistenciasFc)[number]

export interface PartidaDatos {
  elemento: ElementoEstructural
  resistencia_fc: ResistenciaFc
  metrado_m3: number
  /** En porcentaje: 5 es 5 %. */
  factor_desperdicio: number
  /** Soles por m³. */
  precio_unitario: number
}

export interface PartidaFila extends PartidaDatos {
  id: number
  proyecto_id: number
}
