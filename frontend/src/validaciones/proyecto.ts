import { z } from 'zod'
import { estadosProyecto, type ProyectoDatos, type ProyectoFila } from '@/types/proyectos'
import { requerido, texto } from '@/validaciones/usuario'

// El DatePicker devuelve null al borrarse; también cuenta como vacío.
const fecha = z.date({ required_error: requerido, invalid_type_error: requerido })

export const esquemaProyecto = z
  .object({
    codigo: texto(20),
    nombre: texto(120),
    direccion: texto(150),
    fecha_inicio: fecha,
    fecha_fin: fecha,
    ingeniero_id: z.number({ required_error: requerido, invalid_type_error: requerido }),
    estado: z.enum(estadosProyecto).optional(),
  })
  .refine((d) => d.fecha_fin > d.fecha_inicio, {
    message: 'La fecha de fin debe ser posterior a la de inicio',
    path: ['fecha_fin'],
  })

type ValoresProyecto = z.output<typeof esquemaProyecto>

const dosDigitos = (n: number) => String(n).padStart(2, '0')

// Con la fecha local, no con toISOString, para que la zona horaria no mueva el día.
export const aIso = (f: Date) =>
  `${f.getFullYear()}-${dosDigitos(f.getMonth() + 1)}-${dosDigitos(f.getDate())}`

function deIso(iso: string) {
  const [anio = 0, mes = 1, dia = 1] = iso.split('-').map(Number)
  return new Date(anio, mes - 1, dia)
}

export function valoresDeProyecto(proyecto: ProyectoFila) {
  return {
    codigo: proyecto.codigo,
    nombre: proyecto.nombre,
    direccion: proyecto.direccion,
    fecha_inicio: deIso(proyecto.fecha_inicio),
    fecha_fin: deIso(proyecto.fecha_fin),
    ingeniero_id: proyecto.ingeniero_id,
    estado: proyecto.estado,
  }
}

export function datosDeProyecto(valores: ValoresProyecto): ProyectoDatos {
  return {
    ...valores,
    fecha_inicio: aIso(valores.fecha_inicio),
    fecha_fin: aIso(valores.fecha_fin),
  }
}
