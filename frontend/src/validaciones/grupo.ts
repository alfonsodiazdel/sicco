import { z } from 'zod'
import type { GrupoFila } from '@/types/grupos'
import { requerido, texto } from '@/validaciones/usuario'

export const esquemaGrupo = z.object({
  nombre: texto(60),
  // InputNumber devuelve null al quedar vacío; también cuenta como vacío.
  orden: z
    .number({ required_error: requerido, invalid_type_error: requerido })
    .int('Ingrese un número entero')
    .positive('Ingrese un valor mayor a cero'),
})

export function valoresDeGrupo(grupo: GrupoFila) {
  return { nombre: grupo.nombre, orden: grupo.orden }
}
