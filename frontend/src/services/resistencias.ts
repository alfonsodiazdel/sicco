import { http } from '@/api/http'
import { errorDeConflicto, errorDeValidacion } from '@/services/errores'
import type { Resistencia, ResistenciaDatos, ResistenciaFila } from '@/types/resistencias'

export interface ResistenciasServicio {
  /** Las activas, de menor a mayor f'c. */
  listar(): Promise<Resistencia[]>
  /** Todas, con su estado y si están en uso: para el mantenedor. */
  todas(): Promise<ResistenciaFila[]>
  crear(datos: ResistenciaDatos): Promise<ResistenciaFila>
  actualizar(id: number, datos: ResistenciaDatos): Promise<ResistenciaFila>
  eliminar(id: number): Promise<void>
}

export const resistenciasEjemplo: Resistencia[] = [175, 210, 280, 350].map((fc, i) => ({
  id: i + 1,
  fc_kg_cm2: fc,
  nombre: `f'c ${fc} kg/cm²`,
}))

/** El nombre de la resistencia de ejemplo con ese id, para los servicios en memoria. */
export const nombreResistencia = (id: number) =>
  resistenciasEjemplo.find((r) => r.id === id)?.nombre ?? ''

/** Para las pruebas. `enUso` dice si algún registro usa ese f'c (en la API lo verifica el backend). */
export function crearResistenciasEnMemoria(
  enUso: (fc: number) => Promise<boolean> = async () => false,
): ResistenciasServicio {
  const resistencias = resistenciasEjemplo.map((r) => ({ ...r, activo: true }))
  let siguienteId = resistencias.length + 1
  const ordenadas = () => [...resistencias].sort((a, b) => a.fc_kg_cm2 - b.fc_kg_cm2)

  const buscar = (id: number) => {
    const resistencia = resistencias.find((r) => r.id === id)
    if (!resistencia) throw new Error('Resistencia no encontrada.')
    return resistencia
  }
  const fila = async (r: Resistencia & { activo: boolean }): Promise<ResistenciaFila> => ({
    ...r,
    en_uso: await enUso(r.fc_kg_cm2),
  })
  const validarFc = (fc: number, id?: number) => {
    if (resistencias.some((r) => r.id !== id && r.fc_kg_cm2 === fc)) {
      throw errorDeValidacion({ fc_kg_cm2: ['La resistencia ya existe'] })
    }
  }

  return {
    async listar() {
      return ordenadas()
        .filter((r) => r.activo)
        .map(({ id, fc_kg_cm2, nombre }) => ({ id, fc_kg_cm2, nombre }))
    },
    async todas() {
      return Promise.all(ordenadas().map(fila))
    },
    async crear(datos) {
      validarFc(datos.fc_kg_cm2)
      const resistencia = { id: siguienteId++, ...datos, activo: datos.activo ?? true }
      resistencias.push(resistencia)
      return fila(resistencia)
    },
    async actualizar(id, datos) {
      const resistencia = buscar(id)
      validarFc(datos.fc_kg_cm2, id)
      const cambia =
        datos.fc_kg_cm2 !== resistencia.fc_kg_cm2 || datos.nombre !== resistencia.nombre
      if (cambia && (await enUso(resistencia.fc_kg_cm2))) {
        throw errorDeConflicto('Esta resistencia está en uso: solo se puede desactivar.')
      }
      Object.assign(resistencia, { ...datos, activo: datos.activo ?? resistencia.activo })
      return fila(resistencia)
    },
    async eliminar(id) {
      const resistencia = buscar(id)
      if (await enUso(resistencia.fc_kg_cm2)) {
        throw errorDeConflicto('Esta resistencia está en uso: solo se puede desactivar.')
      }
      resistencias.splice(resistencias.indexOf(resistencia), 1)
    },
  }
}

export function crearResistenciasApi(): ResistenciasServicio {
  return {
    async listar() {
      return (await http.get<Resistencia[]>('/resistencias')).data
    },
    async todas() {
      return (await http.get<ResistenciaFila[]>('/resistencias/todas')).data
    },
    async crear(datos) {
      return (await http.post<ResistenciaFila>('/resistencias', datos)).data
    },
    async actualizar(id, datos) {
      return (await http.put<ResistenciaFila>(`/resistencias/${id}`, datos)).data
    },
    async eliminar(id) {
      await http.delete(`/resistencias/${id}`)
    },
  }
}

export const resistenciasServicio: ResistenciasServicio = crearResistenciasApi()
