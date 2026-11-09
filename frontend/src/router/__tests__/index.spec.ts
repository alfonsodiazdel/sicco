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

describe('exportar documentos', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  async function abrir(ruta: string) {
    await router.push('/')
    await router.push(ruta)
    return router.currentRoute.value
  }

  it.each<RolClave>(['oficina_tecnica', 'administrador'])('deja entrar a %s', async (rol) => {
    iniciarSesion(rol)

    const ruta = await abrir('/documentos')
    expect(ruta.name).toBe('exportar-documentos')
    expect(ruta.meta).toMatchObject({ titulo: 'Exportar documentos', porProyecto: true })
  })

  it.each<RolClave>(['almacenero', 'ingeniero_produccion', 'gerencia'])(
    'manda a %s a la página sin acceso',
    async (rol) => {
      iniciarSesion(rol)

      expect((await abrir('/documentos')).name).toBe('no-autorizado')
    },
  )

  it.each<[RolClave, boolean]>([
    ['oficina_tecnica', true],
    ['administrador', true],
    ['almacenero', false],
    ['ingeniero_produccion', false],
    ['gerencia', false],
  ])('en el menú, %s ve la opción: %s', (rol, ve) => {
    iniciarSesion(rol)
    const auth = useAuthStore()
    const item = menu.find((m) => m.ruta === '/documentos')!

    expect(item.etiqueta).toBe('Exportar documentos')
    expect(!item.roles || auth.puedeVer(...item.roles)).toBe(ve)
  })
})
