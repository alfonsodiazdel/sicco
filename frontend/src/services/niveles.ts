import { http } from '@/api/http'
import { errorDeValidacion } from '@/services/errores'
import { partidasServicio, type PartidasServicio } from '@/services/partidas'
import { volumenDisponible } from '@/services/presupuesto'
import type { NivelDatos, NivelFila, SectorDatos, SectorFila } from '@/types/niveles'

export interface NivelesServicio {
  listarNiveles(proyectoId: number): Promise<NivelFila[]>
  crearNivel(proyectoId: number, datos: NivelDatos): Promise<NivelFila>
  actualizarNivel(id: number, datos: NivelDatos): Promise<NivelFila>
  /** Los de todos los niveles del proyecto: el disponible de una partida los suma a todos. */
  listarSectores(proyectoId: number): Promise<SectorFila[]>
  crearSector(nivelId: number, datos: SectorDatos): Promise<SectorFila>
  actualizarSector(id: number, datos: SectorDatos): Promise<SectorFila>
  /** CU05: `fecha` en `YYYY-MM-DD`, no futura ni anterior a la última guía del sector. */
  culminarSector(id: number, fecha: string): Promise<SectorFila>
  reabrirSector(id: number): Promise<SectorFila>
  /** CU05: corrige un error de planificación; solo un nivel sin sectores. */
  eliminarNivel(id: number): Promise<void>
  /** CU05: solo un sector abierto y sin guías asignadas ahora. */
  eliminarSector(id: number): Promise<void>
}

const nivelesEjemplo: NivelFila[] = [
  { id: 1, proyecto_id: 1, nombre: 'Sótano 1', orden: 1 },
  { id: 2, proyecto_id: 1, nombre: 'Piso 1', orden: 2 },
  { id: 3, proyecto_id: 1, nombre: 'Piso 2', orden: 3 },
  { id: 4, proyecto_id: 2, nombre: 'Piso 1', orden: 1 },
]

const sector = (
  id: number,
  nivel_id: number,
  presupuesto_id: number,
  nombre: string,
  volumen_presup_m3: number,
  volumen_vaciado_m3: number,
  fecha_culminacion: string | null = null,
): SectorFila => ({
  id,
  nivel_id,
  presupuesto_id,
  nombre,
  volumen_presup_m3,
  volumen_vaciado_m3,
  fecha_culminacion,
})

const sectoresEjemplo: SectorFila[] = [
  sector(1, 2, 2, 'Sector A', 60, 12),
  sector(2, 2, 2, 'Sector B', 58.5, 3.5),
  sector(3, 2, 3, 'Sector C', 122.5, 8),
  sector(4, 1, 1, 'Zapatas eje 1-3', 40, 32, '2026-10-20'),
  sector(5, 1, 1, 'Zapatas eje 4-6', 36, 0),
]

