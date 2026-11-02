export interface GrupoDatos {
  nombre: string
  /** Posición del grupo en el presupuesto y en los reportes. */
  orden: number
}

export interface GrupoFila extends GrupoDatos {
  id: number
}
