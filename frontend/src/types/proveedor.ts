export interface Proveedor {
  id: number
  ruc: string
  razon_social: string
}

export type ProveedorForm = Omit<Proveedor, 'id'>

/** Respuesta paginada estándar de Laravel (data, links, meta). */
export interface Paginado<T> {
  data: T[]
  meta: {
    current_page: number
    per_page: number
    total: number
  }
}
