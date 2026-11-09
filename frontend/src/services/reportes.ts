import { http } from '@/api/http'
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
      return (await http.get<Blob>(url, { params, responseType: 'blob' })).data
    },
  }
}

export const reportesServicio: ReportesServicio = crearReportesApi()
