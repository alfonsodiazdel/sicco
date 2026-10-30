import axios, { AxiosError } from 'axios'

export interface ErrorApi {
  message: string
  code?: string
  errors?: Record<string, string[]>
}

export const http = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
})

/** Obtiene la cookie XSRF-TOKEN que Sanctum exige antes de peticiones con estado. */
export function obtenerCsrf() {
  return axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}

export function esErrorApi(error: unknown): error is AxiosError<ErrorApi> {
  return axios.isAxiosError(error)
}

// El mensaje de un 5xx puede traer detalles internos (con APP_DEBUG, la excepción entera).
export function mensajeDeError(error: unknown, porDefecto = 'Ocurrió un error inesperado.') {
  if (!esErrorApi(error)) return porDefecto
  const status = error.response?.status ?? 0
  if (!status || status >= 500) return porDefecto
  return error.response?.data?.message ?? porDefecto
}

/** Errores de validación (422) aplanados para VeeValidate: { campo: 'mensaje' }. */
export function erroresDeValidacion(error: unknown): Record<string, string> {
  if (!esErrorApi(error) || error.response?.status !== 422) return {}
  const errores = error.response.data.errors ?? {}
  return Object.fromEntries(Object.entries(errores).map(([campo, msgs]) => [campo, msgs[0] ?? '']))
}
