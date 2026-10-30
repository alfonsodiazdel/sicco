import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import router, { destinoTrasLogin } from '@/router'
import { useAuthStore } from '@/stores/auth'
import type { RolClave, Usuario } from '@/types/auth'

function iniciarSesion(rol: RolClave) {
  const auth = useAuthStore()
  auth.usuario = { rol: { clave: rol }, debe_cambiar_password: false } as Usuario
  auth.inicializado = true
}

describe('acceso por URL', () => {
  beforeEach(async () => {
    setActivePinia(createPinia())
  })

  it.each<RolClave>(['administrador', 'almacenero', 'ingeniero_produccion', 'gerencia'])(
    'manda a %s a la página sin acceso al abrir /proyectos',
    async (rol) => {
      iniciarSesion(rol)
      await router.push('/proyectos')

      expect(router.currentRoute.value.name).toBe('no-autorizado')
    },
  )

  it('deja entrar a Oficina técnica a /proyectos', async () => {
    iniciarSesion('oficina_tecnica')
    await router.push('/proyectos')

    expect(router.currentRoute.value.name).toBe('proyectos')
  })
})

describe('destino tras iniciar sesión', () => {
  it.each([
    ['/proyectos?vista=1', '/proyectos?vista=1'],
    ['//evil.example', '/'],
    ['/\\evil.example', '/'],
    ['https://evil.example', '/'],
    ['javascript:alert(1)', '/'],
    [undefined, '/'],
    [['/proyectos'], '/'],
  ])('con redirect %j vuelve a %s', (redirect, destino) => {
    expect(destinoTrasLogin(redirect)).toBe(destino)
  })
})
