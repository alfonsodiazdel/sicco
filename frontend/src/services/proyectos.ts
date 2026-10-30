import { errorDeValidacion } from '@/services/errores'
import type { ProyectoDatos, ProyectoFila } from '@/types/proyectos'

export interface ProyectosServicio {
  listar(): Promise<ProyectoFila[]>
  /** Los asignados al usuario de la sesión (USUARIO_PROYECTO); la API los saca de la sesión. */
  asignados(): Promise<ProyectoFila[]>
  crear(datos: ProyectoDatos): Promise<ProyectoFila>
  actualizar(id: number, datos: ProyectoDatos): Promise<ProyectoFila>
}

const proyectosEjemplo: ProyectoFila[] = [
  {
    id: 1,
    codigo: 'PRY-001',
    nombre: 'Residencial Los Álamos',
    direccion: 'Av. Primavera 1250, Santiago de Surco',
    fecha_inicio: '2026-03-02',
    fecha_fin: '2027-02-26',
    ingeniero_id: 3,
    estado: 'Activo',
  },
  {
    id: 2,
    codigo: 'PRY-002',
    nombre: 'Torre Empresarial Javier Prado',
    direccion: 'Av. Javier Prado Este 3580, San Isidro',
    fecha_inicio: '2026-01-12',
    fecha_fin: '2027-12-17',
    ingeniero_id: 3,
    estado: 'Activo',
  },
  {
    id: 3,
    codigo: 'PRY-003',
    nombre: 'Condominio Las Palmeras',
    direccion: 'Av. La Molina 1150, La Molina',
    fecha_inicio: '2026-11-02',
    fecha_fin: '2027-10-29',
    ingeniero_id: 3,
    estado: 'Activo',
  },
]

// Import diferido: usuarios depende de proyectos para ofrecer las asignaciones. Se busca por
// correo porque los ids de la sesión son los de la base y no los de estos datos de ejemplo.
async function asignadosAlUsuarioActual() {
  const [{ useAuthStore }, { usuariosServicio }] = await Promise.all([
    import('@/stores/auth'),
    import('@/services/usuarios'),
  ])
  const correo = useAuthStore().usuario?.email.toLowerCase()
  const usuario = (await usuariosServicio.listar()).find((u) => u.email.toLowerCase() === correo)
  return usuario?.proyecto_ids ?? []
}

export function crearProyectosEnMemoria(
  idsAsignados: () => Promise<number[]> = asignadosAlUsuarioActual,
): ProyectosServicio {
  const proyectos = proyectosEjemplo.map((p) => ({ ...p }))
  let siguienteId = proyectos.length + 1

  function buscar(id: number) {
    const proyecto = proyectos.find((p) => p.id === id)
    if (!proyecto) throw new Error('Proyecto no encontrado.')
    return proyecto
  }

  function validarCodigo(codigo: string, id?: number) {
    const valor = codigo.toLowerCase()
    if (proyectos.some((p) => p.id !== id && p.codigo.toLowerCase() === valor)) {
      throw errorDeValidacion({ codigo: ['El código de proyecto ya existe'] })
    }
  }

  return {
    async listar() {
      return proyectos.map((p) => ({ ...p }))
    },
    async asignados() {
      const ids = await idsAsignados()
      return proyectos.filter((p) => ids.includes(p.id)).map((p) => ({ ...p }))
    },
    async crear(datos) {
      validarCodigo(datos.codigo)
      const proyecto: ProyectoFila = { ...datos, id: siguienteId++, estado: 'Activo' }
      proyectos.push({ ...proyecto })
      return proyecto
    },
    async actualizar(id, datos) {
      validarCodigo(datos.codigo, id)
      const proyecto = buscar(id)
      return { ...Object.assign(proyecto, datos, { estado: datos.estado ?? proyecto.estado }) }
    },
  }
}

// Hasta que exista el endpoint; la implementación con la API solo reemplaza esta línea.
export const proyectosServicio: ProyectosServicio = crearProyectosEnMemoria()
