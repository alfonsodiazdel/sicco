// La tesis muestra el campo como lista sin dar los valores: son los de la maqueta, por confirmar.
// Las resistencias f'c vienen del catálogo del backend (services/resistencias).
export const elementosEstructurales = [
  'Zapata',
  'Cimiento corrido',
  'Columna',
  'Placa',
  'Viga',
  'Losa aligerada',
  'Losa maciza',
  'Escalera',
] as const

/** Solo el número de la resistencia, como lo muestra el presupuesto: «f'c 280 kg/cm²» es 280. */
export const fcEnKg = (resistencia: string) => Number(/\d+/.exec(resistencia)?.[0] ?? 0)
