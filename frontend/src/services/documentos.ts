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

// Con responseType blob, el cuerpo de un error también llega como Blob: se lee como JSON para que
// mensajeDeError muestre el mensaje de la API.
async function leerErrorBlob(error: unknown) {
  if (!esErrorApi(error) || !(error.response?.data instanceof Blob)) return error
  try {
    const cuerpo = JSON.parse(await error.response.data.text()) as ErrorApi
    const primero = Object.values(cuerpo.errors ?? {})[0]?.[0]
    error.response.data = { ...cuerpo, message: primero ?? cuerpo.message }
  } catch {
    // Un cuerpo que no es JSON se queda como vino; la vista usa el mensaje por defecto.
  }
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
        throw await leerErrorBlob(error)
      }
    },
  }
}

export const documentosServicio: DocumentosServicio = crearDocumentosApi()
