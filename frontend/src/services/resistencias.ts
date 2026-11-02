import { http } from '@/api/http'
import type { Resistencia } from '@/types/resistencias'

export interface ResistenciasServicio {
  /** Las activas, de menor a mayor f'c. */
  listar(): Promise<Resistencia[]>
}

export const resistenciasEjemplo: Resistencia[] = [175, 210, 280, 350].map((fc, i) => ({
  id: i + 1,
  fc_kg_cm2: fc,
  nombre: `f'c ${fc} kg/cm²`,
}))

/** El nombre de la resistencia de ejemplo con ese id, para los servicios en memoria. */
export const nombreResistencia = (id: number) =>
  resistenciasEjemplo.find((r) => r.id === id)?.nombre ?? ''

export function crearResistenciasEnMemoria(): ResistenciasServicio {
  return {
    async listar() {
      return resistenciasEjemplo.map((r) => ({ ...r }))
    },
  }
}

export function crearResistenciasApi(): ResistenciasServicio {
  return {
    async listar() {
      return (await http.get<Resistencia[]>('/resistencias')).data
    },
  }
}

export const resistenciasServicio: ResistenciasServicio = crearResistenciasApi()
