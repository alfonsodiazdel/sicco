import { http } from '@/api/http'
import { errorDeConflicto, errorDeValidacion } from '@/services/errores'
import { gruposEjemplo } from '@/services/grupos'
import type { ElementoDatos, ElementoEstructural, ElementoFila } from '@/types/elementos'

export interface ElementosServicio {
  /** Los activos, para el combo de la partida. */
  listar(): Promise<ElementoEstructural[]>
  /** Todos, con su estado y si están en uso: para el mantenedor. */
  todos(): Promise<ElementoFila[]>
  crear(datos: ElementoDatos): Promise<ElementoFila>
  actualizar(id: number, datos: ElementoDatos): Promise<ElementoFila>
  eliminar(id: number): Promise<void>
}

const nombresIniciales = [
  'Zapata',
  'Cimiento corrido',
  'Columna',
  'Placa',
  'Viga',
  'Losa aligerada',
  'Losa maciza',
  'Escalera',
]

const enUsoMensaje = 'Este elemento está en uso en partidas: solo se puede desactivar.'

/** Para las pruebas. `enUso` dice si alguna partida usa el elemento (en la API lo verifica el backend). */
export function crearElementosEnMemoria(
  enUso: (nombre: string) => Promise<boolean> = async () => false,
): ElementosServicio {
  // Los grupos de cada uno salen de los de ejemplo de crearGruposEnMemoria.
  const elementos = nombresIniciales.map((nombre, i) => ({
    id: i + 1,
    nombre,
    activo: true,
    grupos: gruposEjemplo.filter((g) => g.elementos.includes(i + 1)).map((g) => g.id),
  }))
  let siguienteId = elementos.length + 1

  const buscar = (id: number) => {
    const elemento = elementos.find((e) => e.id === id)
    if (!elemento) throw new Error('Elemento no encontrado.')
    return elemento
  }
  const fila = async (e: Omit<ElementoFila, 'en_uso'>): Promise<ElementoFila> => ({
    ...e,
    grupos: [...e.grupos],
    en_uso: await enUso(e.nombre),
  })

  function validarNombre(nombre: string, id?: number) {
    const valor = nombre.trim().toLowerCase()
    if (elementos.some((e) => e.id !== id && e.nombre.toLowerCase() === valor)) {
      throw errorDeValidacion({ nombre: ['El elemento ya existe'] })
    }
  }

  return {
    async listar() {
      return elementos.filter((e) => e.activo).map(({ id, nombre }) => ({ id, nombre }))
    },
    async todos() {
      return Promise.all(elementos.map(fila))
    },
    async crear(datos) {
      validarNombre(datos.nombre)
      const elemento = {
        id: siguienteId++,
        nombre: datos.nombre,
        activo: datos.activo ?? true,
        grupos: [...(datos.grupos ?? [])],
      }
      elementos.push(elemento)
      return fila(elemento)
    },
    async actualizar(id, datos) {
      const elemento = buscar(id)
      validarNombre(datos.nombre, id)
      if (datos.nombre !== elemento.nombre && (await enUso(elemento.nombre))) {
        throw errorDeConflicto(enUsoMensaje)
      }
      Object.assign(elemento, {
        nombre: datos.nombre,
        activo: datos.activo ?? elemento.activo,
        grupos: [...(datos.grupos ?? elemento.grupos)],
      })
      return fila(elemento)
    },
    async eliminar(id) {
      const elemento = buscar(id)
      if (await enUso(elemento.nombre)) throw errorDeConflicto(enUsoMensaje)
      elementos.splice(elementos.indexOf(elemento), 1)
    },
  }
}

export function crearElementosApi(): ElementosServicio {
  return {
    async listar() {
      return (await http.get<ElementoEstructural[]>('/elementos-estructurales')).data
    },
    async todos() {
      return (await http.get<ElementoFila[]>('/elementos-estructurales/todos')).data
    },
    async crear(datos) {
      return (await http.post<ElementoFila>('/elementos-estructurales', datos)).data
    },
    async actualizar(id, datos) {
      return (await http.put<ElementoFila>(`/elementos-estructurales/${id}`, datos)).data
    },
    async eliminar(id) {
      await http.delete(`/elementos-estructurales/${id}`)
    },
  }
}

export const elementosServicio: ElementosServicio = crearElementosApi()
