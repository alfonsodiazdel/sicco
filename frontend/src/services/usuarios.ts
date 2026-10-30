import { errorDeValidacion } from '@/services/errores'
import { proyectosServicio } from '@/services/proyectos'
import type { ProyectoOpcion, UsuarioDatos, UsuarioFila } from '@/types/usuarios'

export interface UsuariosServicio {
  listar(): Promise<UsuarioFila[]>
  crear(datos: UsuarioDatos): Promise<UsuarioFila>
  actualizar(id: number, datos: UsuarioDatos): Promise<UsuarioFila>
  cambiarEstado(id: number, activo: boolean): Promise<UsuarioFila>
  proyectos(): Promise<ProyectoOpcion[]>
}

const usuariosEjemplo: UsuarioFila[] = [
  {
    id: 1,
    nombres: 'Administrador',
    apellidos: 'Demo',
    email: 'administrador@sicco.test',
    rol: 'administrador',
    proyecto_ids: [1, 2, 3],
    activo: true,
  },
  {
    id: 2,
    nombres: 'Carlos',
    apellidos: 'Quispe',
    email: 'almacenero@sicco.test',
    rol: 'almacenero',
    proyecto_ids: [1],
    activo: true,
  },
  {
    id: 3,
    nombres: 'Lucía',
    apellidos: 'Ramos',
    email: 'ingeniero_produccion@sicco.test',
    rol: 'ingeniero_produccion',
    proyecto_ids: [1, 2],
    activo: true,
  },
  {
    id: 4,
    nombres: 'Jorge',
    apellidos: 'Salas',
    email: 'oficina_tecnica@sicco.test',
    rol: 'oficina_tecnica',
    proyecto_ids: [2],
    activo: false,
  },
]

const copia = (u: UsuarioFila): UsuarioFila => ({ ...u, proyecto_ids: [...u.proyecto_ids] })

export function crearUsuariosEnMemoria(): UsuariosServicio {
  const usuarios = usuariosEjemplo.map(copia)
  let siguienteId = usuarios.length + 1

  function buscar(id: number) {
    const usuario = usuarios.find((u) => u.id === id)
    if (!usuario) throw new Error('Usuario no encontrado.')
    return usuario
  }

  function validarCorreo(email: string, id?: number) {
    const correo = email.toLowerCase()
    if (usuarios.some((u) => u.id !== id && u.email.toLowerCase() === correo)) {
      throw errorDeValidacion({ email: ['El correo ya está registrado'] })
    }
  }

  return {
    async listar() {
      return usuarios.map(copia)
    },
    async crear(datos) {
      validarCorreo(datos.email)
      const usuario: UsuarioFila = { ...datos, id: siguienteId++, activo: true }
      usuarios.push(copia(usuario))
      return usuario
    },
    async actualizar(id, datos) {
      validarCorreo(datos.email, id)
      return copia(Object.assign(buscar(id), datos, { proyecto_ids: [...datos.proyecto_ids] }))
    },
    async cambiarEstado(id, activo) {
      return copia(Object.assign(buscar(id), { activo }))
    },
    async proyectos() {
      return (await proyectosServicio.listar()).map(({ id, nombre }) => ({ id, nombre }))
    },
  }
}

// Hasta que exista el endpoint; la implementación con la API solo reemplaza esta línea.
export const usuariosServicio: UsuariosServicio = crearUsuariosEnMemoria()
