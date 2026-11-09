import { z } from 'zod'
import { requerido, texto } from '@/validaciones/usuario'
import { hoy } from '@/validaciones/guia'

/** Tasa del IGV: se propone sobre el subtotal y se puede corregir con lo que diga la factura. */
export const TASA_IGV = 0.18

/** CU11 paso 1: imagen o PDF de hasta 10 MB, como lo valida el backend. */
export const TIPOS_ARCHIVO = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp']
export const MAXIMO_ARCHIVO = 10 * 1024 * 1024
export const ARCHIVO_INVALIDO = 'Adjunte una imagen (JPG, PNG o WebP) o un PDF de hasta 10 MB'

/** Fila del detalle: el Select y los InputNumber devuelven null mientras están vacíos. */
export interface FilaLinea {
  resistencia_id: number | null
  volumen_m3: number | null
  precio_unitario: number | null
}

/** Otro cargo de la factura (bombeo, aditivos…): suma al costo, no al volumen. */
export interface FilaCargo {
  descripcion: string
  importe: number | null
}

export interface ValoresFactura {
  numero: string
  fecha: string
  proveedor_id: number | null
  /** El archivo elegido, o null si ya se subió al leerlo con OCR (`archivo_temporal`). */
  archivo: File | null
  archivo_temporal?: string | null
  lineas: FilaLinea[]
  cargos?: FilaCargo[]
  igv: number | null
  guia_ids: number[]
}

const numero = () => z.number({ required_error: requerido, invalid_type_error: requerido })

/**
 * CU11 pasos 1–2 y CU12. Los errores llevan la misma clave que los del backend
 * (`lineas.0.volumen_m3`) para mostrar en el mismo lugar los de una y otra validación.
 */
export const esquemaFactura = z.object({
  numero: texto(20),
  fecha: texto(10).refine((f) => f <= hoy(), 'La fecha no puede ser posterior a hoy'),
  proveedor_id: numero(),
  archivo: z
    .instanceof(File)
    .refine((a) => TIPOS_ARCHIVO.includes(a.type) && a.size <= MAXIMO_ARCHIVO, ARCHIVO_INVALIDO)
    .nullable(),
  archivo_temporal: z.string().nullish(),
  cargos: z
    .array(
      z.object({
        descripcion: texto(200),
        importe: numero()
          .gt(0, 'Ingrese un valor mayor a cero')
          .max(9999999.99, 'El valor es demasiado grande'),
      }),
    )
    .max(20, 'Debe tener como máximo 20 cargos')
    .optional(),
  lineas: z
    .array(
      z.object({
        resistencia_id: numero(),
        volumen_m3: numero()
          .gt(0, 'Ingrese un valor mayor a cero')
          .max(9999.99, 'El valor es demasiado grande'),
        precio_unitario: numero()
          .gt(0, 'Ingrese un valor mayor a cero')
          .max(99999.99, 'El valor es demasiado grande'),
      }),
    )
    .min(1, 'Agregue al menos una resistencia')
    .superRefine((filas, ctx) => {
      const vistas = new Set<number>()
      filas.forEach((f, i) => {
        if (vistas.has(f.resistencia_id)) {
          ctx.addIssue({
            code: 'custom',
            path: [i, 'resistencia_id'],
            message: 'La resistencia ya está en la factura',
          })
        }
        vistas.add(f.resistencia_id)
      })
    }),
  igv: numero().min(0, 'El IGV no puede ser negativo'),
  guia_ids: z.array(z.number()).min(1, 'Vincule al menos una guía'),
})

/** Valida el formulario y devuelve los errores con la clave del backend; vacío si es válido. */
export function validarFactura(valores: ValoresFactura) {
  const resultado = esquemaFactura.safeParse(valores)
  const errores: Record<string, string> = {}
  for (const issue of resultado.success ? [] : resultado.error.issues) {
    errores[issue.path.join('.')] ??= issue.message
  }
  // El archivo se adjunta o ya se subió al leerlo con OCR.
  if (!valores.archivo && !valores.archivo_temporal) errores.archivo ??= requerido
  return errores
}

