import type { PartidaDatos } from '@/types/partidas'

type Importe = Pick<PartidaDatos, 'metrado_m3' | 'factor_desperdicio' | 'precio_unitario'>

// EPSILON corrige productos como 0.5 × 2.01 = 1.00499…, que deben quedar en 1.01.
const redondear = (valor: number) => Math.round((valor + Number.EPSILON) * 100) / 100

export const totalPartida = (p: Importe) =>
  redondear(p.metrado_m3 * (1 + p.factor_desperdicio / 100) * p.precio_unitario)

export const totalPresupuesto = (partidas: Importe[]) =>
  redondear(partidas.reduce((suma, p) => suma + totalPartida(p), 0))

export const decimales = (valor: number) =>
  valor.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export const soles = (valor: number) => `S/ ${decimales(valor)}`
