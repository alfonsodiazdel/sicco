import type { PartidaDatos, PartidaFila } from '@/types/partidas'
import type { SectorFila } from '@/types/niveles'

type Importe = Pick<PartidaDatos, 'metrado_m3' | 'factor_desperdicio' | 'precio_unitario'>

// Los importes y volúmenes llegan con dos decimales. Se opera en centésimos enteros porque el
// producto en coma flotante cae justo debajo de los medios céntimos (0.31 × 420.5 = 130.35499…)
// y ningún ajuste con EPSILON lo corrige en todos los rangos.
const centesimos = (valor: number) => Math.round(valor * 100)

const centimosDePartida = (p: Importe) => {
  // metrado × (100 % + desperdicio) × precio, los tres en centésimos: escala 1e6 sobre el céntimo.
  const producto =
    BigInt(centesimos(p.metrado_m3)) *
    BigInt(10000 + centesimos(p.factor_desperdicio)) *
    BigInt(centesimos(p.precio_unitario))
  return Number((producto + 500000n) / 1000000n)
}

export const totalPartida = (p: Importe) => centimosDePartida(p) / 100

export const totalPresupuesto = (partidas: Importe[]) =>
  partidas.reduce((suma, p) => suma + centimosDePartida(p), 0) / 100

type Asignado = Pick<SectorFila, 'id' | 'presupuesto_id' | 'volumen_presup_m3'>

// Metrado aún no repartido entre sectores de cualquier nivel. Sin desperdicio: CU14 lo aplica
// después sobre el volumen presupuestado del sector. `sinSector` excluye al sector que se edita.
export const volumenDisponible = (
  partida: Pick<PartidaFila, 'id' | 'metrado_m3'>,
  sectores: Asignado[],
  sinSector?: number,
) =>
  sectores
    .filter((s) => s.presupuesto_id === partida.id && s.id !== sinSector)
    .reduce((resto, s) => resto - centesimos(s.volumen_presup_m3), centesimos(partida.metrado_m3)) /
  100

export const decimales = (valor: number) =>
  valor.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export const soles = (valor: number) => `S/ ${decimales(valor)}`
