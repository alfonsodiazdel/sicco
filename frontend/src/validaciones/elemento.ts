import { z } from 'zod'
import { texto } from '@/validaciones/usuario'

export const esquemaElemento = z.object({
  // Mismo largo que la columna `partidas.elemento`.
  nombre: texto(30),
  // Los grupos donde se puede elegir; puede quedar vacío.
  grupos: z.array(z.number()).default([]),
})