export function crearNivelesEnMemoria(
  partidas: PartidasServicio = partidasServicio,
): NivelesServicio {
  const niveles = nivelesEjemplo.map((n) => ({ ...n }))
  const sectores = sectoresEjemplo.map((s) => ({ ...s }))
  let siguienteNivel = niveles.length + 1
  let siguienteSector = sectores.length + 1

  function buscarSector(id: number) {
    const sector = sectores.find((s) => s.id === id)
    if (!sector) throw new Error('Sector no encontrado.')
    return sector
  }

  function buscarNivel(id: number) {
    const nivel = niveles.find((n) => n.id === id)
    if (!nivel) throw new Error('Nivel no encontrado.')
    return nivel
  }

  const delProyecto = (proyectoId: number) =>
    sectores.filter((s) => buscarNivel(s.nivel_id).proyecto_id === proyectoId)

  async function validarVolumen(nivelId: number, datos: SectorDatos, sectorId?: number) {
    const proyectoId = buscarNivel(nivelId).proyecto_id
    const partida = (await partidas.listar(proyectoId)).find((p) => p.id === datos.presupuesto_id)
    if (!partida) throw errorDeValidacion({ presupuesto_id: ['Este dato es requerido'] })
    if (datos.volumen_presup_m3 > volumenDisponible(partida, delProyecto(proyectoId), sectorId)) {
      throw errorDeValidacion({
        volumen_presup_m3: ['El volumen excede lo presupuestado para el elemento'],
      })
    }
  }

  return {
    async listarNiveles(proyectoId) {
      return niveles.filter((n) => n.proyecto_id === proyectoId).map((n) => ({ ...n }))
    },
    async crearNivel(proyectoId, datos) {
      const nivel: NivelFila = { id: siguienteNivel++, proyecto_id: proyectoId, ...datos }
      niveles.push({ ...nivel })
      return nivel
    },
    async actualizarNivel(id, datos) {
      return { ...Object.assign(buscarNivel(id), datos) }
    },
    async listarSectores(proyectoId) {
      return delProyecto(proyectoId).map((s) => ({ ...s }))
    },
    async crearSector(nivelId, datos) {
      await validarVolumen(nivelId, datos)
      const nuevo: SectorFila = {
        id: siguienteSector++,
        nivel_id: nivelId,
        ...datos,
        volumen_vaciado_m3: 0,
        fecha_culminacion: null,
      }
      sectores.push({ ...nuevo })
      return nuevo
    },
    async actualizarSector(id, datos) {
      const actual = buscarSector(id)
      if (actual.fecha_culminacion) {
        throw errorDeValidacion({ sector: ['El sector está culminado; reábralo para editarlo'] })
      }
      await validarVolumen(actual.nivel_id, datos, id)
      return { ...Object.assign(actual, datos) }
    },
    async culminarSector(id, fecha) {
      const actual = buscarSector(id)
      if (actual.fecha_culminacion) {
        throw errorDeValidacion({ sector: ['El sector ya está culminado'] })
      }
      return { ...Object.assign(actual, { fecha_culminacion: fecha }) }
    },
    async reabrirSector(id) {
      const actual = buscarSector(id)
      if (!actual.fecha_culminacion) {
        throw errorDeValidacion({ sector: ['El sector no está culminado'] })
      }
      return { ...Object.assign(actual, { fecha_culminacion: null }) }
    },
    async eliminarNivel(id) {
      buscarNivel(id)
      if (sectores.some((s) => s.nivel_id === id)) {
        throw errorDeValidacion({ nivel: ['El nivel tiene sectores; elimínelos para eliminarlo'] })
      }
      niveles.splice(
        niveles.findIndex((n) => n.id === id),
        1,
      )
    },
    async eliminarSector(id) {
      const actual = buscarSector(id)
      if (actual.fecha_culminacion) {
        throw errorDeValidacion({ sector: ['El sector está culminado; reábralo para eliminarlo'] })
      }
      // En memoria no hay guías: lo vaciado hace las veces de sus asignaciones.
      if (actual.volumen_vaciado_m3 > 0) {
        throw errorDeValidacion({
          sector: ['El sector tiene guías asignadas; reasígnelas a otro sector para eliminarlo'],
        })
      }
      sectores.splice(sectores.indexOf(actual), 1)
    },
  }
}

export function crearNivelesApi(): NivelesServicio {
  return {
    async listarNiveles(proyectoId) {
      return (await http.get<NivelFila[]>(`/proyectos/${proyectoId}/niveles`)).data
    },
    async crearNivel(proyectoId, datos) {
      return (await http.post<NivelFila>(`/proyectos/${proyectoId}/niveles`, datos)).data
    },
    async actualizarNivel(id, datos) {
      return (await http.put<NivelFila>(`/niveles/${id}`, datos)).data
    },
    async listarSectores(proyectoId) {
      return (await http.get<SectorFila[]>(`/proyectos/${proyectoId}/sectores`)).data
    },
    async crearSector(nivelId, datos) {
      return (await http.post<SectorFila>(`/niveles/${nivelId}/sectores`, datos)).data
    },
    async actualizarSector(id, datos) {
      return (await http.put<SectorFila>(`/sectores/${id}`, datos)).data
    },
    async culminarSector(id, fecha) {
      const url = `/sectores/${id}/culminar`
      return (await http.post<{ data: SectorFila }>(url, { fecha_culminacion: fecha })).data.data
    },
    async reabrirSector(id) {
      return (await http.post<{ data: SectorFila }>(`/sectores/${id}/reabrir`)).data.data
    },
    async eliminarNivel(id) {
      await http.delete(`/niveles/${id}`)
    },
    async eliminarSector(id) {
      await http.delete(`/sectores/${id}`)
    },
  }
}

export const nivelesServicio: NivelesServicio = crearNivelesApi()
