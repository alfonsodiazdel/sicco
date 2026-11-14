/** Elemento estructural del catálogo (`ELEMENTO_ESTRUCTURAL`): lo eligen las partidas. */
export interface ElementoEstructural {
  id: number
  nombre: string
}

/** Fila del mantenedor: el elemento con su estado y si alguna partida lo usa. */
export interface ElementoFila extends ElementoEstructural {
  activo: boolean
  en_uso: boolean
}

export interface ElementoDatos {
  nombre: string
  activo?: boolean
}
