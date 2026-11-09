import { http } from '@/api/http'
import type { Alerta, FiltroAlertas } from '@/types/alertas'

export interface AlertasServicio {
  /** Pantalla «Alertas de consumo»: de la más reciente a la más antigua. */
  listar(filtro?: FiltroAlertas): Promise<Alerta[]>
  /** CU14 paso 4: cuántas siguen pendientes, para el ícono de notificaciones. */
  pendientes(): Promise<number>
  /** CU14 paso 5: marca la alerta como «Revisada». */
  revisar(id: number): Promise<{ alerta: Alerta; message: string }>
}

export const alertasServicio: AlertasServicio = {
  async listar(filtro = {}) {
    const params = Object.fromEntries(Object.entries(filtro).filter(([, v]) => v))
    return (await http.get<Alerta[]>('/alertas', { params })).data
  },
  async pendientes() {
    return (await http.get<{ pendientes: number }>('/alertas/resumen')).data.pendientes
  },
  async revisar(id) {
    return (await http.patch<{ alerta: Alerta; message: string }>(`/alertas/${id}/revisar`)).data
  },
}

/** «Piso 1 – Sector C – Losa · f'c 210 kg/cm²», como el aviso del prototipo. */
export const ubicacion = (a: Alerta) =>
  `${a.sector.nivel} – ${a.sector.nombre} – ${a.sector.elemento}`

const formatoFechaHora = new Intl.DateTimeFormat('es-PE', {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
  timeZone: 'America/Lima',
})

/** Fecha y hora en Lima: el backend guarda en UTC. */
export const fechaHora = (iso: string) => formatoFechaHora.format(new Date(iso))
