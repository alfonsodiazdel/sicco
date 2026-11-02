import type { GrupoFila } from '@/types/grupos'
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

type Volumen = Pick<PartidaDatos, 'metrado_m3' | 'factor_desperdicio'>

// metrado × (100 % + desperdicio), en centésimos de m³.
const centesimosPresupuestados = (p: Volumen) =>
  Math.round((centesimos(p.metrado_m3) * (10000 + centesimos(p.factor_desperdicio))) / 10000)

/** Metrado con el desperdicio incluido (m³ presupuestados), a dos decimales. */
export const volumenPresupuestado = (p: Volumen) => centesimosPresupuestados(p) / 100

/** El desperdicio se guarda en %; el presupuesto lo muestra como factor: 5 % es 1.050. */
export const factorDesperdicio = (porcentaje: number) =>
  (1 + porcentaje / 100).toLocaleString('es-PE', {
    minimumFractionDigits: 3,
    maximumFractionDigits: 4,
  })

export interface GrupoDePresupuesto {
  grupo: Pick<GrupoFila, 'id' | 'nombre' | 'partida_control'>
  partidas: PartidaFila[]
  metrado_m3: number
  volumen_presup_m3: number
  total: number
}

const sinGrupo = {
  id: 0,
  nombre: 'Sin grupo',
  partida_control: '',
  orden: Number.POSITIVE_INFINITY,
}

/**
 * Partidas reunidas por grupo, en el orden del catálogo, con sus subtotales. Los grupos sin
 * partidas no aparecen; una partida con un grupo que ya no existe va al final, en «Sin grupo».
 */
export function agruparPartidas(
  partidas: PartidaFila[],
  grupos: GrupoFila[],
): GrupoDePresupuesto[] {
  const porId = new Map(grupos.map((g) => [g.id, g]))
  const reunidos = new Map<number, { grupo: GrupoFila; partidas: PartidaFila[] }>()
  for (const partida of partidas) {
    const grupo = porId.get(partida.grupo_id) ?? sinGrupo
    const reunido = reunidos.get(grupo.id) ?? { grupo, partidas: [] }
    reunido.partidas.push(partida)
    reunidos.set(grupo.id, reunido)
  }
  return [...reunidos.values()]
    .sort((a, b) => a.grupo.orden - b.grupo.orden || a.grupo.id - b.grupo.id)
    .map(({ grupo, partidas }) => ({
      grupo: { id: grupo.id, nombre: grupo.nombre, partida_control: grupo.partida_control },
      partidas,
      metrado_m3: partidas.reduce((suma, p) => suma + centesimos(p.metrado_m3), 0) / 100,
      volumen_presup_m3: partidas.reduce((suma, p) => suma + centesimosPresupuestados(p), 0) / 100,
      total: totalPresupuesto(partidas),
    }))
}

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
