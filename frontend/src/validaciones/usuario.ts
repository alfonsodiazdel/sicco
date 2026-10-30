import { z } from 'zod'
import type { RolClave } from '@/types/auth'
import { etiquetasRol } from '@/types/usuarios'

export const requerido = 'Este dato es requerido'

export const texto = (maximo: number) =>
  z
    .string({ required_error: requerido })
    .trim()
    .min(1, requerido)
    .max(maximo, `Debe tener como máximo ${maximo} caracteres`)

const roles = Object.keys(etiquetasRol) as [RolClave, ...RolClave[]]

export const esquemaUsuario = z.object({
  nombres: texto(100),
  apellidos: texto(100),
  email: texto(255).regex(
    /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/,
    'Ingrese un correo válido, por ejemplo nombre@empresa.com.',
  ),
  rol: z.enum(roles, { errorMap: () => ({ message: requerido }) }),
  proyecto_ids: z.array(z.number(), { required_error: requerido }).min(1, requerido),
})
