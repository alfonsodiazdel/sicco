import { http } from '@/api/http'
import { errorDeValidacion } from '@/services/errores'
import { resistenciasEjemplo } from '@/services/resistencias'
import type {
  AsignacionDatos,
  AsignacionFila,
  AsignacionGuia,
  FiltroSinSector,
  GuiaPendiente,
} from '@/types/guias'

export interface AsignacionesServicio {
  /** CU10 pasos 2–3: las guías con volumen por asignar, de la más antigua a la más reciente. */
  listarSinSector(proyectoId: number, filtro: FiltroSinSector): Promise<GuiaPendiente[]>
  /** CU10 requerimiento especial: la misma lista en Excel. */
  exportarSinSector(proyectoId: number, filtro: FiltroSinSector): Promise<Blob>
  /** CU09 paso 1: la guía con su volumen disponible y su reparto actual. */
  obtener(guiaId: number): Promise<AsignacionGuia>
  /** CU09 pasos 5–7: reemplaza el reparto de la guía. */
  guardar(guiaId: number, asignaciones: AsignacionDatos[]): Promise<AsignacionGuia>
}

// Sin fechas, el backend trae todas las pendientes.
const parametros = (filtro: FiltroSinSector) =>
  Object.fromEntries(Object.entries(filtro).filter(([, v]) => v))

export function crearAsignacionesApi(): AsignacionesServicio {
  return {
    async listarSinSector(proyectoId, filtro) {
      const url = `/proyectos/${proyectoId}/guias/sin-sector`
      return (await http.get<GuiaPendiente[]>(url, { params: parametros(filtro) })).data
    },
    async exportarSinSector(proyectoId, filtro) {
      const url = `/proyectos/${proyectoId}/guias/sin-sector/exportar`
      return (await http.get<Blob>(url, { params: parametros(filtro), responseType: 'blob' })).data
    },
    async obtener(guiaId) {
      return (await http.get<AsignacionGuia>(`/guias/${guiaId}/asignaciones`)).data
    },
    async guardar(guiaId, asignaciones) {
      return (await http.put<AsignacionGuia>(`/guias/${guiaId}/asignaciones`, { asignaciones }))
        .data
    },
  }
}

const centesimos = (valor: number) => Math.round(valor * 100)

// Días sin asignar contados desde una fecha fija, para que las pruebas no dependan del día.
const HOY = '2026-11-02'

const guia = (
  id: number,
  numero: string,
  fecha: string,
  volumen_m3: number,
  dias: number,
): GuiaPendiente => ({
  id,
  proyecto_id: 1,
  proveedor: { id: 1, ruc: '20100030595', razon_social: 'UNICON S.A.' },
  numero,
  fecha,
  resistencia: { ...resistenciasEjemplo[1]! },
  codigo_producto: null,
  volumen_m3,
  placa: 'ABC-123',
  estado_asignacion: 'sin_sector',
  origen: 'ocr',
  volumen_asignado_m3: 0,
  volumen_pendiente_m3: volumen_m3,
  dias_transcurridos: dias,
  atrasada: dias > 2,
})

const guiasEjemplo: GuiaPendiente[] = [
  guia(1, 'T001-00012340', '2026-10-28', 8.5, 5),
  guia(2, 'T001-00012345', '2026-10-31', 7, 2),
  guia(3, 'T001-00012350', HOY, 8, 0),
]

/**
 * Para las pruebas de las vistas. `sectoresDelProyecto` son los que acepta, como lo validaría el
 * backend; el vaciado de los sectores no se lleva aquí.
 */
export function crearAsignacionesEnMemoria(
  sectoresDelProyecto: number[] = [1, 2, 3, 4, 5],
): AsignacionesServicio {
  const guias = guiasEjemplo.map((g) => ({ ...g }))
  const asignaciones = new Map<number, AsignacionFila[]>()
  let siguiente = 1

  function buscar(id: number) {
    const encontrada = guias.find((g) => g.id === id)
    if (!encontrada) throw new Error('Guía no encontrada.')
    return encontrada
  }

  const respuesta = (g: GuiaPendiente): AsignacionGuia => ({
    guia: { ...g },
    asignaciones: (asignaciones.get(g.id) ?? []).map((a) => ({ ...a })),
  })

  return {
    async listarSinSector(proyectoId, { desde, hasta }) {
      return guias
        .filter((g) => g.proyecto_id === proyectoId && g.estado_asignacion !== 'asignada')
        .filter((g) => (!desde || g.fecha >= desde) && (!hasta || g.fecha <= hasta))
        .map((g) => ({ ...g }))
    },
    async exportarSinSector() {
      return new Blob(['PK'], {
        type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      })
    },
    async obtener(guiaId) {
      return respuesta(buscar(guiaId))
    },
    async guardar(guiaId, nuevas) {
      const g = buscar(guiaId)
      if (nuevas.some((a) => !sectoresDelProyecto.includes(a.sector_id))) {
        throw errorDeValidacion({
          'asignaciones.0.sector_id': ['El sector no pertenece al proyecto de la guía'],
        })
      }
      const suma = nuevas.reduce((total, a) => total + centesimos(a.volumen_m3), 0)
      if (suma > centesimos(g.volumen_m3)) {
        throw errorDeValidacion({ asignaciones: ['El volumen asignado supera el de la guía'] })
      }
      asignaciones.set(
        guiaId,
        nuevas.map((a) => ({ id: siguiente++, ...a })),
      )
      g.volumen_asignado_m3 = suma / 100
      g.volumen_pendiente_m3 = (centesimos(g.volumen_m3) - suma) / 100
      g.estado_asignacion = suma === centesimos(g.volumen_m3) ? 'asignada' : 'parcial'
      return { ...respuesta(g), sectores_excedidos: [], message: 'Guía asignada correctamente' }
    },
  }
}

export const asignacionesServicio: AsignacionesServicio = crearAsignacionesApi()
