import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { crearProyectosEnMemoria } from '@/services/proyectos'
import { useAuthStore } from '@/stores/auth'
import type { Usuario } from '@/types/auth'

const sesion = (email: string) =>
  ({ email, rol: { clave: 'ingeniero_produccion' } }) as unknown as Usuario

describe('proyectos en memoria', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('asignados devuelve solo los proyectos del usuario de la sesión, por su correo', async () => {
    useAuthStore().usuario = sesion('Ingeniero_Produccion@SICCO.test')

    const asignados = await crearProyectosEnMemoria().asignados()

    expect(asignados.map((p) => p.id)).toEqual([1, 2])
  })

  it('sin sesión o con un correo sin asignaciones no devuelve ninguno', async () => {
    expect(await crearProyectosEnMemoria().asignados()).toEqual([])

    useAuthStore().usuario = sesion('gerencia@sicco.test')
    expect(await crearProyectosEnMemoria().asignados()).toEqual([])
  })
})
