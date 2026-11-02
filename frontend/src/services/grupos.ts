import { http } from '@/api/http'
import { errorDeConflicto, errorDeValidacion } from '@/services/errores'
import type { GrupoDatos, GrupoFila } from '@/types/grupos'

export interface GruposServicio {
  /** Ordenados por `orden`, como se muestran en el presupuesto. */
  listar(): Promise<GrupoFila[]>
  crear(datos: GrupoDatos): Promise<GrupoFila>
  actualizar(id: number, datos: GrupoDatos): Promise<GrupoFila>
  eliminar(id: number): Promise<void>
}

const gruposEjemplo: GrupoFila[] = [
  { id: 1, nombre: 'Muros anclados', partida_control: '2.02.02', orden: 1 },
  { id: 2, nombre: 'Cimentación', partida_control: '2.02.02', orden: 2 },
  { id: 3, nombre: 'Cisterna', partida_control: '2.02.02', orden: 3 },
  { id: 4, nombre: 'Verticales sótanos', partida_control: '2.02.02', orden: 4 },
  { id: 5, nombre: 'Horizontales sótanos', partida_control: '2.02.02', orden: 5 },
  { id: 6, nombre: 'Verticales superestructura', partida_control: '2.02.02', orden: 6 },
  { id: 7, nombre: 'Horizontales superestructura', partida_control: '2.02.02', orden: 7 },
]

/** Para las pruebas. `enUso` indica si el grupo tiene partidas (en la API lo verifica el backend). */
export function crearGruposEnMemoria(
  enUso: (grupoId: number) => Promise<boolean> = async () => false,
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
      if (await enUso(id)) {
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

export function crearGruposApi(): GruposServicio {
  return {
    async listar() {
      return (await http.get<GrupoFila[]>('/grupos-partida')).data
    },
    async crear(datos) {
      return (await http.post<GrupoFila>('/grupos-partida', datos)).data
    },
    async actualizar(id, datos) {
      return (await http.put<GrupoFila>(`/grupos-partida/${id}`, datos)).data
    },
    async eliminar(id) {
      await http.delete(`/grupos-partida/${id}`)
    },
  }
}

export const gruposServicio: GruposServicio = crearGruposApi()
