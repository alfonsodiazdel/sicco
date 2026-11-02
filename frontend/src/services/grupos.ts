import { errorDeConflicto, errorDeValidacion } from '@/services/errores'
import type { PartidasServicio } from '@/services/partidas'
import type { GrupoDatos, GrupoFila } from '@/types/grupos'

export interface GruposServicio {
  /** Ordenados por `orden`, como se muestran en el presupuesto. */
  listar(): Promise<GrupoFila[]>
  crear(datos: GrupoDatos): Promise<GrupoFila>
  actualizar(id: number, datos: GrupoDatos): Promise<GrupoFila>
  eliminar(id: number): Promise<void>
}

const gruposEjemplo: GrupoFila[] = [
  { id: 1, nombre: 'Muros anclados', orden: 1 },
  { id: 2, nombre: 'Cimentación', orden: 2 },
  { id: 3, nombre: 'Cisterna', orden: 3 },
  { id: 4, nombre: 'Verticales sótanos', orden: 4 },
  { id: 5, nombre: 'Horizontales sótanos', orden: 5 },
  { id: 6, nombre: 'Verticales superestructura', orden: 6 },
  { id: 7, nombre: 'Horizontales superestructura', orden: 7 },
]

// Import diferido: partidas depende de grupos para ordenar el presupuesto.
const partidasPorDefecto = async () => (await import('@/services/partidas')).partidasServicio

export function crearGruposEnMemoria(
  partidas: () => Promise<Pick<PartidasServicio, 'usanGrupo'>> = partidasPorDefecto,
): GruposServicio {
  const grupos = gruposEjemplo.map((g) => ({ ...g }))
  let siguienteId = grupos.length + 1

  function buscar(id: number) {
    const grupo = grupos.find((g) => g.id === id)
    if (!grupo) throw new Error('Grupo no encontrado.')
    return grupo
  }

  // Los reportes agrupan por nombre: dos grupos iguales partirían el mismo subtotal.
  function validarNombre(nombre: string, id?: number) {
    const valor = nombre.trim().toLowerCase()
    if (grupos.some((g) => g.id !== id && g.nombre.toLowerCase() === valor)) {
      throw errorDeValidacion({ nombre: ['El grupo ya existe'] })
    }
  }

  return {
    async listar() {
      return grupos.map((g) => ({ ...g })).sort((a, b) => a.orden - b.orden || a.id - b.id)
    },
    async crear(datos) {
      validarNombre(datos.nombre)
      const grupo: GrupoFila = { id: siguienteId++, ...datos }
      grupos.push({ ...grupo })
      return grupo
    },
    async actualizar(id, datos) {
      validarNombre(datos.nombre, id)
      return { ...Object.assign(buscar(id), datos) }
    },
    async eliminar(id) {
      buscar(id)
      if (await (await partidas()).usanGrupo(id)) {
        throw errorDeConflicto(
          'El grupo tiene partidas registradas; reasígnelas antes de eliminarlo.',
        )
      }
      grupos.splice(
        grupos.findIndex((g) => g.id === id),
        1,
      )
    },
  }
}

// Hasta que exista el endpoint; la implementación con la API solo reemplaza esta línea.
export const gruposServicio: GruposServicio = crearGruposEnMemoria()
