/** CU14 paso 5: pendiente hasta que alguien la marca como revisada. */
export type EstadoAlerta = 'pendiente' | 'revisada'

/** Alerta de consumo: un sector pasó lo presupuestado más su desperdicio (CU14). */
export interface Alerta {
  id: number
  /** ISO 8601 con zona. */
  fecha: string
  estado: EstadoAlerta
  /** m³ por encima del límite del sector. */
  exceso_m3: number
  proyecto: { id: number; codigo: string; nombre: string }
  sector: {
    id: number
    nombre: string
    nivel: string
    elemento: string
    presupuestado_m3: number
    factor_desperdicio: number
    /** Presupuestado × (1 + factor). */
    limite_m3: number
    /** Vaciado de hoy: puede haber cambiado desde la alerta. */
    vaciado_m3: number
  }
  /** Nulo mientras el correo no sale (CU14 flujo alternativo 1: se reintenta). */
  correo_enviado_at: string | null
  revisada_at: string | null
  revisada_por: string | null
}

export interface FiltroAlertas {
  estado?: EstadoAlerta
}
