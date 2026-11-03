import { z } from 'zod'
import { requerido } from '@/validaciones/usuario'

const centesimos = (valor: number) => Math.round(valor * 100)

/** Fila del formulario: el InputNumber y el Select devuelven null mientras están vacíos. */
export interface FilaAsignacion {
  sector_id: number | null
  volumen_m3: number | null
}

/**
 * CU09 pasos 3–6. Los errores llevan la misma clave que los del backend (`asignaciones.0.volumen_m3`)
 * para mostrar en el mismo lugar los de una y otra validación.
 */
export function esquemaAsignacion(volumenGuia: number) {
  return z
    .array(
      z.object({
        sector_id: z.number({ required_error: requerido, invalid_type_error: requerido }),
        // El InputNumber ya no deja escribir más de dos decimales.
        volumen_m3: z
          .number({ required_error: requerido, invalid_type_error: requerido })
          .gt(0, 'Ingrese un valor mayor a cero'),
      }),
    )
    .min(1, 'Agregue al menos un sector')
    .superRefine((filas, ctx) => {
      const vistos = new Set<number>()
      filas.forEach((f, i) => {
        if (vistos.has(f.sector_id)) {
          ctx.addIssue({
            code: 'custom',
            path: [i, 'sector_id'],
            message: 'El sector ya está en la asignación',
          })
        }
        vistos.add(f.sector_id)
      })
      const suma = filas.reduce((total, f) => total + centesimos(f.volumen_m3), 0)
      if (suma > centesimos(volumenGuia)) {
        ctx.addIssue({
          code: 'custom',
          path: [],
          message: 'El volumen asignado supera el de la guía',
        })
      }
    })
}

/** Valida las filas y devuelve los errores con la clave del backend; vacío si son válidas. */
export function validarAsignacion(filas: FilaAsignacion[], volumenGuia: number) {
  const resultado = esquemaAsignacion(volumenGuia).safeParse(filas)
  if (resultado.success) return {}
  const errores: Record<string, string> = {}
  for (const issue of resultado.error.issues) {
    const clave = ['asignaciones', ...issue.path].join('.')
    errores[clave] ??= issue.message
  }
  return errores
}

/** Volumen asignado en las filas, en m³ con dos decimales. */
export const sumaAsignada = (filas: FilaAsignacion[]) =>
  filas.reduce((total, f) => total + centesimos(f.volumen_m3 ?? 0), 0) / 100
