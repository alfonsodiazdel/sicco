/** Resistencia f'c del catálogo (`RESISTENCIA`), compartida por el presupuesto y las guías. */
export interface Resistencia {
  id: number
  /** En kg/cm²: 280. */
  fc_kg_cm2: number
  /** Como se muestra: «f'c 280 kg/cm²». */
  nombre: string
}

/** Fila del mantenedor: la resistencia con su estado y si algún registro la usa. */
export interface ResistenciaFila extends Resistencia {
  activo: boolean
  en_uso: boolean
}

/** Lo que se envía al crear o editar; el nombre sale del f'c: «f'c 280 kg/cm²». */
export interface ResistenciaDatos {
  fc_kg_cm2: number
  nombre: string
  activo?: boolean
}
