import type { elementosEstructurales, resistenciasFc } from '@/catalogos/partidas'

export type ElementoEstructural = (typeof elementosEstructurales)[number]

export type ResistenciaFc = (typeof resistenciasFc)[number]

export interface PartidaDatos {
  /** Id del grupo del catálogo (`GRUPO_PARTIDA`): ordena y subtotaliza el presupuesto. */
  grupo_id: number
  /** Ítem del expediente técnico, p. ej. 03.02.01.01. */
  item: string | null
  descripcion: string
  /** Lo usan los sectores (CU05) para repartir el volumen. */
  elemento: ElementoEstructural
  resistencia_fc: ResistenciaFc
  metrado_m3: number
  /** En porcentaje: 5 es 5 %. Se muestra como factor (1.050). */
  factor_desperdicio: number
  /** Soles por m³. */
  precio_unitario: number
}

export interface PartidaFila extends PartidaDatos {
  id: number
  proyecto_id: number
}
