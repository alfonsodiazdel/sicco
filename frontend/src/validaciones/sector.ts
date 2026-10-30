import { z } from 'zod'
import type { SectorFila } from '@/types/niveles'
import { requerido, texto } from '@/validaciones/usuario'

const numero = () => z.number({ required_error: requerido, invalid_type_error: requerido })

// El disponible depende de las partidas y los sectores cargados en la vista, por eso llega como función.
export const esquemaSector = (disponible: (presupuestoId: number) => number) =>
  z
    .object({
      nombre: texto(40),
      presupuesto_id: numero(),
      volumen_presup_m3: numero().positive('Ingrese un valor mayor a cero'),
    })
    .refine((d) => d.volumen_presup_m3 <= disponible(d.presupuesto_id), {
      message: 'El volumen excede lo presupuestado para el elemento',
      path: ['volumen_presup_m3'],
    })

export function valoresDeSector(sector: SectorFila) {
  return {
    nombre: sector.nombre,
    presupuesto_id: sector.presupuesto_id,
    volumen_presup_m3: sector.volumen_presup_m3,
  }
}
