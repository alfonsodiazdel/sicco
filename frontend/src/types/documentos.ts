import type { GuiaFila } from './guias'
import type { Proveedor } from './proveedor'

export type TipoDocumento = 'guias' | 'facturas'

export interface FiltroDocumentos {
  desde: string | null
  hasta: string | null
  proveedor_id: number | null
  /** Solo aplica a las guías. */
  estado?: GuiaFila['estado_asignacion'] | null
}

/** Con `ids`, la API descarga solo esos documentos e ignora los filtros. */
export type PedidoZip = FiltroDocumentos | { ids: number[] }

interface DocumentoBase {
  id: number
  numero: string
  fecha: string
  proveedor: Proveedor
  volumen_m3: number
}

export interface GuiaDocumento extends DocumentoBase {
  estado_asignacion: GuiaFila['estado_asignacion']
  tiene_foto: boolean
}

export interface FacturaDocumento extends DocumentoBase {
  total: number
  tiene_archivo: boolean
}

export interface ArchivoZip {
  blob: Blob
  nombre: string
}