// Los importes se calculan en céntimos enteros, igual que el backend (App\Support\Importe): en
// coma flotante 8.33 × 291.35 cae justo debajo del medio céntimo.
const centesimos = (valor: number) => Math.round(valor * 100)

/** Volumen × precio unitario, en soles redondeados al céntimo. */
export const importe = (volumen: number, precio: number) =>
  Math.floor((centesimos(volumen) * centesimos(precio) + 50) / 100) / 100

/** Suma de las líneas completas, en soles. */
export const subtotalLineas = (filas: FilaLinea[]) =>
  filas.reduce(
    (suma, f) =>
      f.volumen_m3 && f.precio_unitario
        ? suma + centesimos(importe(f.volumen_m3, f.precio_unitario))
        : suma,
    0,
  ) / 100

/** Subtotal de la factura: el concreto más los otros cargos completos. */
export const subtotalFactura = (filas: FilaLinea[], cargos: FilaCargo[] = []) =>
  sumar(subtotalLineas(filas), ...cargos.map((c) => c.importe ?? 0))

export const igvSugerido = (subtotal: number) => Math.round(centesimos(subtotal) * TASA_IGV) / 100

export const sumar = (...valores: number[]) =>
  valores.reduce((suma, v) => suma + centesimos(v), 0) / 100

/** Volumen de una resistencia en la factura frente al de las guías vinculadas (CU11 paso 5). */
export interface ComparacionVolumen {
  resistencia_id: number
  facturado: number
  guias: number
}

/**
 * CU11 paso 5: el volumen de cada resistencia, en la factura y en las guías. Se compara por
 * resistencia, como el backend: 16 m³ de f'c 280 no cobran 16 m³ de f'c 350.
 */
export function compararVolumenes(
  filas: FilaLinea[],
  guias: { resistencia: { id: number }; volumen_m3: number }[],
): ComparacionVolumen[] {
  const porResistencia = new Map<number, { facturado: number; guias: number }>()
  const de = (id: number) => {
    const fila = porResistencia.get(id) ?? { facturado: 0, guias: 0 }
    porResistencia.set(id, fila)
    return fila
  }
  for (const f of filas) {
    if (f.resistencia_id !== null) de(f.resistencia_id).facturado += centesimos(f.volumen_m3 ?? 0)
  }
  for (const g of guias) de(g.resistencia.id).guias += centesimos(g.volumen_m3)
  return [...porResistencia.entries()].map(([resistencia_id, v]) => ({
    resistencia_id,
    facturado: v.facturado / 100,
    guias: v.guias / 100,
  }))
}

export const volumenesCoinciden = (comparacion: ComparacionVolumen[]) =>
  comparacion.every((c) => centesimos(c.facturado) === centesimos(c.guias))

/**
 * CU12 cuando la factura no cita sus guías: por cada resistencia, las guías pendientes de la más
 * antigua a la más reciente hasta completar el volumen facturado. Se proponen solo las resistencias
 * que cuadran exacto; si una no cuadra, sus guías se dejan para que el usuario las elija.
 */
export function sugerirGuias<
  G extends { id: number; fecha: string; resistencia: { id: number }; volumen_m3: number },
>(pendientes: G[], filas: FilaLinea[]): number[] {
  const ordenadas = [...pendientes].sort((a, b) => a.fecha.localeCompare(b.fecha) || a.id - b.id)
  const sugeridas: number[] = []
  for (const fila of filas) {
    if (fila.resistencia_id === null || !fila.volumen_m3) continue
    const objetivo = centesimos(fila.volumen_m3)
    let suma = 0
    const elegidas: number[] = []
    for (const g of ordenadas.filter((g) => g.resistencia.id === fila.resistencia_id)) {
      if (suma >= objetivo) break
      suma += centesimos(g.volumen_m3)
      elegidas.push(g.id)
    }
    if (suma === objetivo) sugeridas.push(...elegidas)
  }
  return ordenadas.filter((g) => sugeridas.includes(g.id)).map((g) => g.id)
}
