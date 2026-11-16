import { z } from 'zod'
import type { GrupoFila } from '@/types/grupos'
import { requerido, texto } from '@/validaciones/usuario'

/** La que usa hoy la empresa para el concreto premezclado. */
export const partidaControlPorDefecto = '2.02.02'

export const esquemaGrupo = z.object({
  nombre: texto(60),
  partida_control: texto(20).regex(/^\d+(\.\d+)*$/, 'Use solo números separados por puntos'),
  // InputNumber devuelve null al quedar vacío; también cuenta como vacío.
  orden: z
    .number({ required_error: requerido, invalid_type_error: requerido })
    .int('Ingrese un número entero')
    .positive('Ingrese un valor mayor a cero'),
  // Puede quedar vacío: el grupo existe, pero sus partidas no tendrán elemento que elegir.
  elementos: z.array(z.number()).default([]),
})

export function valoresDeGrupo(grupo: GrupoFila) {
  return {
    nombre: grupo.nombre,
    partida_control: grupo.partida_control,
    orden: grupo.orden,
    elementos: [...grupo.elementos],
  }
}
