import { esErrorApi, http } from '@/api/http'
import type { FormatoEat, ReporteEat, ResultadoEat } from '@/types/reportes'

export interface ReportesServicio {
  /** CU15: reporte EAT del proyecto a la fecha de corte (`YYYY-MM-DD`). */
  eat(proyectoId: number, fechaCorte: string): Promise<ResultadoEat>
  descargarEat(proyectoId: number, fechaCorte: string, formato: FormatoEat): Promise<Blob>
}

interface RespuestaEat {
  data: ReporteEat | null
  mensaje?: string
}

// Con responseType blob, axios también entrega como Blob el cuerpo JSON de un error;
// se vuelve a leer para que mensajeDeError y erroresDeValidacion lo entiendan.
async function conCuerpoLegible(error: unknown): Promise<never> {
  const cuerpo: unknown = esErrorApi(error) ? error.response?.data : undefined
  if (esErrorApi(error) && error.response && cuerpo instanceof Blob) {
    try {
      error.response.data = JSON.parse(await cuerpo.text())
    } catch {
      // No era JSON: el error sigue como llegó.
    }
  }
  throw error
}

export function crearReportesApi(): ReportesServicio {
  return {
    async eat(proyectoId, fechaCorte) {
      const url = `/proyectos/${proyectoId}/reportes/eat`
      const { data } = await http.get<RespuestaEat>(url, { params: { fecha_corte: fechaCorte } })
      return { reporte: data.data, mensaje: data.mensaje ?? null }
    },
    async descargarEat(proyectoId, fechaCorte, formato) {
      const url = `/proyectos/${proyectoId}/reportes/eat/${formato}`
      const params = { fecha_corte: fechaCorte }
      try {
        return (await http.get<Blob>(url, { params, responseType: 'blob' })).data
      } catch (error) {
        return conCuerpoLegible(error)
      }
    },
  }
}

export const reportesServicio: ReportesServicio = crearReportesApi()
