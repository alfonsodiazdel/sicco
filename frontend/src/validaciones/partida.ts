import { z } from 'zod'
import { elementosEstructurales } from '@/catalogos/partidas'
import type { PartidaFila } from '@/types/partidas'
import { requerido, texto } from '@/validaciones/usuario'

const mayorACero = 'Ingrese un valor mayor a cero'

// InputNumber devuelve null al quedar vacío; también cuenta como vacío.
const cantidad = () => z.number({ required_error: requerido, invalid_type_error: requerido })

const lista = <T extends readonly [string, ...string[]]>(opciones: T) =>
  z.enum(opciones, { errorMap: () => ({ message: requerido }) })

// PC e ítem son opcionales: vacíos se guardan como null.
const codigo = z
  .string()
  .trim()
  .max(20, 'Debe tener como máximo 20 caracteres')
  .regex(/^[\d.]*$/, 'Use solo números separados por puntos')
  .nullish()
  .transform((valor) => valor || null)

export const esquemaPartida = z.object({
  grupo_id: z.number({ required_error: requerido, invalid_type_error: requerido }),
  item: codigo,
  descripcion: texto(200),
  elemento: lista(elementosEstructurales),
  resistencia_id: z.number({ required_error: requerido, invalid_type_error: requerido }),
  metrado_m3: cantidad().positive(mayorACero),
  factor_desperdicio: cantidad().min(0, 'Ingrese un valor mayor o igual a cero'),
  precio_unitario: cantidad().positive(mayorACero),
})

export function valoresDePartida(partida: PartidaFila) {
  return {
    grupo_id: partida.grupo_id,
    item: partida.item,
    descripcion: partida.descripcion,
    elemento: partida.elemento,
    resistencia_id: partida.resistencia_id,
    metrado_m3: partida.metrado_m3,
    factor_desperdicio: partida.factor_desperdicio,
    precio_unitario: partida.precio_unitario,
  }
}
