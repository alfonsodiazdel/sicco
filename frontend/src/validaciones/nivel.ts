import { z } from 'zod'
import type { NivelFila } from '@/types/niveles'
import { requerido, texto } from '@/validaciones/usuario'

export const esquemaNivel = z.object({
  nombre: texto(40),
  // InputNumber devuelve null al quedar vacío; también cuenta como vacío.
  orden: z
    .number({ required_error: requerido, invalid_type_error: requerido })
    .int('Ingrese un número entero')
    .positive('Ingrese un valor mayor a cero'),
})

export function valoresDeNivel(nivel: NivelFila) {
  return { nombre: nivel.nombre, orden: nivel.orden }
}
