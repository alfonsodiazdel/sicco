import { http } from '@/api/http'
import type {
  FormatoEat,
  ReporteCorteMes,
  ReporteEat,
  ResultadoCorteMes,
  ResultadoEat,
} from '@/types/reportes'

export interface ReportesServicio {
  /** CU15: reporte EAT del proyecto a la fecha de corte (`YYYY-MM-DD`). */
  eat(proyectoId: number, fechaCorte: string): Promise<ResultadoEat>
  descargarEat(proyectoId: number, fechaCorte: string, formato: FormatoEat): Promise<Blob>
  /** CU16: reporte de corte a fin de mes del proyecto (`mes` de 1 a 12). */
  corteMes(proyectoId: number, anio: number, mes: number): Promise<ResultadoCorteMes>
  descargarCorteMes(proyectoId: number, anio: number, mes: number, formato: FormatoEat): Promise<Blob>
}

interface RespuestaCorteMes {
  data: ReporteCorteMes | null
  mensaje?: string
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
    async corteMes(proyectoId, anio, mes) {
      const url = `/proyectos/${proyectoId}/reportes/corte-mes`
      const { data } = await http.get<RespuestaCorteMes>(url, { params: { anio, mes } })
      return { reporte: data.data, mensaje: data.mensaje ?? null }
    },
    async descargarCorteMes(proyectoId, anio, mes, formato) {
      const url = `/proyectos/${proyectoId}/reportes/corte-mes/${formato}`
      return (await http.get<Blob>(url, { params: { anio, mes }, responseType: 'blob' })).data
    },
  }
}

export const reportesServicio: ReportesServicio = crearReportesApi()
