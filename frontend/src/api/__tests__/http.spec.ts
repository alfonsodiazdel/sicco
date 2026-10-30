import { describe, expect, it } from 'vitest'
import { AxiosError, AxiosHeaders } from 'axios'
import { mensajeDeError } from '@/api/http'

function errorHttp(status: number, message: string) {
  return new AxiosError(message, String(status), undefined, undefined, {
    status,
    statusText: '',
    data: { message },
    headers: {},
    config: { headers: new AxiosHeaders() },
  })
}

describe('mensajeDeError', () => {
  it('muestra el mensaje del servidor en los errores del usuario', () => {
    expect(mensajeDeError(errorHttp(403, 'Su cuenta está inactiva.'))).toBe(
      'Su cuenta está inactiva.',
    )
  })

  it('no muestra el detalle interno de un error 500', () => {
    const error = errorHttp(500, 'SQLSTATE[42S02]: Base table or view not found')

    expect(mensajeDeError(error)).toBe('Ocurrió un error inesperado.')
  })

  it('sin respuesta del servidor usa el mensaje por defecto', () => {
    expect(mensajeDeError(new AxiosError('Network Error'))).toBe('Ocurrió un error inesperado.')
  })
})
