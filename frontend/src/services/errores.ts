import { AxiosError, type AxiosResponse } from 'axios'
import type { ErrorApi } from '@/api/http'

// Misma forma que un 422 de la API, para que la vista lo trate con erroresDeValidacion.
export function errorDeValidacion(errors: Record<string, string[]>) {
  const data: ErrorApi = { message: Object.values(errors)[0]?.[0] ?? '', errors }
  return new AxiosError(data.message, AxiosError.ERR_BAD_REQUEST, undefined, undefined, {
    status: 422,
    data,
  } as AxiosResponse<ErrorApi>)
}
