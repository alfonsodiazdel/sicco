import type { Proveedor } from '@/types/proveedor'
import type { Resistencia } from '@/types/resistencias'

/** Campos que lee el OCR (CU07 paso 3). */
export type CampoLectura =
  | 'numero'
  | 'fecha'
  | 'proveedor'
  | 'ruc'
  | 'codigo_producto'
  | 'descripcion_producto'
  /** f'c en kg/cm², ya resuelto con el catálogo de productos de la concretera. */
  | 'resistencia'
  | 'volumen_m3'
  | 'placa'

export interface CampoLeido {
  valor: string | number | null
  /** De 0 a 1. */
  confianza: number
  /** Baja confianza o no identificado: el almacenero debe revisarlo (CU07 paso 4). */
  revisar: boolean
}

export interface LecturaGuia {
  campos: Record<CampoLectura, CampoLeido>
  /** El proveedor registrado con el RUC leído, si existe. */
  proveedor_id: number | null
  /** La resistencia del catálogo que corresponde a lo leído, si existe. */
  resistencia_id: number | null
}

export interface RespuestaLectura {
  /** Referencia de la foto ya subida, para registrarla sin volver a enviarla. */
  foto_temporal: string
  /** null cuando el OCR no respondió o no reconoció la guía (CU08). */
  lectura: LecturaGuia | null
  message?: string
}

export interface GuiaDatos {
  numero: string
  /** AAAA-MM-DD */
  fecha: string
  resistencia_id: number
  volumen_m3: number
  placa?: string | null
  /** El producto de la concretera; un código nuevo se registra con la resistencia elegida. */
  codigo_producto?: string | null
  descripcion_producto?: string | null
}

/** El proveedor es uno registrado o el que leyó el OCR y aún no está registrado. */
export type ProveedorGuia =
  { proveedor_id: number } | { proveedor_ruc: string; proveedor_razon_social: string }

/** La foto como archivo o la referencia que devolvió la lectura. */
export type FotoGuia = { foto: Blob } | { foto_temporal: string }

export type RegistroGuia = GuiaDatos & ProveedorGuia & FotoGuia & { proyecto_id: number }

export interface GuiaFila extends Omit<GuiaDatos, 'resistencia_id' | 'descripcion_producto'> {
  id: number
  proyecto_id: number
  proveedor: Proveedor
  resistencia: Resistencia
  codigo_producto: string | null
  placa: string | null
  estado_asignacion: 'sin_sector' | 'parcial' | 'asignada'
  origen: 'ocr' | 'manual'
}
