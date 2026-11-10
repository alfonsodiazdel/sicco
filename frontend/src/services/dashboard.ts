import { http } from '@/api/http'
import type { Dashboard } from '@/types/dashboard'

export interface DashboardServicio {
  /** CU17: indicadores de los proyectos activos del usuario entre dos meses (`AAAA-MM`). */
  consultar(desde: string, hasta: string): Promise<Dashboard>
}

export const dashboardServicio: DashboardServicio = {
  async consultar(desde, hasta) {
    return (await http.get<Dashboard>('/dashboard', { params: { desde, hasta } })).data
  },
}
