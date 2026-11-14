export interface PartidaDatos {
  /** Id del grupo del catálogo (`GRUPO_PARTIDA`): ordena y subtotaliza el presupuesto. */
  grupo_id: number
  /** Ítem del expediente técnico, p. ej. 03.02.01.01. */
  item: string | null
  descripcion: string
  /** Lo usan los sectores (CU05) para repartir el volumen. */
  /** Nombre de un elemento del catálogo, guardado como texto. */
  elemento: string
  /** Id del catálogo de resistencias (`RESISTENCIA`). */
  resistencia_id: number
  metrado_m3: number
  /** En porcentaje: 5 es 5 %. Se muestra como factor (1.050). */
  factor_desperdicio: number
  /** Soles por m³. */
  precio_unitario: number
}

export interface PartidaFila extends PartidaDatos {
  id: number
  proyecto_id: number
  /** El nombre de la resistencia, para mostrarlo: «f'c 280 kg/cm²». */
  resistencia_fc: string
}
