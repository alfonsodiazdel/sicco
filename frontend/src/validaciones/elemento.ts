import { z } from 'zod'
import { texto } from '@/validaciones/usuario'

// Mismo largo que la columna `partidas.elemento`.
export const esquemaElemento = z.object({ nombre: texto(30) })
