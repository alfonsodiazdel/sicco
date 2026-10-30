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
): SectorFila => ({ id, nivel_id, presupuesto_id, nombre, volumen_presup_m3, volumen_vaciado_m3 })

const sectoresEjemplo: SectorFila[] = [
  sector(1, 2, 2, 'Sector A', 60, 12),
  sector(2, 2, 2, 'Sector B', 58.5, 3.5),
  sector(3, 2, 3, 'Sector C', 122.5, 8),
  sector(4, 1, 1, 'Zapatas eje 1-3', 40, 32),
  sector(5, 1, 1, 'Zapatas eje 4-6', 36, 0),
]

export function crearNivelesEnMemoria(
  partidas: PartidasServicio = partidasServicio,
): NivelesServicio {
  const niveles = nivelesEjemplo.map((n) => ({ ...n }))
  const sectores = sectoresEjemplo.map((s) => ({ ...s }))
  let siguienteNivel = niveles.length + 1
  let siguienteSector = sectores.length + 1

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
      }
      sectores.push({ ...nuevo })
      return nuevo
    },
    async actualizarSector(id, datos) {
      const actual = sectores.find((s) => s.id === id)
      if (!actual) throw new Error('Sector no encontrado.')
      await validarVolumen(actual.nivel_id, datos, id)
      return { ...Object.assign(actual, datos) }
    },
  }
}

// Hasta que exista el endpoint; la implementación con la API solo reemplaza esta línea.
export const nivelesServicio: NivelesServicio = crearNivelesEnMemoria()
