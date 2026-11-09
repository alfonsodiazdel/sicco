import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import router, { destinoTrasLogin } from '@/router'
import { menu } from '@/router/menu'
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

  // El router es uno solo para todo el archivo: se parte de Inicio para que cada prueba pase
  // por la guarda y no herede la ruta de la anterior.
  async function abrir(ruta: string) {
    await router.push('/')
    await router.push(ruta)
    return router.currentRoute.value.name
  }

  it.each<RolClave>(['almacenero', 'ingeniero_produccion', 'gerencia'])(
    'manda a %s a la página sin acceso al abrir /proyectos',
    async (rol) => {
      iniciarSesion(rol)

      expect(await abrir('/proyectos')).toBe('no-autorizado')
    },
  )

  it('deja entrar a Oficina técnica a /proyectos', async () => {
    iniciarSesion('oficina_tecnica')

    expect(await abrir('/proyectos')).toBe('proyectos')
  })

  it.each(['/proyectos', '/partidas', '/niveles'])(
    'deja entrar al administrador a %s en modo consulta',
    async (ruta) => {
      iniciarSesion('administrador')

      expect(await abrir(ruta)).toBe(ruta.slice(1))
    },
  )
})

describe('reportes (CU15): los roles de la API', () => {
  beforeEach(() => setActivePinia(createPinia()))

  async function abrir(ruta: string) {
    await router.push('/')
    await router.push(ruta)
    return router.currentRoute.value.name
  }

  // El menú se filtra igual que en AppLayout.
  const veReportes = () =>
    menu
      .filter((item) => !item.roles || useAuthStore().puedeVer(...item.roles))
      .some((item) => item.ruta === '/reportes')

  it.each<RolClave>(['almacenero', 'ingeniero_produccion'])(
    '%s no ve «Reportes» en el menú ni entra por la URL',
    async (rol) => {
      iniciarSesion(rol)

      expect(veReportes()).toBe(false)
      expect(await abrir('/reportes')).toBe('no-autorizado')
      expect(await abrir('/reportes/eat')).toBe('no-autorizado')
    },
  )

  it.each<RolClave>(['oficina_tecnica', 'gerencia', 'administrador'])(
    '%s ve «Reportes» en el menú y entra al reporte EAT',
    async (rol) => {
      iniciarSesion(rol)

      expect(veReportes()).toBe(true)
      expect(await abrir('/reportes')).toBe('reportes')
      expect(await abrir('/reportes/eat')).toBe('reporte-eat')
    },
  )
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
