/** CU13: nivel de detalle de la consulta. */
export type DetalleDesperdicio = 'elemento' | 'nivel' | 'sector'

export interface FilaDesperdicio {
  /** Único en la respuesta: los nombres se repiten entre niveles o partidas. */
  id: string
  etiqueta: string
  nivel: string | null
  elemento: string | null
  /** Ítem de la partida (opcional en el presupuesto); nulo al agrupar por nivel. */
  item: string | null
  /** Descripción de la partida; nula al agrupar por nivel. */
  descripcion: string | null
  presupuestado_m3: number
  vaciado_m3: number
  diferencia_m3: number
  /** Nulo mientras la fila no tenga vaciados. */
  desperdicio_pct: number | null
  /** Presupuestado × (1 + factor de desperdicio de la partida). */
  limite_m3: number
  supera_limite: boolean
}

export interface ControlDesperdicio {
  detalle: DetalleDesperdicio
  hay_vaciados: boolean
  filas: FilaDesperdicio[]
}
