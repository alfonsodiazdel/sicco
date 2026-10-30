import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { AxiosError, AxiosHeaders } from 'axios'
import { useAuthStore } from '@/stores/auth'
import { authApi } from '@/api/auth'
import type { Usuario } from '@/types/auth'

vi.mock('@/api/auth', () => ({
  authApi: {
    login: vi.fn<typeof authApi.login>(),
    logout: vi.fn<typeof authApi.logout>(),
    me: vi.fn<typeof authApi.me>(),
    cambiarPassword: vi.fn<typeof authApi.cambiarPassword>(),
  },
}))

const usuario: Usuario = {
  id: 1,
  nombres: 'Ana',
  apellidos: 'Pérez',
  nombre_completo: 'Ana Pérez',
  email: 'ana@sicco.test',
  activo: true,
  debe_cambiar_password: false,
  ultimo_acceso_at: null,
  rol: { id: 4, clave: 'oficina_tecnica', nombre: 'Oficina técnica', descripcion: null },
}

function errorHttp(status: number, data: object = {}) {
  return new AxiosError('Error', String(status), undefined, undefined, {
    status,
    statusText: '',
    data,
    headers: {},
    config: { headers: new AxiosHeaders() },
  })
}

describe('auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('guarda el usuario al iniciar sesión y evalúa su rol', async () => {
    vi.mocked(authApi.login).mockResolvedValue(usuario)
    const auth = useAuthStore()

    await auth.login({ email: usuario.email, password: 'password' })

    expect(auth.autenticado).toBe(true)
    expect(auth.tieneRol('oficina_tecnica', 'gerencia')).toBe(true)
    expect(auth.tieneRol('administrador')).toBe(false)
    expect(auth.puedeVer('gerencia')).toBe(false)
  })

  it('permite al administrador ver todos los módulos sin darle los demás roles', async () => {
    vi.mocked(authApi.login).mockResolvedValue({
      ...usuario,
      rol: { id: 1, clave: 'administrador', nombre: 'Administrador', descripcion: null },
    })
    const auth = useAuthStore()

    await auth.login({ email: usuario.email, password: 'password' })

    expect(auth.puedeVer('gerencia')).toBe(true)
    expect(auth.puedeVer('oficina_tecnica')).toBe(true)
    expect(auth.tieneRol('oficina_tecnica')).toBe(false)
  })

  it('queda sin sesión cuando /auth/me responde 401', async () => {
    vi.mocked(authApi.me).mockRejectedValue(errorHttp(401))
    const auth = useAuthStore()

    await auth.cargarSesion()

    expect(auth.inicializado).toBe(true)
    expect(auth.autenticado).toBe(false)
  })

  it.each([
    ['responde 500', errorHttp(500, { message: 'SQLSTATE[HY000] [2002] Connection refused' })],
    ['no responde', new AxiosError('Network Error')],
  ])('si /auth/me %s, queda sin sesión y con un aviso, sin romper la navegación', async (_, error) => {
    vi.mocked(authApi.me).mockRejectedValue(error)
    const auth = useAuthStore()

    await expect(auth.cargarSesion()).resolves.toBeUndefined()

    expect(auth.inicializado).toBe(true)
    expect(auth.autenticado).toBe(false)
    expect(auth.errorSesion).toBe('Ocurrió un error inesperado.')
  })

  it('limpia la sesión aunque falle el logout en el servidor', async () => {
    vi.mocked(authApi.login).mockResolvedValue(usuario)
    vi.mocked(authApi.logout).mockRejectedValue(new Error('red'))
    const auth = useAuthStore()
    await auth.login({ email: usuario.email, password: 'password' })

    await expect(auth.logout()).rejects.toThrow('red')
    expect(auth.autenticado).toBe(false)
  })
})
