import type { GuiaFila } from '@/types/guias'
import type { Proveedor } from '@/types/proveedor'
import type { Resistencia } from '@/types/resistencias'

/** Línea de la factura: el concreto de una resistencia, en m³ y a un precio sin IGV. */
export interface LineaFacturaDatos {
  resistencia_id: number
  volumen_m3: number
  precio_unitario: number
}

/** Lo que cobra la factura y no es concreto (bombeo, aditivos…), sin IGV. */
export interface CargoFacturaDatos {
  descripcion: string
  importe: number
}

/** El archivo como tal o la referencia que devolvió la lectura con OCR. */
export type ArchivoFactura = { archivo: File } | { archivo_temporal: string }

/** CU11 pasos 1–4 y CU12: lo que se envía al registrar la factura. */
export type RegistroFactura = ArchivoFactura & {
  proyecto_id: number
  proveedor_id: number
  numero: string
  /** AAAA-MM-DD */
  fecha: string
  lineas: LineaFacturaDatos[]
  cargos?: CargoFacturaDatos[]
  igv: number
  /** Las guías que cobra (CU12). */
  guia_ids: number[]
  /** CU11 flujo alternativo 1: registrar aunque el volumen no coincida con las guías. */
  confirmado?: boolean
}

export interface LineaFactura extends Omit<LineaFacturaDatos, 'resistencia_id'> {
  id: number
  resistencia: Resistencia
  importe: number
}

export interface CargoFactura extends CargoFacturaDatos {
  id: number
}

/** Guía vinculada, con su importe según el precio de su resistencia en la factura (CU12 paso 3). */
export interface GuiaFacturada extends GuiaFila {
  importe: number | null
}

export interface FacturaFila {
  id: number
  proyecto_id: number
  proveedor: Proveedor
  numero: string
  fecha: string
  volumen_m3: number
  /** Sin IGV, con los otros cargos: el costo real del proyecto. */
  subtotal: number
  igv: number
  total: number
  /** Se registró aunque el volumen no coincidía con las guías. */
  diferencia_confirmada: boolean
  origen: 'ocr' | 'manual'
  guias_count?: number
  /** Volumen de las guías vinculadas; con `volumen_pendiente_m3` da lo que falta por vincular. */
  volumen_guias_m3?: number
  volumen_pendiente_m3?: number
}

/** Lo que falta vincular de una resistencia: el facturado menos el de sus guías. */
export interface PendienteResistencia {
  resistencia: Resistencia
  facturado_m3: number
  en_guias_m3: number
  faltan_m3: number
}

export interface FacturaDetalle extends FacturaFila {
  lineas: LineaFactura[]
  cargos: CargoFactura[]
  guias: GuiaFacturada[]
  /** Solo las resistencias a las que aún les faltan guías. */
  pendiente_por_resistencia?: PendienteResistencia[]
}

export interface RespuestaVincularGuias {
  data: FacturaFila
  message: string
}

export interface RespuestaRegistroFactura {
  data: FacturaFila
  message: string
}

/** Datos de cabecera que lee el OCR. */
export type CampoLecturaFactura =
  'numero' | 'fecha' | 'proveedor' | 'ruc' | 'subtotal' | 'igv' | 'total'

export interface CampoLeidoFactura {
  valor: string | number | null
  /** De 0 a 1. */
  confianza: number
  /** Baja confianza o no identificado: se debe revisar. */
  revisar: boolean
}

/** Lo leído de la factura, listo para el formulario. */
export interface LecturaFactura {
  campos: Record<CampoLecturaFactura, CampoLeidoFactura>
  /** El proveedor registrado con el RUC leído, si existe. */
  proveedor_id: number | null
  /** Concreto, con la resistencia del catálogo si se pudo resolver. */
  lineas: {
    resistencia_id: number | null
    volumen_m3: number | null
    precio_unitario: number | null
    descripcion: string | null
    codigo_producto: string | null
    revisar: boolean
  }[]
  cargos: { descripcion: string | null; importe: number | null; revisar: boolean }[]
  guias: {
    /** Guías citadas en la factura y pendientes: se proponen marcadas. */
    sugeridas: number[]
    /** Las citadas que no se pueden proponer, y por qué. */
    avisos: string[]
  }
}

export interface RespuestaLecturaFactura {
  /** Referencia del archivo ya subido, para registrarlo sin volver a enviarlo. */
  archivo_temporal: string
  /** null cuando el OCR no respondió o no reconoció la factura: se ingresa a mano. */
  lectura: LecturaFactura | null
  message?: string
}
