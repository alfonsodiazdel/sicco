import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import type { RolClave } from '@/types/auth'
import AppLayout from '@/layouts/AppLayout.vue'
import { menu } from './menu'

declare module 'vue-router' {
  interface RouteMeta {
    /** Ruta accesible sin sesión (login). */
    publica?: boolean
    /** Roles permitidos (el administrador entra a todo); si se omite, cualquier usuario autenticado. */
    roles?: RolClave[]
    titulo?: string
  }
}

const implementados = ['/', '/usuarios', '/proyectos', '/partidas', '/niveles']

// Vistas de los módulos ya implementados, por ruta del menú.
const vistas: Record<string, RouteRecordRaw['component']> = {
  '/proveedores': () => import('@/views/proveedores/ProveedoresView.vue'),
}

// Los módulos aún no implementados comparten una vista provisional y ya respetan los roles.
const modulos: RouteRecordRaw[] = menu
  .filter((item) => !implementados.includes(item.ruta))
  .map((item) => ({
    path: item.ruta.slice(1),
    component: vistas[item.ruta] ?? (() => import('@/views/EnConstruccionView.vue')),
    meta: { roles: item.roles, titulo: item.etiqueta },
  }))

const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/auth/LoginView.vue'),
    meta: { publica: true, titulo: 'Iniciar sesión' },
  },
  {
    path: '/cambiar-password',
    name: 'cambiar-password',
    component: () => import('@/views/auth/CambiarPasswordView.vue'),
    meta: { titulo: 'Cambiar contraseña' },
  },
  {
    path: '/',
    component: AppLayout,
    children: [
      {
        path: '',
        name: 'inicio',
        component: () => import('@/views/InicioView.vue'),
        meta: { titulo: 'Inicio' },
      },
      {
        path: 'usuarios',
        name: 'usuarios',
        component: () => import('@/views/UsuariosView.vue'),
        meta: { roles: ['administrador'], titulo: 'Usuarios' },
      },
      {
        path: 'proyectos',
        name: 'proyectos',
        component: () => import('@/views/ProyectosView.vue'),
        meta: { roles: ['oficina_tecnica'], titulo: 'Proyectos' },
      },
      {
        path: 'partidas',
        name: 'partidas',
        component: () => import('@/views/PartidasView.vue'),
        meta: { roles: ['oficina_tecnica'], titulo: 'Presupuesto' },
      },
      {
        path: 'niveles',
        name: 'niveles',
        component: () => import('@/views/NivelesView.vue'),
        meta: { roles: ['ingeniero_produccion'], titulo: 'Niveles y sectores' },
      },
      ...modulos,
      {
        path: 'no-autorizado',
        name: 'no-autorizado',
        component: () => import('@/views/errores/NoAutorizadoView.vue'),
        meta: { titulo: 'Sin acceso' },
      },
      {
        path: ':pathMatch(.*)*',
        name: 'no-encontrado',
        component: () => import('@/views/errores/NoEncontradoView.vue'),
        meta: { titulo: 'Página no encontrada' },
      },
    ],
  },
]

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!auth.inicializado) await auth.cargarSesion()

  if (to.meta.publica) {
    return auth.autenticado ? { name: 'inicio' } : true
  }

  if (!auth.autenticado) {
    return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} }
  }

  if (auth.usuario?.debe_cambiar_password && to.name !== 'cambiar-password') {
    return { name: 'cambiar-password' }
  }

  if (to.meta.roles && !auth.puedeVer(...to.meta.roles)) {
    return { name: 'no-autorizado' }
  }

  return true
})

router.afterEach((to) => {
  document.title = to.meta.titulo ? `${to.meta.titulo} · SICCO` : 'SICCO'
})

// Solo rutas del propio sitio: «//host» y «/\\host» los navegadores los leen como otro origen.
export function destinoTrasLogin(redirect: unknown) {
  return typeof redirect === 'string' && /^\/(?![/\\])/.test(redirect) ? redirect : '/'
}

export default router
