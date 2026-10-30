import type { RolClave } from './auth'

export interface UsuarioDatos {
  nombres: string
  apellidos: string
  email: string
  rol: RolClave
  proyecto_ids: number[]
}

export interface UsuarioFila extends UsuarioDatos {
  id: number
  activo: boolean
}

export interface ProyectoOpcion {
  id: number
  nombre: string
}

export const etiquetasRol: Record<RolClave, string> = {
  administrador: 'Administrador',
  almacenero: 'Almacenero',
  ingeniero_produccion: 'Ingeniero de producción',
  oficina_tecnica: 'Oficina técnica',
  gerencia: 'Gerencia',
}
