export type CondicionPago = 'contado' | '30' | '60' | '90'

export const CONDICIONES_PAGO: { value: CondicionPago; label: string }[] = [
  { value: 'contado', label: 'Contado' },
  { value: '30', label: 'Crédito a 30 días' },
  { value: '60', label: 'Crédito a 60 días' },
  { value: '90', label: 'Crédito a 90 días' },
]

/** Los datos de contacto y condiciones solo llegan a Oficina técnica y al administrador. */
export interface Proveedor {
  id: number
  ruc: string
  razon_social: string
  activo?: boolean
  contacto_nombre?: string | null
  contacto_telefono?: string | null
  contacto_correo?: string | null
  correo_facturacion?: string | null
  condicion_pago?: CondicionPago | null
  direccion_fiscal?: string | null
  planta_despacho?: string | null
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
