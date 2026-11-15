import { z } from 'zod'
import { requerido } from '@/validaciones/usuario'

// La columna es un entero sin signo de 16 bits.
export const esquemaResistencia = z.object({
  fc_kg_cm2: z
    .number({ required_error: requerido, invalid_type_error: requerido })
    .int('Ingrese un número entero')
    .positive('Ingrese un valor mayor a cero')
    .max(65535, 'Ingrese un valor menor o igual a 65535'),
})

/** Como se muestra en el presupuesto y las guías: «f'c 280 kg/cm²». */
export const nombreDeResistencia = (fc: number) => `f'c ${fc} kg/cm²`
