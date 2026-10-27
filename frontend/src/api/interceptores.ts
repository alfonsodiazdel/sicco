import type { Router } from 'vue-router'
import type { ToastServiceMethods } from 'primevue/toastservice'
import { http, obtenerCsrf } from './http'
import { useAuthStore } from '@/stores/auth'

/**
 * Manejo global de respuestas de la API:
 * - 401: la sesión expiró (30 min de inactividad, CU01) → volver al login.
 * - 403 debe_cambiar_password: forzar el cambio de contraseña temporal.
 * - 403 usuario_inactivo: el administrador desactivó al usuario.
 * - 419: token CSRF vencido → se renueva y se reintenta una vez.
 */
export function registrarInterceptores(router: Router, toast: ToastServiceMethods) {
  http.interceptors.response.use(undefined, async (error) => {
    const status = error.response?.status
    const code = error.response?.data?.code
    const auth = useAuthStore()

    if (status === 419 && !error.config._reintento) {
      error.config._reintento = true
      await obtenerCsrf()
      return http.request(error.config)
    }

    const enLogin = router.currentRoute.value.name === 'login'

    if (status === 401 && !enLogin && auth.autenticado) {
      auth.limpiar()
      toast.add({
        severity: 'warn',
        summary: 'Sesión expirada',
        detail: 'Vuelva a iniciar sesión.',
        life: 5000,
      })
      await router.push({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } })
    }

    if (status === 403 && code === 'usuario_inactivo' && !enLogin) {
      auth.limpiar()
      toast.add({
        severity: 'error',
        summary: 'Acceso denegado',
        detail: error.response.data.message,
        life: 6000,
      })
      await router.push({ name: 'login' })
    }

    if (status === 403 && code === 'debe_cambiar_password') {
      await router.push({ name: 'cambiar-password' })
    }

    return Promise.reject(error)
  })
}
