import type { Alerta } from '@/types/alertas'

/** Desperdicio acumulado al cierre del rango (CU16 S1.5), con el límite también en %. */
export interface DesperdicioDashboard {
  /** Sectores medidos: culminados hasta el cierre y abiertos que ya pasaron lo presupuestado. */
  sectores: number
  presupuestado_m3: number
  vaciado_m3: number
  diferencia_m3: number
  /** Nulo mientras no haya sectores medidos. */
  desperdicio_pct: number | null
  limite_m3: number
  /** Factor de desperdicio ponderado de los sectores medidos. */
  limite_pct: number | null
  supera_limite: boolean
}

export interface ProyectoDashboard {
  id: number
  codigo: string
  nombre: string
  guias: number
  recibido_m3: number
  vaciado_m3: number
  /** Recibido en el rango valorizado sin IGV, como el corte de mes. */
  costo_soles: number
  desperdicio: DesperdicioDashboard
}

export interface Dashboard {
  periodo: {
    /** AAAA-MM. */
    desde: string
    hasta: string
    fecha_inicio: string
    fecha_cierre: string
    /** El rango llega al mes en curso: las cifras aún pueden cambiar. */
    parcial: boolean
  }
  indicadores: {
    proyectos_activos: number
    vaciado_m3: number
    costo_soles: number
    /** Ponderado por volumen entre los proyectos. */
    desperdicio_pct: number | null
    limite_pct: number | null
    supera_limite: boolean
  }
  proyectos: ProyectoDashboard[]
  alertas: { pendientes: number; recientes: Alerta[] }
  /** «No hay proyectos activos» (flujo alternativo 1). */
  mensaje: string | null
}
