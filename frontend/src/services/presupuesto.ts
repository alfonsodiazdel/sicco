import type { PartidaDatos, PartidaFila } from '@/types/partidas'
import type { SectorFila } from '@/types/niveles'

type Importe = Pick<PartidaDatos, 'metrado_m3' | 'factor_desperdicio' | 'precio_unitario'>

// EPSILON corrige productos como 0.5 × 2.01 = 1.00499…, que deben quedar en 1.01.
const redondear = (valor: number) => Math.round((valor + Number.EPSILON) * 100) / 100

export const totalPartida = (p: Importe) =>
  redondear(p.metrado_m3 * (1 + p.factor_desperdicio / 100) * p.precio_unitario)

export const totalPresupuesto = (partidas: Importe[]) =>
  redondear(partidas.reduce((suma, p) => suma + totalPartida(p), 0))

type Asignado = Pick<SectorFila, 'id' | 'presupuesto_id' | 'volumen_presup_m3'>

// Metrado aún no repartido entre sectores de cualquier nivel. Sin desperdicio: CU14 lo aplica
// después sobre el volumen presupuestado del sector. `sinSector` excluye al sector que se edita.
export const volumenDisponible = (
  partida: Pick<PartidaFila, 'id' | 'metrado_m3'>,
  sectores: Asignado[],
  sinSector?: number,
) =>
  redondear(
    sectores
      .filter((s) => s.presupuesto_id === partida.id && s.id !== sinSector)
      .reduce((resto, s) => resto - s.volumen_presup_m3, partida.metrado_m3),
  )

export const decimales = (valor: number) =>
  valor.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export const soles = (valor: number) => `S/ ${decimales(valor)}`
