export interface NivelDatos {
  nombre: string
  orden: number
}

export interface NivelFila extends NivelDatos {
  id: number
  proyecto_id: number
}

export interface SectorDatos {
  nombre: string
  /** Id de la partida (`PRESUPUESTO`); el elemento y el f'c se leen de ella. */
  presupuesto_id: number
  volumen_presup_m3: number
}

export interface SectorFila extends SectorDatos {
  id: number
  nivel_id: number
  /** Lo suma la asignación de guías (CU09); en CU05 solo se muestra. */
  volumen_vaciado_m3: number
  /**
   * `YYYY-MM-DD` si está culminado; null si está abierto. Culminado ya no recibe guías ni se edita,
   * y en el reporte EAT (CU15) su vaciado pasa a costo real.
   */
  fecha_culminacion: string | null
}
