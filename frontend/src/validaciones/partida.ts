import { z } from 'zod'
import { elementosEstructurales, resistenciasFc } from '@/catalogos/partidas'
import type { PartidaFila } from '@/types/partidas'
import { requerido } from '@/validaciones/usuario'

const mayorACero = 'Ingrese un valor mayor a cero'

// InputNumber devuelve null al quedar vacío; también cuenta como vacío.
const cantidad = () => z.number({ required_error: requerido, invalid_type_error: requerido })

const lista = <T extends readonly [string, ...string[]]>(opciones: T) =>
  z.enum(opciones, { errorMap: () => ({ message: requerido }) })

export const esquemaPartida = z.object({
  elemento: lista(elementosEstructurales),
  resistencia_fc: lista(resistenciasFc),
  metrado_m3: cantidad().positive(mayorACero),
  factor_desperdicio: cantidad().min(0, 'Ingrese un valor mayor o igual a cero'),
  precio_unitario: cantidad().positive(mayorACero),
})

export function valoresDePartida(partida: PartidaFila) {
  return {
    elemento: partida.elemento,
    resistencia_fc: partida.resistencia_fc,
    metrado_m3: partida.metrado_m3,
    factor_desperdicio: partida.factor_desperdicio,
    precio_unitario: partida.precio_unitario,
  }
}
