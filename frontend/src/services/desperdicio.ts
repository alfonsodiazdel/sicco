import { http } from '@/api/http'
import type { ControlDesperdicio, DetalleDesperdicio } from '@/types/desperdicio'

export interface DesperdicioServicio {
  /** CU13 pasos 2–3: presupuestado y vaciado del proyecto al nivel de detalle pedido. */
  consultar(proyectoId: number, detalle: DetalleDesperdicio): Promise<ControlDesperdicio>
}

export const desperdicioServicio: DesperdicioServicio = {
  async consultar(proyectoId, detalle) {
    return (
      await http.get<ControlDesperdicio>(`/proyectos/${proyectoId}/desperdicio`, {
        params: { detalle },
      })
    ).data
  },
}
