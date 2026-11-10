/** CU15: montos de una fila del reporte EAT, sin IGV y con 2 decimales. */
export interface MontosEat {
  presupuesto_m3: number
  presupuesto_soles: number
  consumido_m3: number
  consumido_soles: number
  saldo_m3: number
  saldo_soles: number
  eat_m3: number
  eat_soles: number
  /** EAT S/ mayor que el presupuesto S/: se resalta en rojo. */
  sobrecosto: boolean
}

export interface FilaEat extends MontosEat {
  partida_id: number
  item: string | null
  /** «elemento · resistencia», como en el control de desperdicio. */
  etiqueta: string
}

export interface GrupoEat {
  grupo_id: number
  nombre: string
  filas: FilaEat[]
  subtotal: MontosEat
}

/**
 * Montos que no son de ningún elemento: guías sin sector y cargos de factura.
 * Las guías sin sector se muestran aparte y no suman al total.
 */
export interface FilaAparteEat extends Omit<MontosEat, 'consumido_m3' | 'saldo_m3' | 'eat_m3'> {
  tipo: 'guias_sin_sector' | 'cargo'
  concepto: string
  /** Solo en las guías sin sector: pide asignarles su sector. */
  aviso?: string
  /** Nulos en los cargos, que no tienen volumen. */
  consumido_m3: number | null
  saldo_m3: number | null
  eat_m3: number | null
}

export interface ReporteEat {
  proyecto: { id: number; codigo: string; nombre: string }
  fecha_corte: string
  grupos: GrupoEat[]
  filas_aparte: FilaAparteEat[]
  total: MontosEat
}

/** Sin consumos a la fecha de corte el reporte es nulo y llega el mensaje del flujo alternativo. */
export interface ResultadoEat {
  reporte: ReporteEat | null
  mensaje: string | null
}

export type FormatoEat = 'excel' | 'pdf'
