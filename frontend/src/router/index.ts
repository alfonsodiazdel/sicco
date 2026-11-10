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
    /** Vista que trabaja sobre el proyecto elegido en el encabezado (SelectorProyecto). */
    porProyecto?: boolean
  }
}

const implementados = [
  '/',
  '/usuarios',
  '/proyectos',
  '/partidas',
  '/grupos',
  '/niveles',
  '/guias/registrar',
  '/guias/sin-sector',
  '/facturas',
  '/desperdicio',
  '/alertas',
  '/reportes',
  '/reportes/eat',
  '/reportes/corte-mes',
]

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
        meta: { roles: ['oficina_tecnica'], titulo: 'Presupuesto', porProyecto: true },
      },
      {
        path: 'grupos',
        name: 'grupos',
        component: () => import('@/views/GruposView.vue'),
        meta: { roles: ['oficina_tecnica'], titulo: 'Grupos de partidas' },
      },
      {
        path: 'niveles',
        name: 'niveles',
        component: () => import('@/views/NivelesView.vue'),
        meta: { roles: ['ingeniero_produccion'], titulo: 'Niveles y sectores', porProyecto: true },
      },
      {
        path: 'guias/registrar',
        name: 'registrar-guia',
        component: () => import('@/views/guias/RegistrarGuiaView.vue'),
        meta: { roles: ['almacenero'], titulo: 'Registrar guía', porProyecto: true },
      },
      {
        path: 'guias/sin-sector',
        name: 'guias-sin-sector',
        component: () => import('@/views/guias/GuiasSinSectorView.vue'),
        meta: {
          roles: ['ingeniero_produccion', 'oficina_tecnica'],
          titulo: 'Guías sin sector',
          porProyecto: true,
        },
      },
      {
        // El proyecto es el de la guía, no el del selector del encabezado.
        path: 'guias/:id(\\d+)/asignar',
        name: 'asignar-guia',
        component: () => import('@/views/guias/AsignarGuiaView.vue'),
        meta: { roles: ['ingeniero_produccion'], titulo: 'Asignar guía' },
      },
      {
        path: 'facturas',
        name: 'facturas',
        component: () => import('@/views/facturas/FacturasView.vue'),
        meta: { roles: ['oficina_tecnica'], titulo: 'Facturas', porProyecto: true },
      },
      {
        path: 'facturas/nueva',
        name: 'nueva-factura',
        component: () => import('@/views/facturas/NuevaFacturaView.vue'),
        meta: { roles: ['oficina_tecnica'], titulo: 'Nueva factura', porProyecto: true },
      },
      {
        path: 'desperdicio',
        name: 'desperdicio',
        component: () => import('@/views/DesperdicioView.vue'),
        meta: {
          roles: ['ingeniero_produccion', 'gerencia'],
          titulo: 'Control de desperdicio',
          porProyecto: true,
        },
      },
      {
        path: 'reportes',
        name: 'reportes',
        component: () => import('@/views/reportes/ReportesView.vue'),
        meta: { roles: ['oficina_tecnica', 'gerencia'], titulo: 'Reportes' },
      },
      {
        path: 'reportes/eat',
        name: 'reporte-eat',
        component: () => import('@/views/reportes/ReporteEatView.vue'),
        meta: { roles: ['oficina_tecnica', 'gerencia'], titulo: 'Reporte EAT', porProyecto: true },
      },
      {
        path: 'reportes/corte-mes',
        name: 'reporte-corte-mes',
        component: () => import('@/views/reportes/ReporteCorteMesView.vue'),
        meta: { roles: ['oficina_tecnica', 'gerencia'], titulo: 'Corte de mes', porProyecto: true },
      },
      ...modulos,
      {
        path: 'alertas',
        name: 'alertas',
        component: () => import('@/views/AlertasView.vue'),
        meta: { roles: ['ingeniero_produccion', 'gerencia'], titulo: 'Alertas' },
      },
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
