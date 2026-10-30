export const estadosProyecto = ['Activo', 'Finalizado'] as const

export type EstadoProyecto = (typeof estadosProyecto)[number]

export interface ProyectoDatos {
  codigo: string
  nombre: string
  direccion: string
  /** Texto ISO `YYYY-MM-DD`, como lo recibe la API. */
  fecha_inicio: string
  fecha_fin: string
  ingeniero_id: number
  /** Solo se envía al editar; un proyecto nuevo siempre nace Activo. */
  estado?: EstadoProyecto
}

export interface ProyectoFila extends ProyectoDatos {
  id: number
  estado: EstadoProyecto
}
