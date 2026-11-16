export interface GrupoDatos {
  nombre: string
  /**
   * Código de control de costos de la empresa (2.02.02 es concreto premezclado). No viene de la
   * Norma Técnica de Metrados, que numera con códigos OE; por eso es editable.
   */
  partida_control: string
  /** Posición del grupo en el presupuesto y en los reportes. */
  orden: number
  /** Ids de los elementos estructurales que pueden elegir las partidas del grupo. */
  elementos: number[]
}

export interface GrupoFila extends GrupoDatos {
  id: number
}
