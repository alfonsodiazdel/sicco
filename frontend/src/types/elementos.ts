/** Elemento estructural del catálogo (`ELEMENTO_ESTRUCTURAL`): lo eligen las partidas. */
export interface ElementoEstructural {
  id: number
  nombre: string
}

/** Fila del mantenedor: el elemento con su estado, si alguna partida lo usa y sus grupos. */
export interface ElementoFila extends ElementoEstructural {
  activo: boolean
  en_uso: boolean
  /** Ids de los grupos en los que se puede elegir (`grupo_elemento`). */
  grupos: number[]
}

export interface ElementoDatos {
  nombre: string
  activo?: boolean
  /** Sin el campo (p. ej. al activar o desactivar), el backend deja los grupos como están. */
  grupos?: number[]
}
