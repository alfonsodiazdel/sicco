import type { CambioPassword, Credenciales, Usuario } from '@/types/auth'
import { http, obtenerCsrf } from './http'

export const authApi = {
  async login(credenciales: Credenciales) {
    await obtenerCsrf()
    const { data } = await http.post<Usuario>('/auth/login', credenciales)
    return data
  },

  async logout() {
    await http.post('/auth/logout')
  },

  async me() {
    const { data } = await http.get<Usuario>('/auth/me')
    return data
  },

  async cambiarPassword(payload: CambioPassword) {
    await http.put('/auth/password', payload)
  },
}
