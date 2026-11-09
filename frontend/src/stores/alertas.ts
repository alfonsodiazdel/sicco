import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { alertasServicio } from '@/services/alertas'
import { useAuthStore } from '@/stores/auth'
import type { RolClave } from '@/types/auth'

/** Roles que reciben las alertas de consumo (CU14); el administrador solo las consulta. */
export const ROLES_ALERTAS: RolClave[] = ['ingeniero_produccion', 'gerencia']

/**
 * Contador del ícono de notificaciones (CU14 paso 4). Lo refrescan el encabezado cada cierto
 * tiempo y las vistas que pueden cambiarlo (asignar una guía, revisar una alerta).
 */
export const useAlertasStore = defineStore('alertas', () => {
  const auth = useAuthStore()
  const pendientes = ref(0)

  const recibe = computed(() => auth.puedeVer(...ROLES_ALERTAS))

  let ultima = 0
  async function actualizar() {
    if (!recibe.value) return
    const numero = ++ultima
    try {
      const total = await alertasServicio.pendientes()
      if (numero === ultima) pendientes.value = total
    } catch {
      // El contador es un aviso: si falla, se conserva el último valor y se reintenta luego.
    }
  }

  return { pendientes, recibe, actualizar }
})
