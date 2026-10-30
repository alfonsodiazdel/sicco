import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { authApi } from '@/api/auth'
import { esErrorApi, mensajeDeError } from '@/api/http'
import type { CambioPassword, Credenciales, RolClave, Usuario } from '@/types/auth'

export const useAuthStore = defineStore('auth', () => {
  const usuario = ref<Usuario | null>(null)
  /** true cuando ya se consultó /auth/me al menos una vez (haya o no sesión). */
  const inicializado = ref(false)
  /** Por qué no se pudo saber si hay sesión (servidor caído o sin red); lo muestra el login. */
  const errorSesion = ref<string | null>(null)

  const autenticado = computed(() => usuario.value !== null)
  const rol = computed(() => usuario.value?.rol.clave ?? null)

  function tieneRol(...roles: RolClave[]) {
    return rol.value !== null && roles.includes(rol.value)
  }

  async function cargarSesion() {
    try {
      usuario.value = await authApi.me()
    } catch (error) {
      // Si se relanza, el guard del router falla y la pantalla queda en blanco: se va al login.
      if (!esErrorApi(error) || ![401, 403].includes(error.response?.status ?? 0)) {
        errorSesion.value = mensajeDeError(error)
      }
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
    errorSesion,
    autenticado,
    rol,
    tieneRol,
    cargarSesion,
    login,
    logout,
    cambiarPassword,
    limpiar,
  }
})
