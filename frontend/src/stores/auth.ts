import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { authApi } from '@/api/auth'
import { esErrorApi } from '@/api/http'
import type { CambioPassword, Credenciales, RolClave, Usuario } from '@/types/auth'

export const useAuthStore = defineStore('auth', () => {
  const usuario = ref<Usuario | null>(null)
  /** true cuando ya se consultó /auth/me al menos una vez (haya o no sesión). */
  const inicializado = ref(false)

  const autenticado = computed(() => usuario.value !== null)
  const rol = computed(() => usuario.value?.rol.clave ?? null)

  function tieneRol(...roles: RolClave[]) {
    return rol.value !== null && roles.includes(rol.value)
  }

  /** Acceso de consulta a un módulo: el administrador ve todo; los demás, solo los de su rol. */
  function puedeVer(...roles: RolClave[]) {
    return tieneRol('administrador', ...roles)
  }

  async function cargarSesion() {
    try {
      usuario.value = await authApi.me()
    } catch (error) {
      if (!esErrorApi(error) || ![401, 403].includes(error.response?.status ?? 0)) throw error
      usuario.value = null
    } finally {
      inicializado.value = true
    }
  }

  async function login(credenciales: Credenciales) {
    usuario.value = await authApi.login(credenciales)
    inicializado.value = true
  }

  async function logout() {
    try {
      await authApi.logout()
    } finally {
      limpiar()
    }
  }

  async function cambiarPassword(payload: CambioPassword) {
    await authApi.cambiarPassword(payload)
    if (usuario.value) usuario.value.debe_cambiar_password = false
  }

  function limpiar() {
    usuario.value = null
  }

  return {
    usuario,
    inicializado,
    autenticado,
    rol,
    tieneRol,
    puedeVer,
    cargarSesion,
    login,
    logout,
    cambiarPassword,
    limpiar,
  }
})
