// La tesis muestra ambos campos como lista sin dar los valores: son los de la maqueta, por confirmar.
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

export const resistenciasFc = [
  "f'c 175 kg/cm²",
  "f'c 210 kg/cm²",
  "f'c 280 kg/cm²",
  "f'c 350 kg/cm²",
] as const

/** Solo el número de la resistencia, como lo muestra el presupuesto: «f'c 280 kg/cm²» es 280. */
export const fcEnKg = (resistencia: string) => Number(/\d+/.exec(resistencia)?.[0] ?? 0)
