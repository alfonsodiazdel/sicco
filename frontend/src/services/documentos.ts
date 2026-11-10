import { http, esErrorApi, type ErrorApi } from '@/api/http'
import type {
  ArchivoZip,
  FacturaDocumento,
  FiltroDocumentos,
  GuiaDocumento,
  PedidoZip,
  TipoDocumento,
} from '@/types/documentos'

export interface DocumentosServicio {
  listarGuias(proyectoId: number, filtro: FiltroDocumentos): Promise<GuiaDocumento[]>
  listarFacturas(proyectoId: number, filtro: FiltroDocumentos): Promise<FacturaDocumento[]>
  /** Las fotos o archivos en un ZIP, con el nombre que propone la API. */
  descargarZip(proyectoId: number, tipo: TipoDocumento, pedido: PedidoZip): Promise<ArchivoZip>
}

// Axios manda los arreglos como `ids[]=1&ids[]=2`, que es lo que lee Laravel.
const parametros = (pedido: PedidoZip) =>
  Object.fromEntries(Object.entries(pedido).filter(([, v]) => v !== null && v !== undefined))

function nombreDeArchivo(cabecera: unknown, porDefecto: string) {
  if (typeof cabecera !== 'string') return porDefecto
  const codificado = /filename\*=utf-8''([^;]+)/i.exec(cabecera)?.[1]
  if (codificado) return decodeURIComponent(codificado.trim())
  return /filename="?([^";]+)"?/i.exec(cabecera)?.[1]?.trim() || porDefecto
}

// El interceptor de http ya lee como JSON el cuerpo Blob del error. En un 422 se muestra el primer
// error del campo en lugar del resumen de Laravel («… (and 1 more error)»).
function conPrimerError(error: unknown) {
  if (!esErrorApi(error) || !error.response?.data?.errors) return error
  const cuerpo: ErrorApi = error.response.data
  const primero = Object.values(cuerpo.errors ?? {})[0]?.[0]
  if (primero) error.response.data = { ...cuerpo, message: primero }
  return error
}

export function crearDocumentosApi(): DocumentosServicio {
  const listar = async <T>(proyectoId: number, tipo: TipoDocumento, filtro: FiltroDocumentos) => {
    const url = `/proyectos/${proyectoId}/documentos/${tipo}`
    return (await http.get<{ data: T[] }>(url, { params: parametros(filtro) })).data.data
  }
  return {
    listarGuias: (proyectoId, filtro) => listar<GuiaDocumento>(proyectoId, 'guias', filtro),
    listarFacturas: (proyectoId, filtro) =>
      listar<FacturaDocumento>(proyectoId, 'facturas', filtro),
    async descargarZip(proyectoId, tipo, pedido) {
      const url = `/proyectos/${proyectoId}/documentos/${tipo}/zip`
      try {
        const respuesta = await http.get<Blob>(url, {
          params: parametros(pedido),
          responseType: 'blob',
        })
        const nombre = nombreDeArchivo(respuesta.headers['content-disposition'], `${tipo}.zip`)
        return { blob: respuesta.data, nombre }
      } catch (error) {
        throw conPrimerError(error)
      }
    },
  }
}

export const documentosServicio: DocumentosServicio = crearDocumentosApi()
