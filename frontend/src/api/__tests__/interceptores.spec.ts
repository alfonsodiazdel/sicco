import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { AxiosError, type AxiosAdapter, type AxiosResponse } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { ToastServiceMethods } from 'primevue/toastservice'
import { http, mensajeDeError } from '@/api/http'
import { registrarInterceptores } from '@/api/interceptores'
import { crearAsignacionesApi } from '@/services/asignaciones'
import { crearReportesApi } from '@/services/reportes'
import { useAuthStore } from '@/stores/auth'
import type { Usuario } from '@/types/auth'

const toast = { add: vi.fn<(mensaje: object) => void>() }
const vacia = { template: '<div />' }
const router = createRouter({
  history: createMemoryHistory(),
  routes: [
    { path: '/', name: 'inicio', component: vacia },
    { path: '/login', name: 'login', component: vacia },
    { path: '/cambiar-password', name: 'cambiar-password', component: vacia },
  ],
})

const adapterOriginal = http.defaults.adapter

// La respuesta pasa por toda la cadena de axios, con los interceptores reales.
function responder(status: number, data: unknown) {
  const adapter: AxiosAdapter = (config) =>
    Promise.reject(
      new AxiosError(String(status), AxiosError.ERR_BAD_REQUEST, config, undefined, {
        status,
        statusText: '',
        headers: {},
        config,
        data,
      } as AxiosResponse),
    )
  http.defaults.adapter = adapter
}

const enBlob = (cuerpo: object) => new Blob([JSON.stringify(cuerpo)], { type: 'application/json' })

const inactivo = { message: 'Su cuenta está inactiva.', code: 'usuario_inactivo' }
const cambiarPassword = {
  message: 'Debe cambiar su contraseña temporal.',
  code: 'debe_cambiar_password',
}

const descargas = {
  'la descarga del reporte EAT': () => crearReportesApi().descargarEat(1, '2026-10-31', 'excel'),
  'la exportación de Guías sin sector': () => crearAsignacionesApi().exportarSinSector(1, {}),
}

beforeAll(() => registrarInterceptores(router, toast as unknown as ToastServiceMethods))

beforeEach(async () => {
  setActivePinia(createPinia())
  useAuthStore().usuario = { rol: { clave: 'oficina_tecnica' } } as Usuario
  toast.add.mockClear()
  await router.push('/')
})

afterEach(() => {
  http.defaults.adapter = adapterOriginal
})

describe('interceptores con cuerpos de error en Blob', () => {
  it.each(Object.entries(descargas))(
    'un 403 usuario_inactivo en %s cierra la sesión y lleva al login',
    async (_, descargar) => {
      responder(403, enBlob(inactivo))

      const error = await descargar().catch((e: unknown) => e)

      expect(error).toBeInstanceOf(AxiosError)
      expect(useAuthStore().autenticado).toBe(false)
      expect(toast.add).toHaveBeenCalledWith({
        severity: 'error',
        summary: 'Acceso denegado',
        detail: 'Su cuenta está inactiva.',
        life: 6000,
      })
      expect(router.currentRoute.value.name).toBe('login')
    },
  )

  it.each(Object.entries(descargas))(
    'un 403 debe_cambiar_password en %s lleva a cambiar la contraseña',
    async (_, descargar) => {
      responder(403, enBlob(cambiarPassword))

      await expect(descargar()).rejects.toBeInstanceOf(AxiosError)

      expect(router.currentRoute.value.name).toBe('cambiar-password')
      expect(useAuthStore().autenticado).toBe(true)
    },
  )

  it.each(Object.entries(descargas))(
    'el mensaje de un error JSON en %s llega legible',
    async (_, descargar) => {
      const mensaje = 'No hay información para la fecha seleccionada'
      responder(422, enBlob({ message: mensaje }))

      const error = await descargar().catch((e: unknown) => e)

      expect(mensajeDeError(error)).toBe(mensaje)
      expect(router.currentRoute.value.name).toBe('inicio')
    },
  )

  it('un 403 con cuerpo que no es JSON se rechaza como llegó, sin redirigir', async () => {
    const cuerpo = new Blob(['<html>Forbidden</html>'], { type: 'text/html' })
    responder(403, cuerpo)

    const error = await descargas['la descarga del reporte EAT']().catch((e: unknown) => e)

    expect(error).toBeInstanceOf(AxiosError)
    expect((error as AxiosError).response?.status).toBe(403)
    expect((error as AxiosError).response?.data).toBe(cuerpo)
    expect(router.currentRoute.value.name).toBe('inicio')
    expect(useAuthStore().autenticado).toBe(true)
    expect(toast.add).not.toHaveBeenCalled()
  })
})

describe('interceptores con cuerpos de error en JSON', () => {
  it('un 403 usuario_inactivo cierra la sesión y lleva al login', async () => {
    responder(403, inactivo)

    await expect(crearReportesApi().eat(1, '2026-10-31')).rejects.toBeInstanceOf(AxiosError)

    expect(useAuthStore().autenticado).toBe(false)
    expect(toast.add).toHaveBeenCalledWith(
      expect.objectContaining({ summary: 'Acceso denegado', detail: 'Su cuenta está inactiva.' }),
    )
    expect(router.currentRoute.value.name).toBe('login')
  })

  it('un 403 debe_cambiar_password lleva a cambiar la contraseña', async () => {
    responder(403, cambiarPassword)

    await expect(crearReportesApi().eat(1, '2026-10-31')).rejects.toBeInstanceOf(AxiosError)

    expect(router.currentRoute.value.name).toBe('cambiar-password')
  })
})
