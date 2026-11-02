import { z } from 'zod'
import { requerido, texto } from '@/validaciones/usuario'

/** Fecha local de hoy como AAAA-MM-DD (toISOString daría la de UTC, que en Lima adelanta 5 h). */
export function hoy() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** Proveedor leído por el OCR que aún no está registrado: se registra con la guía. */
export const PROVEEDOR_NUEVO = -1

const numero = () => z.number({ required_error: requerido, invalid_type_error: requerido })

// CU08 paso 2: número, fecha, proveedor, resistencia y volumen son requeridos; la placa y el
// código del producto no.
export const esquemaGuia = z.object({
  numero: texto(20),
  fecha: texto(10).refine((f) => f <= hoy(), 'La fecha no puede ser posterior a hoy'),
  proveedor_id: numero(),
  resistencia_id: numero(),
  volumen_m3: numero()
    .positive('Ingrese un valor mayor a cero')
    .max(99.99, 'El valor es demasiado grande'),
  placa: z.string().trim().max(10, 'Debe tener como máximo 10 caracteres').nullish(),
  codigo_producto: z.string().trim().max(30, 'Debe tener como máximo 30 caracteres').nullish(),
})

export type ValoresGuia = z.infer<typeof esquemaGuia>
