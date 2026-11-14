import type { Paginado, Proveedor, ProveedorForm } from '@/types/proveedor'
import { http } from './http'

export interface FiltrosProveedores {
  buscar?: string
  /** 1 = solo activos (para los combos de guías y facturas). */
  activo?: 1
  page?: number
  per_page?: number
}

export const proveedoresApi = {
  async listar(filtros: FiltrosProveedores = {}) {
    const { data } = await http.get<Paginado<Proveedor>>('/proveedores', { params: filtros })
    return data
  },

  async crear(payload: ProveedorForm) {
    const { data } = await http.post<Proveedor>('/proveedores', payload)
    return data
  },

  async actualizar(id: number, payload: ProveedorForm) {
    const { data } = await http.put<Proveedor>(`/proveedores/${id}`, payload)
    return data
  },

  async eliminar(id: number) {
    await http.delete(`/proveedores/${id}`)
  },
}
