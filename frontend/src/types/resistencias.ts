/** Resistencia f'c del catálogo (`RESISTENCIA`), compartida por el presupuesto y las guías. */
export interface Resistencia {
  id: number
  /** En kg/cm²: 280. */
  fc_kg_cm2: number
  /** Como se muestra: «f'c 280 kg/cm²». */
  nombre: string
}
