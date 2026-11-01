import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { crearProyectosEnMemoria, type ProyectosServicio } from '@/services/proyectos'
import { useAuthStore } from '@/stores/auth'
import { useProyectoActualStore } from '@/stores/proyectoActual'
import type { Usuario } from '@/types/auth'

const { servicios } = vi.hoisted(() => ({
  servicios: { proyectos: null as ProyectosServicio | null, asignados: [] as number[] },
}))

vi.mock('@/services/proyectos', async (importOriginal) => {
  const real = await importOriginal<typeof import('@/services/proyectos')>()
  return {
    ...real,
    get proyectosServicio() {
      return servicios.proyectos
    },
  }
})

const usuario = (id: number) => ({ id, rol: { clave: 'oficina_tecnica' } }) as Usuario

function preparar(id = 7) {
  setActivePinia(createPinia())
  useAuthStore().usuario = usuario(id)
  return useProyectoActualStore()
}

describe('proyectoActual store', () => {
  beforeEach(() => {
    localStorage.clear()
    servicios.asignados = [1, 2, 3]
    servicios.proyectos = crearProyectosEnMemoria(async () => servicios.asignados)
  })

  it('ofrece solo los activos asignados y elige el primero', async () => {
    servicios.asignados = [2, 3]
    const tercero = (await servicios.proyectos!.listar()).find((p) => p.id === 3)!
    await servicios.proyectos!.actualizar(3, { ...tercero, estado: 'Finalizado' })
    const store = preparar()
    await store.cargar()

    expect(store.proyectos.map((p) => p.id)).toEqual([2])
    expect(store.proyecto?.nombre).toBe('Torre Empresarial Javier Prado')
  })

  it('pide los proyectos una sola vez aunque lo pidan varias vistas a la vez', async () => {
    const store = preparar()
    const asignados = vi.spyOn(servicios.proyectos!, 'asignados')
    await Promise.all([store.cargar(), store.cargar()])
    await store.cargar()

    expect(asignados).toHaveBeenCalledOnce()
  })

  it('solo elige proyectos ofrecidos y acepta el id como texto de la URL', async () => {
    servicios.asignados = [1, 2]
    const store = preparar()
    await store.cargar()

    expect(store.elegir('2')).toBe(true)
    expect(store.proyectoId).toBe(2)
    expect(store.elegir(3)).toBe(false)
    expect(store.elegir('abc')).toBe(false)
    expect(store.proyectoId).toBe(2)
  })

  it('recuerda el último proyecto de cada usuario', async () => {
    let store = preparar(7)
    await store.cargar()
    store.elegir(3)
    await flushPromises()

    store = preparar(7)
    await store.cargar()
    expect(store.proyectoId).toBe(3)

    store = preparar(8)
    await store.cargar()
    expect(store.proyectoId).toBe(1)
  })

  it('si el proyecto recordado ya no está asignado vuelve al primero', async () => {
    localStorage.setItem('sicco-proyecto-7', '3')
    servicios.asignados = [1, 2]
    const store = preparar(7)
    await store.cargar()

    expect(store.proyectoId).toBe(1)
  })

  it('al cambiar de usuario olvida los proyectos del anterior', async () => {
    const store = preparar(7)
    await store.cargar()
    useAuthStore().usuario = null
    await flushPromises()

    expect(store.cargado).toBe(false)
    expect(store.proyectos).toEqual([])
    expect(store.proyectoId).toBeNull()
  })

  it('descarta la respuesta pedida para el usuario anterior', async () => {
    const store = preparar(7)
    const pedido = store.cargar()
    useAuthStore().usuario = usuario(8)
    await flushPromises()
    await pedido

    expect(store.cargado).toBe(false)
    expect(store.proyectos).toEqual([])
  })

  it('al recargar conserva el proyecto elegido si sigue activo', async () => {
    const store = preparar()
    await store.cargar()
    store.elegir(2)
    const segundo = (await servicios.proyectos!.listar()).find((p) => p.id === 2)!
    await servicios.proyectos!.actualizar(2, { ...segundo, nombre: 'Torre JP' })
    await store.recargar()

    expect(store.proyecto?.nombre).toBe('Torre JP')

    await servicios.proyectos!.actualizar(2, { ...segundo, estado: 'Finalizado' })
    await store.recargar()
    expect(store.proyectoId).toBe(1)
  })
})
