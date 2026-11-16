import type { RolClave } from '@/types/auth'

export interface ItemMenu {
  etiqueta: string
  icono: string
  ruta: string
  /** Roles que ven la opción (el administrador ve todas); si se omite, la ven todos. */
  roles?: RolClave[]
}

/**
 * Opciones del menú por rol (CU01: «el sistema muestra solo las opciones de su rol»).
 * Los roles se toman de los actores de cada caso de uso del documento SICCO.
 * El administrador ve todas las opciones en modo consulta (auth.puedeVer).
 */
export const menu: ItemMenu[] = [
  { etiqueta: 'Inicio', icono: 'pi pi-home', ruta: '/' },
  { etiqueta: 'Dashboard', icono: 'pi pi-chart-bar', ruta: '/dashboard', roles: ['gerencia'] },
  {
    etiqueta: 'Proyectos',
    icono: 'pi pi-building',
    ruta: '/proyectos',
    roles: ['oficina_tecnica'],
  },
  {
    etiqueta: 'Presupuesto',
    icono: 'pi pi-calculator',
    ruta: '/partidas',
    roles: ['oficina_tecnica'],
  },
  {
    etiqueta: 'Catálogos',
    icono: 'pi pi-folder',
    ruta: '/catalogos',
    roles: ['oficina_tecnica'],
  },
  {
    etiqueta: 'Niveles y sectores',
    icono: 'pi pi-sitemap',
    ruta: '/niveles',
    roles: ['ingeniero_produccion'],
  },
  {
    etiqueta: 'Registrar guía',
    icono: 'pi pi-camera',
    ruta: '/guias/registrar',
    roles: ['almacenero'],
  },
  {
    etiqueta: 'Guías sin sector',
    icono: 'pi pi-inbox',
    ruta: '/guias/sin-sector',
    roles: ['almacenero', 'ingeniero_produccion', 'oficina_tecnica'],
  },
  {
    etiqueta: 'Proveedores',
    icono: 'pi pi-truck',
    ruta: '/proveedores',
    roles: ['almacenero', 'ingeniero_produccion', 'oficina_tecnica', 'gerencia'],
  },
  { etiqueta: 'Facturas', icono: 'pi pi-file', ruta: '/facturas', roles: ['oficina_tecnica'] },
  {
    etiqueta: 'Exportar documentos',
    icono: 'pi pi-download',
    ruta: '/documentos',
    roles: ['oficina_tecnica'],
  },
  {
    etiqueta: 'Control de desperdicio',
    icono: 'pi pi-percentage',
    ruta: '/desperdicio',
    roles: ['ingeniero_produccion', 'gerencia'],
  },
  {
    etiqueta: 'Alertas',
    icono: 'pi pi-bell',
    ruta: '/alertas',
    roles: ['ingeniero_produccion', 'gerencia'],
  },
  {
    etiqueta: 'Reportes',
    icono: 'pi pi-print',
    ruta: '/reportes',
    roles: ['oficina_tecnica', 'gerencia'],
  },
  { etiqueta: 'Usuarios', icono: 'pi pi-users', ruta: '/usuarios', roles: ['administrador'] },
]
