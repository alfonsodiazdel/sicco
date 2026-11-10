/**
 * CU15: montos de una fila del reporte EAT, sin IGV y con 2 decimales.
 * EAT = real de los sectores culminados al corte + proyectado de lo que falta (con desperdicio y al
 * precio vigente). Variación = presupuesto meta − EAT.
 */
export interface MontosEat {
  /** Presupuesto meta: metrado × (1 + desperdicio), como en el CU04. */
  presupuesto_m3: number
  presupuesto_soles: number
  /** Todo lo vaciado hasta el corte, en sectores culminados y abiertos. */
  consumido_m3: number
  consumido_soles: number
  /** Presupuesto meta − consumido. */
  saldo_soles: number
  /** Lo vaciado en los sectores culminados al corte. */
  real_m3: number
  real_soles: number
  /** Lo que falta: neto aún no culminado × (1 + desperdicio), al precio vigente. */
  proyectado_m3: number
  proyectado_soles: number
  eat_m3: number
  eat_soles: number
  variacion_soles: number
  /** Variación negativa (EAT sobre el presupuesto meta): se resalta en rojo. */
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

type ConNulos<T> = { [K in keyof T]: T[K] | null }

/**
 * Montos que no son de ningún elemento: guías sin sector y cargos de factura.
 * Las guías sin sector se muestran aparte, sin EAT, y no suman al total. Los cargos suman solo lo
 * facturado hasta el corte: no se proyectan y no tienen volumen.
 */
export interface FilaAparteEat extends ConNulos<Omit<MontosEat, 'sobrecosto'>> {
  tipo: 'guias_sin_sector' | 'cargo'
  concepto: string
  /** Solo en las guías sin sector: pide asignarles su sector. */
  aviso?: string
  sobrecosto: boolean
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

/**
 * CU16: reporte de corte a fin de mes. Recibido, vaciado y facturado son del mes; lo pendiente de
 * facturar es acumulado al cierre. Montos sin IGV.
 */
export interface ResumenCorteMes {
  guias_recibidas: number
  recibido_m3: number
  /** Lo asignado a sectores de las guías del mes. */
  vaciado_m3: number
  facturas: number
  /** Solo el concreto; el monto incluye también los cargos (bombeo, aditivos…). */
  facturado_m3: number
  facturado_soles: number
  /** Guías hasta el cierre sin factura fechada hasta ese día. */
  guias_pendientes: number
  pendiente_m3: number
  pendiente_soles: number
}

/** Desperdicio acumulado: sectores culminados hasta el cierre y abiertos que ya pasaron lo presupuestado. */
export interface DesperdicioCorteMes {
  sectores: number
  presupuestado_m3: number
  vaciado_m3: number
  diferencia_m3: number
  desperdicio_pct: number | null
  limite_m3: number
  supera_limite: boolean
}

export interface GuiaCorteMes {
  id: number
  numero: string
  proveedor: string
  fecha: string
  resistencia: string
  volumen_m3: number
  vaciado_m3: number
  /** Número de la factura, solo si estaba fechada hasta el cierre. */
  factura: string | null
  factura_fecha: string | null
  estado: 'facturada' | 'pendiente'
  precio_unitario: number | null
  importe_soles: number
  /** false: guía de un mes anterior que seguía pendiente al cierre. */
  del_mes: boolean
}

export interface ReporteCorteMes {
  proyecto: { id: number; codigo: string; nombre: string }
  anio: number
  mes: number
  fecha_inicio: string
  fecha_cierre: string
  /** Mes en curso: el corte aún no está cerrado. */
  parcial: boolean
  resumen: ResumenCorteMes
  desperdicio: DesperdicioCorteMes
  guias: GuiaCorteMes[]
}

/** Sin guías ni facturas en el mes el reporte es nulo y llega el mensaje del flujo alternativo. */
export interface ResultadoCorteMes {
  reporte: ReporteCorteMes | null
  mensaje: string | null
}
