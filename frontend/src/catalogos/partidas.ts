// Los elementos estructurales y las resistencias f'c vienen de sus catálogos del backend
// (services/elementos, services/resistencias).

/** Solo el número de la resistencia, como lo muestra el presupuesto: «f'c 280 kg/cm²» es 280. */
export const fcEnKg = (resistencia: string) => Number(/\d+/.exec(resistencia)?.[0] ?? 0)
